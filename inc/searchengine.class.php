<?php

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

class PluginGlobalsearchSearchEngine
{
    /** @var string */
    private $raw_query;
    /** @var bool */
    private $id_only = false;

    /**
     * @param string $raw_query
     */
    public function __construct($raw_query)
    {
        $raw = trim($raw_query);

        // Soporte para prefijo "#123" => modo solo ID
        if ($raw !== '' && mb_substr($raw, 0, 1) === '#') {
            $id = trim(mb_substr($raw, 1));
            if (is_numeric($id)) {
                $this->id_only = true;
                $this->raw_query = $id;
                return;
            }
            // Si después de # no hay número, seguimos usando la query completa
        }

        $this->raw_query = $raw;
    }

    /**
     * Obtiene las restricciones de entidades usando métodos estándar de GLPI.
     * Considera entidades recursivas (is_recursive = 1).
     * Si un item está en una entidad padre con is_recursive=1, será visible desde entidades hijas.
     *
     * @param string $itemtype Tipo de item (Ticket, Project, etc.)
     * @param string $table_alias Alias de la tabla en la consulta (ej: 'glpi_tickets')
     * @return array Criterio WHERE para restricciones de entidades
     */
    private function getEntityRestrictCriteria($itemtype, $table_alias = null)
    {
        $table = $itemtype::getTable();
        $field = 'entities_id';

        // Obtener todas las entidades activas del usuario
        $active_entities = [];
        if (isset($_SESSION['glpiactiveentities']) && is_array($_SESSION['glpiactiveentities'])) {
            $active_entities = $_SESSION['glpiactiveentities'];
        }

        if (empty($active_entities)) {
            return [];
        }

        // Obtener información de recursividad del usuario
        $recursive_entities = [];
        if (isset($_SESSION['glpiactiveentities_recursive']) && is_array($_SESSION['glpiactiveentities_recursive'])) {
            $recursive_entities = $_SESSION['glpiactiveentities_recursive'];
        }

        // Construir lista completa de entidades accesibles
        // En GLPI, si un item está en una entidad padre con is_recursive=1,
        // ese item es visible desde cualquier entidad hija.
        // Por lo tanto, necesitamos incluir:
        // 1. Todas las entidades activas del usuario
        // 2. Todas las entidades hijas de las entidades activas con is_recursive=1
        // 3. Todas las entidades padre de las entidades activas (para ver items de padres con is_recursive)
        $all_entities = [];

        foreach ($active_entities as $entity_id) {
            // Agregar la entidad activa
            $all_entities[$entity_id] = $entity_id;

            // Si la entidad tiene is_recursive=1, obtener todas sus entidades hijas
            if (isset($recursive_entities[$entity_id]) && $recursive_entities[$entity_id] == 1) {
                // Obtener todas las entidades hijas recursivamente
                $sons = Entity::getSonsOf($entity_id);
                foreach ($sons as $son_id) {
                    $all_entities[$son_id] = $son_id;
                }
            }

            // Incluir todos los ancestros de esta entidad activa
            // Esto permite ver items que están en entidades padre con is_recursive=1
            // Necesitamos crear una instancia de Entity para usar getAncestors()
            $entity = new Entity();
            if ($entity->getFromDB($entity_id)) {
                $ancestors = $entity->getAncestors();
                if (is_array($ancestors)) {
                    foreach ($ancestors as $ancestor_id) {
                        $all_entities[$ancestor_id] = $ancestor_id;
                    }
                }
            }
        }

        if (empty($all_entities)) {
            return [];
        }

        $field_name = ($table_alias !== null) ? $table_alias . '.' . $field : $table . '.' . $field;

        return [
            $field_name => array_values($all_entities)
        ];
    }


    /**
     * Obtiene el nombre del técnico asignado a un ticket
     * Los técnicos se guardan en glpi_tickets_users con type = 2 (assigned)
     *
     * @param int $ticket_id ID del ticket
     * @return string Nombre del técnico o "Sin asignar"
     */
    private function getTechnicianName($ticket_id)
    {
        global $DB;

        // Buscar técnico asignado (type = 2 significa "assigned")
        $criteria = [
            'SELECT' => [
                'glpi_users.firstname',
                'glpi_users.realname'
            ],
            'FROM' => 'glpi_tickets_users',
            'INNER JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        'glpi_tickets_users' => 'users_id',
                        'glpi_users' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                'glpi_tickets_users.tickets_id' => $ticket_id,
                'glpi_tickets_users.type' => 2  // 2 = Assigned technician
            ],
            'LIMIT' => 1
        ];

        $iterator = $DB->request($criteria);

        if (count($iterator)) {
            $row = $iterator->current();
            $firstname = $row['firstname'] ?? '';
            $realname = $row['realname'] ?? '';
            $fullname = trim($firstname . ' ' . $realname);
            return $fullname ?: __('Unknown');
        }

        return __('Not assigned');
    }

    /**
     * Genera el criterio de búsqueda "estilo Google".
     * Divide la query en palabras y requiere que TODAS las palabras aparezcan
     * en al menos uno de los campos proporcionados.
     *
     * @param array $fields Array de nombres de campos (ej: ['name', 'content'])
     * @return array Array compatible con DBmysqlIterator WHERE
     */
    private function getMultiWordCriteria(array $fields)
    {
        // Regex para encontrar "frases literales" o palabras sueltas.
        // Captura el contenido entre comillas en el primer grupo o palabras sin comillas en el segundo.
        preg_match_all('/"([^"]+)"|(\S+)/', $this->raw_query, $matches);

        $terms = [];
        foreach ($matches[1] as $key => $phrase) {
            if ($phrase !== '') {
                // Es una frase entre comillas
                $terms[] = $phrase;
            } else {
                // Es una palabra suelta (o una comilla mal cerrada)
                $term = $matches[2][$key];
                if ($term !== '') {
                    // Si el término es solo una comilla ("), lo ignoramos para evitar LIKE '%%%'
                    if ($term !== '"') {
                        $terms[] = $term;
                    }
                }
            }
        }

        if (empty($terms)) {
            return [];
        }

        $and_criteria = [];

        foreach ($terms as $term) {
            // Cada término (palabra o frase) debe encontrarse en ALGUNO de los campos (OR)
            $or_criteria = [];
            foreach ($fields as $field) {
                $or_criteria[$field] = ['LIKE', '%' . $term . '%'];
            }
            // Agregamos este bloque OR al bloque principal AND
            $and_criteria[] = ['OR' => $or_criteria];
        }

        return ['AND' => $and_criteria];
    }

    /**
     * Obtiene los criterios de restricción de permisos para tickets.
     * Considera si el usuario puede ver tickets no asignados, solo asignados, etc.
     *
     * @return array Criterio WHERE para restricciones de permisos
     */
    private function getTicketPermissionCriteria()
    {
        $user_id = Session::getLoginUserID();
        $criteria = [];

        // Verificar si el usuario puede ver todos los tickets o solo los asignados
        // En GLPI, esto se controla a través de los perfiles y derechos
        // Si el usuario no tiene permiso para ver tickets no asignados, 
        // debemos filtrar solo los tickets asignados a él o a sus grupos

        // Verificar si el usuario tiene el derecho de ver todos los tickets
        // Esto se hace verificando el perfil del usuario
        $can_see_all = false;
        if (isset($_SESSION['glpiactiveprofile']['ticket'])) {
            // Si tiene derecho de ver todos los tickets (típicamente ticket = ALL)
            // En GLPI, los derechos se almacenan en $_SESSION['glpiactiveprofile']
            // Para simplificar, usamos el método can() después, pero intentamos
            // aplicar algunas restricciones básicas en SQL cuando sea posible
        }

        // Si no puede ver todos los tickets, aplicar restricción de tickets asignados
        // Nota: Esta es una aproximación. La verificación completa se hace con can()
        // pero podemos optimizar filtrando en SQL cuando sea posible
        if (!$can_see_all) {
            // No aplicamos restricción aquí porque es complejo determinar
            // todos los casos (grupos, observadores, etc.)
            // Se deja que can() lo maneje después
        }

        return $criteria;
    }

    /**
     * Ejecuta todas las búsquedas soportadas.
     *
     * @return array
     */
    public function searchAll()
    {
        $results = [];

        // Verificar configuración para cada tipo de búsqueda
        if (PluginGlobalsearchConfig::isEnabled('Ticket')) {
            $results['Ticket'] = $this->searchTickets();
        }

        if (PluginGlobalsearchConfig::isEnabled('Project')) {
            $results['Project'] = $this->searchProjects();
        }

        if (PluginGlobalsearchConfig::isEnabled('Document')) {
            $results['Document'] = $this->searchDocuments();
        }

        if (PluginGlobalsearchConfig::isEnabled('Software')) {
            $results['Software'] = $this->searchSoftware();
        }

        if (PluginGlobalsearchConfig::isEnabled('User')) {
            $results['User'] = $this->searchUsers();
        }

        if (PluginGlobalsearchConfig::isEnabled('TicketTask')) {
            $results['TicketTask'] = $this->searchTicketTasks();
        }

        if (PluginGlobalsearchConfig::isEnabled('ProjectTask')) {
            $results['ProjectTask'] = $this->searchProjectTasks();
        }

        return $results;
    }

    /**
     * Búsqueda en tickets (incluyendo cerrados/resueltos)
     * Devuelve todos los resultados sin límite, usando estrategia Bulk Load para técnicos
     * Aplica restricciones de permisos según los derechos del usuario.
     *
     * @return array
     */
    public function searchTickets()
    {
        global $DB;

        if (!Ticket::canView()) {
            return [];
        }

        // Obtener restricciones de entidades usando métodos estándar de GLPI
        $entity_criteria = $this->getEntityRestrictCriteria('Ticket', 'glpi_tickets');

        // Construir criterios WHERE comunes
        $where = [];
        $search_fields = ['glpi_tickets.name', 'glpi_tickets.content'];

        if (is_numeric($this->raw_query)) {
            $id_criteria = ['glpi_tickets.id' => $this->raw_query];

            if ($this->id_only) {
                // Modo solo ID: no buscar en contenido
                $where = $id_criteria;
            } else {
                // Combinar búsqueda por contenido y por ID (OR), similar a TicketTasks
                $content_criteria = $this->getMultiWordCriteria($search_fields);

                if (!empty($content_criteria)) {
                    $where = [
                        'OR' => [
                            $content_criteria,
                            $id_criteria
                        ]
                    ];
                } else {
                    $where = $id_criteria;
                }
            }
        } elseif (mb_strlen($this->raw_query) >= 3) {
            $where = $this->getMultiWordCriteria($search_fields);
        } else {
            return [];
        }

        // Aplicar restricciones de permisos para tickets
        $permission_criteria = $this->getTicketPermissionCriteria();

        $common_where = array_merge($where, $entity_criteria, $permission_criteria, ['glpi_tickets.is_deleted' => 0]);

        // 1. OBTENER TODOS LOS TICKETS (SIN LIMIT, SIN JOIN de técnicos para evitar duplicados)
        $iterator = $DB->request([
            'SELECT' => [
                'glpi_tickets.id',
                'glpi_tickets.name',
                'glpi_tickets.status',
                'glpi_tickets.entities_id',
                'glpi_tickets.date',
                'glpi_tickets.closedate',
                'glpi_tickets.date_mod'
            ],
            'FROM' => 'glpi_tickets',
            'WHERE' => $common_where,
            'ORDER' => 'glpi_tickets.date_mod DESC'
        ]);

        $tickets = [];
        $ticket_ids = [];
        $ticket_obj = new Ticket();

        foreach ($iterator as $row) {
            // Verificar permisos antes de agregar
            if ($ticket_obj->can($row['id'], READ)) {
                $row['status_name'] = Ticket::getStatus($row['status']);
                $row['tech_name'] = __('Not assigned'); // Valor inicial
                $row['requester_name'] = __('Not assigned'); // Valor inicial
                $tickets[$row['id']] = $row;
                $ticket_ids[] = $row['id'];
            }
        }

        // 2. CARGA MASIVA DE TÉCNICOS (BULK LOAD)
        if (!empty($ticket_ids)) {
            $tech_iter = $DB->request([
                'SELECT' => [
                    'glpi_tickets_users.tickets_id',
                    'glpi_users.firstname',
                    'glpi_users.realname',
                    'glpi_users.name AS uname'
                ],
                'FROM' => 'glpi_tickets_users',
                'INNER JOIN' => [
                    'glpi_users' => [
                        'ON' => ['glpi_tickets_users' => 'users_id', 'glpi_users' => 'id']
                    ]
                ],
                'WHERE' => [
                    'glpi_tickets_users.tickets_id' => $ticket_ids,
                    'glpi_tickets_users.type' => 2 // Assigned
                ]
            ]);

            $techs_by_ticket = [];
            foreach ($tech_iter as $tech_row) {
                $tid = $tech_row['tickets_id'];
                $fullname = trim($tech_row['firstname'] . ' ' . $tech_row['realname']);
                if (empty($fullname)) {
                    $fullname = $tech_row['uname'];
                }

                if (isset($techs_by_ticket[$tid])) {
                    $techs_by_ticket[$tid][] = $fullname;
                } else {
                    $techs_by_ticket[$tid] = [$fullname];
                }
            }

            // Asignar nombres concatenados
            foreach ($techs_by_ticket as $tid => $names) {
                if (isset($tickets[$tid])) {
                    $tickets[$tid]['tech_name'] = implode(', ', $names);
                }
            }
        }

        // 3. CARGA MASIVA DE SOLICITANTES (BULK LOAD)
        if (!empty($ticket_ids)) {
            $requester_iter = $DB->request([
                'SELECT' => [
                    'glpi_tickets_users.tickets_id',
                    'glpi_users.firstname',
                    'glpi_users.realname',
                    'glpi_users.name AS uname'
                ],
                'FROM' => 'glpi_tickets_users',
                'INNER JOIN' => [
                    'glpi_users' => [
                        'ON' => ['glpi_tickets_users' => 'users_id', 'glpi_users' => 'id']
                    ]
                ],
                'WHERE' => [
                    'glpi_tickets_users.tickets_id' => $ticket_ids,
                    'glpi_tickets_users.type' => 1 // Requester
                ]
            ]);

            $requesters_by_ticket = [];
            foreach ($requester_iter as $req_row) {
                $tid = $req_row['tickets_id'];
                $fullname = trim($req_row['firstname'] . ' ' . $req_row['realname']);
                if (empty($fullname)) {
                    $fullname = $req_row['uname'];
                }
                if (isset($requesters_by_ticket[$tid])) {
                    $requesters_by_ticket[$tid][] = $fullname;
                } else {
                    $requesters_by_ticket[$tid] = [$fullname];
                }
            }

            // Asignar nombres concatenados
            foreach ($requesters_by_ticket as $tid => $names) {
                if (isset($tickets[$tid])) {
                    $tickets[$tid]['requester_name'] = implode(', ', $names);
                }
            }
        }

        return array_values($tickets);
    }

    /**
     * Búsqueda en proyectos
     */
    public function searchProjects()
    {
        global $DB;

        if (!Project::canView()) {
            return [];
        }

        // Obtener restricciones de entidades usando métodos estándar de GLPI
        $entity_criteria = $this->getEntityRestrictCriteria('Project', 'glpi_projects');

        $search_fields = ['glpi_projects.name', 'glpi_projects.comment', 'glpi_projects.content'];

        if (is_numeric($this->raw_query)) {
            // Criterio por ID
            $id_criteria = ['glpi_projects.id' => $this->raw_query];

            if ($this->id_only) {
                // Modo solo ID
                $where = $id_criteria;
            } else {
                // Criterio por contenido
                $content_criteria = $this->getMultiWordCriteria($search_fields);

                // Combinar ambos con OR
                if (!empty($content_criteria)) {
                    $where = [
                        'OR' => [
                            $content_criteria,
                            $id_criteria
                        ]
                    ];
                } else {
                    $where = $id_criteria;
                }
            }
        } else {
            if (mb_strlen($this->raw_query) < 3) {
                return [];
            }

            $where = $this->getMultiWordCriteria($search_fields);
        }

        $criteria = [
            'SELECT' => [
                'glpi_projects.id',
                'glpi_projects.name',
                'glpi_projects.projectstates_id',
                'glpi_projects.entities_id',
                'glpi_projects.plan_start_date',
                'glpi_projects.plan_end_date',
                'glpi_projects.date_mod',
                'glpi_projects.date',
                'glpi_users.firstname AS requester_firstname',
                'glpi_users.realname AS requester_realname',
                'glpi_users.name AS requester_uname'
            ],
            'FROM' => 'glpi_projects',
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => ['glpi_projects' => 'users_id', 'glpi_users' => 'id']
                ]
            ],
            'WHERE' => array_merge(
                $where,
                $entity_criteria
            ),
            'ORDER' => 'glpi_projects.date_mod DESC'
        ];

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            // Verificar permisos adicionales
            $project = new Project();
            if ($project->can($row['id'], READ)) {
                // Construir nombre del solicitante
                $fullname = trim(($row['requester_firstname'] ?? '') . ' ' . ($row['requester_realname'] ?? ''));
                $row['requester_name'] = $fullname ?: ($row['requester_uname'] ?? __('Unknown'));
                $results[] = $row;
            }
        }
        return $results;
    }

    /**
     * Búsqueda en documentos
     */
    public function searchDocuments()
    {
        global $DB;

        if (!Document::canView()) {
            return [];
        }

        // Obtener restricciones de entidades usando métodos estándar de GLPI
        $entity_criteria = $this->getEntityRestrictCriteria('Document', 'glpi_documents');

        $search_fields = ['glpi_documents.name', 'glpi_documents.filename', 'glpi_documents.comment'];

        if (is_numeric($this->raw_query)) {
            // Criterio por ID
            $id_criteria = ['glpi_documents.id' => $this->raw_query];

            if ($this->id_only) {
                // Modo solo ID
                $where = $id_criteria;
            } else {
                // Criterio por contenido
                $content_criteria = $this->getMultiWordCriteria($search_fields);

                // Combinar ambos con OR
                if (!empty($content_criteria)) {
                    $where = [
                        'OR' => [
                            $content_criteria,
                            $id_criteria
                        ]
                    ];
                } else {
                    $where = $id_criteria;
                }
            }
        } else {
            if (mb_strlen($this->raw_query) < 3) {
                return [];
            }

            $where = $this->getMultiWordCriteria($search_fields);
        }

        $criteria = [
            'SELECT' => [
                'glpi_documents.id',
                'glpi_documents.name',
                'glpi_documents.filename',
                'glpi_documents.entities_id',
                'glpi_documents.date_mod',
                'glpi_documents.documentcategories_id'
            ],
            'FROM' => 'glpi_documents',
            'WHERE' => array_merge(
                $where,
                [
                    'glpi_documents.is_deleted' => 0
                ],
                $entity_criteria
            ),
            'ORDER' => 'glpi_documents.date_mod DESC'
        ];

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            // Verificar permisos adicionales
            $document = new Document();
            if ($document->can($row['id'], READ)) {
                $results[] = $row;
            }
        }
        return $results;
    }

    /**
     * Búsqueda en software
     */
    public function searchSoftware()
    {
        global $DB;

        if (!Software::canView()) {
            return [];
        }

        // Obtener restricciones de entidades usando métodos estándar de GLPI
        $entity_criteria = $this->getEntityRestrictCriteria('Software', 'glpi_softwares');

        $search_fields = ['glpi_softwares.name', 'glpi_softwares.comment'];

        if (is_numeric($this->raw_query)) {
            // Criterio por ID
            $id_criteria = ['glpi_softwares.id' => $this->raw_query];

            if ($this->id_only) {
                // Modo solo ID
                $where = $id_criteria;
            } else {
                // Criterio por contenido
                $content_criteria = $this->getMultiWordCriteria($search_fields);

                // Combinar ambos con OR
                if (!empty($content_criteria)) {
                    $where = [
                        'OR' => [
                            $content_criteria,
                            $id_criteria
                        ]
                    ];
                } else {
                    $where = $id_criteria;
                }
            }
        } else {
            if (mb_strlen($this->raw_query) < 3) {
                return [];
            }

            $where = $this->getMultiWordCriteria($search_fields);
        }

        $criteria = [
            'SELECT' => [
                'glpi_softwares.id',
                'glpi_softwares.name',
                'glpi_softwares.entities_id',
                'glpi_softwares.date_mod',
                'glpi_softwares.manufacturers_id'
            ],
            'FROM' => 'glpi_softwares',
            'WHERE' => array_merge(
                $where,
                [
                    'glpi_softwares.is_deleted' => 0,
                    'glpi_softwares.is_template' => 0
                ],
                $entity_criteria
            ),
            'ORDER' => 'glpi_softwares.date_mod DESC'
        ];

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            // Verificar permisos adicionales
            $software = new Software();
            if ($software->can($row['id'], READ)) {
                $results[] = $row;
            }
        }
        return $results;
    }

    /**
     * Búsqueda en usuarios
     */
    public function searchUsers()
    {
        global $DB;

        if (!User::canView()) {
            return [];
        }

        // Los usuarios no tienen restricciones de entidad directas como otros items
        // pero debemos verificar permisos de visualización

        $search_fields = ['glpi_users.name', 'glpi_users.realname', 'glpi_users.firstname', 'glpi_users.phone', 'glpi_users.mobile'];

        if (is_numeric($this->raw_query)) {
            // Criterio por ID
            $id_criteria = ['glpi_users.id' => $this->raw_query];

            if ($this->id_only) {
                // Modo solo ID
                $where = $id_criteria;
            } else {
                // Criterio por contenido
                $content_criteria = $this->getMultiWordCriteria($search_fields);

                // Combinar ambos con OR
                if (!empty($content_criteria)) {
                    $where = [
                        'OR' => [
                            $content_criteria,
                            $id_criteria
                        ]
                    ];
                } else {
                    $where = $id_criteria;
                }
            }
        } else {
            if (mb_strlen($this->raw_query) < 3) {
                return [];
            }

            $where = $this->getMultiWordCriteria($search_fields);
        }

        $criteria = [
            'SELECT' => [
                'glpi_users.id',
                'glpi_users.name',
                'glpi_users.realname',
                'glpi_users.firstname',
                'glpi_users.phone',
                'glpi_users.mobile',
                'glpi_users.date_mod'
            ],
            'FROM' => 'glpi_users',
            'WHERE' => array_merge(
                $where,
                ['glpi_users.is_deleted' => 0]
            ),
            'ORDER' => 'glpi_users.date_mod DESC'
        ];

        $iterator = $DB->request($criteria);
        $results = [];

        foreach ($iterator as $row) {
            // Verificar permisos adicionales
            $user = new User();
            if ($user->can($row['id'], READ)) {
                $fullname = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
                $row['fullname'] = $fullname ?: $row['name'];
                $results[] = $row;
            }
        }

        return $results;
    }

    /**
     * Búsqueda en tareas de tickets
     */
    public function searchTicketTasks()
    {
        global $DB;

        if (mb_strlen($this->raw_query) < 1) {
            return [];
        }

        if (!TicketTask::canView()) {
            return [];
        }

        // Obtener restricciones de entidades para tickets (las tareas están vinculadas a tickets)
        $entity_criteria = $this->getEntityRestrictCriteria('Ticket', 'glpi_tickets');

        // Campos donde buscar
        $search_fields = ['glpi_tickettasks.content'];

        // Construir criterio de búsqueda en contenido
        $content_criteria = $this->getMultiWordCriteria($search_fields);

        // Si es numérico, también buscamos por ID de tarea o ID de ticket
        if (is_numeric($this->raw_query)) {
            $id_criteria = [
                'OR' => [
                    'glpi_tickettasks.id' => $this->raw_query,
                    'glpi_tickettasks.tickets_id' => $this->raw_query
                ]
            ];

            if ($this->id_only) {
                // Modo solo ID: no buscar en contenido
                $where_criteria = $id_criteria;
            } else {
                // Combinar búsqueda en contenido Y búsqueda por ID con OR
                if (!empty($content_criteria)) {
                    $where_criteria = [
                        'OR' => [
                            $content_criteria,
                            $id_criteria
                        ]
                    ];
                } else {
                    $where_criteria = $id_criteria;
                }
            }
        } else {
            $where_criteria = $content_criteria;
        }

        // Combinar con restricciones de entidades
        $where_criteria = array_merge($where_criteria, $entity_criteria);

        // Aplicar restricciones de permisos para tareas privadas
        $user_id = Session::getLoginUserID();
        $private_criteria = [
            'OR' => [
                'glpi_tickettasks.is_private' => 0,
                'glpi_tickettasks.users_id' => $user_id
            ]
        ];

        // Si el usuario tiene permiso para ver tareas privadas de otros, 
        // no aplicar la restricción (esto se verifica con can() después)
        // Por ahora, aplicamos la restricción básica en SQL

        $criteria = [
            'SELECT' => [
                'glpi_tickettasks.id',
                'glpi_tickettasks.tickets_id',
                'glpi_tickettasks.content',
                'glpi_tickettasks.date',
                'glpi_tickettasks.users_id',
                'glpi_tickettasks.date_mod',
                'glpi_tickettasks.is_private',
                'glpi_tickets.name AS ticket_name',
                'glpi_tickets.entities_id',
                'glpi_users.firstname AS requester_firstname',
                'glpi_users.realname AS requester_realname',
                'glpi_users.name AS requester_uname'
            ],
            'FROM' => 'glpi_tickettasks',
            'INNER JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        'glpi_tickettasks' => 'tickets_id',
                        'glpi_tickets' => 'id'
                    ]
                ],
                'glpi_tickets_users' => [
                    'ON' => [
                        'glpi_tickets' => 'id',
                        'glpi_tickets_users' => 'tickets_id'
                    ]
                ],
                'glpi_users' => [
                    'ON' => [
                        'glpi_tickets_users' => 'users_id',
                        'glpi_users' => 'id'
                    ]
                ]
            ],
            'WHERE' => array_merge(
                $where_criteria,
                [
                    'glpi_tickets_users.type' => 1, // Requester
                    $private_criteria
                ]
            ),
            'ORDER' => 'glpi_tickettasks.date_mod DESC'
        ];

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            // Verificar permisos: el método can() ya verifica tareas privadas y otros permisos
            $tickettask = new TicketTask();
            if ($tickettask->can($row['id'], READ)) {
                // Construir nombre del solicitante
                $fullname = trim(($row['requester_firstname'] ?? '') . ' ' . ($row['requester_realname'] ?? ''));
                $row['requester_name'] = $fullname ?: ($row['requester_uname'] ?? __('Unknown'));
                $results[] = $row;
            }
        }
        return $results;
    }

    /**
     * Búsqueda en tareas de proyectos
     */
    public function searchProjectTasks()
    {
        global $DB;

        if (!ProjectTask::canView()) {
            return [];
        }

        // Obtener restricciones de entidades usando métodos estándar de GLPI
        $entity_criteria = $this->getEntityRestrictCriteria('ProjectTask', 'glpi_projecttasks');

        $has_private_field = $DB->fieldExists('glpi_projecttasks', 'is_private');

        $search_fields = ['glpi_projecttasks.name', 'glpi_projecttasks.content', 'glpi_projecttasks.comment'];

        if (is_numeric($this->raw_query)) {
            // Criterio por ID
            $id_criteria = ['glpi_projecttasks.id' => $this->raw_query];

            if ($this->id_only) {
                // Modo solo ID
                $where = $id_criteria;
            } else {
                // Criterio por contenido
                $content_criteria = $this->getMultiWordCriteria($search_fields);

                // Combinar ambos con OR
                if (!empty($content_criteria)) {
                    $where = [
                        'OR' => [
                            $content_criteria,
                            $id_criteria
                        ]
                    ];
                } else {
                    $where = $id_criteria;
                }
            }
        } else {
            if (mb_strlen($this->raw_query) < 3) {
                return [];
            }

            $where = $this->getMultiWordCriteria($search_fields);
        }

        $select = [
            'glpi_projecttasks.id',
            'glpi_projecttasks.name',
            'glpi_projecttasks.content',
            'glpi_projecttasks.projects_id',
            'glpi_projecttasks.entities_id',
            'glpi_projecttasks.date_mod',
            'glpi_projecttasks.plan_start_date',
            'glpi_projecttasks.users_id',
            'glpi_users.firstname AS requester_firstname',
            'glpi_users.realname AS requester_realname',
            'glpi_users.name AS requester_uname'
        ];

        if ($has_private_field) {
            $select[] = 'glpi_projecttasks.is_private';
        }

        $criteria = [
            'SELECT' => $select,
            'FROM' => 'glpi_projecttasks',
            'LEFT JOIN' => [
                'glpi_users' => [
                    'ON' => ['glpi_projecttasks' => 'users_id', 'glpi_users' => 'id']
                ]
            ],
            'WHERE' => array_merge(
                $where,
                [
                    'glpi_projecttasks.is_template' => 0
                ],
                $entity_criteria,
                $has_private_field ? [
                    'OR' => [
                        'glpi_projecttasks.is_private' => 0,
                        'glpi_projecttasks.users_id' => Session::getLoginUserID()
                    ]
                ] : []
            ),
            'ORDER' => 'glpi_projecttasks.date_mod DESC'
        ];

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            // Verificar permisos: el método can() ya verifica tareas privadas y otros permisos
            $projecttask = new ProjectTask();
            if ($projecttask->can($row['id'], READ)) {
                // Construir nombre del solicitante
                $fullname = trim(($row['requester_firstname'] ?? '') . ' ' . ($row['requester_realname'] ?? ''));
                $row['requester_name'] = $fullname ?: ($row['requester_uname'] ?? __('Unknown'));
                $results[] = $row;
            }
        }
        return $results;
    }
}
