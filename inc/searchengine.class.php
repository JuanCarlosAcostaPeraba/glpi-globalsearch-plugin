<?php

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

class PluginGlobalsearchSearchEngine
{
    /** @var string */
    private $raw_query;

    /**
     * @param string $raw_query
     */
    public function __construct($raw_query)
    {
        $this->raw_query = trim($raw_query);
    }

    /**
     * Obtiene las restricciones de entidades usando métodos estándar de GLPI.
     * Considera entidades recursivas (is_recursive = 1).
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
        $entities = [];
        if (isset($_SESSION['glpiactiveentities']) && is_array($_SESSION['glpiactiveentities'])) {
            $entities = $_SESSION['glpiactiveentities'];
        }

        if (empty($entities)) {
            return [];
        }

        $field_name = ($table_alias !== null) ? $table_alias . '.' . $field : $table . '.' . $field;

        // En GLPI 11 simplificamos: limitamos por las entidades activas sin usar is_recursive
        return [
            $field_name => $entities
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
            'FROM'   => 'glpi_tickets_users',
            'INNER JOIN' => [
                'glpi_users' => [
                    'ON' => [
                        'glpi_tickets_users' => 'users_id',
                        'glpi_users'         => 'id'
                    ]
                ]
            ],
            'WHERE'  => [
                'glpi_tickets_users.tickets_id' => $ticket_id,
                'glpi_tickets_users.type'       => 2  // 2 = Assigned technician
            ],
            'LIMIT'  => 1
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
        // Dividir por espacios
        $words = explode(' ', $this->raw_query);

        // Eliminar palabras vacías y espacios extra
        $words = array_filter($words, function ($w) {
            return mb_strlen(trim($w)) > 0;
        });

        if (empty($words)) {
            return [];
        }

        $and_criteria = [];

        foreach ($words as $word) {
            // Cada palabra debe encontrarse en ALGUNO de los campos (OR)
            $or_criteria = [];
            foreach ($fields as $field) {
                $or_criteria[$field] = ['LIKE', '%' . $word . '%'];
            }
            // Agregamos este bloque OR al bloque principal AND
            $and_criteria[] = ['OR' => $or_criteria];
        }

        return ['AND' => $and_criteria];
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
     *
     * @param int $limit
     * @return array
     */
    public function searchTickets($limit = 20)
    {
        global $DB;

        if (!Ticket::canView()) {
            return [];
        }

        // Obtener restricciones de entidades usando métodos estándar de GLPI
        $entity_criteria = $this->getEntityRestrictCriteria('Ticket', 'glpi_tickets');

        // Búsqueda por ID exacta si es numérico
        if (is_numeric($this->raw_query)) {
            $criteria = [
                'SELECT' => [
                    'glpi_tickets.id',
                    'glpi_tickets.name',
                    'glpi_tickets.status',
                    'glpi_tickets.entities_id',
                    'glpi_tickets.date',
                    'glpi_tickets.closedate',
                    'glpi_tickets.date_mod'
                ],
                'FROM'   => 'glpi_tickets',
                'WHERE'  => array_merge(
                    ['glpi_tickets.id' => $this->raw_query],
                    $entity_criteria
                )
            ];

            $iterator = $DB->request($criteria);
            $results = [];
            foreach ($iterator as $row) {
                // Verificar permisos adicionales antes de incluir el resultado
                $ticket = new Ticket();
                if ($ticket->can($row['id'], READ)) {
                    $row['status_name'] = Ticket::getStatus($row['status']);
                    $row['tech_name'] = $this->getTechnicianName($row['id']);
                    $results[] = $row;
                }
            }
            return $results;
        }

        if (mb_strlen($this->raw_query) < 3) {
            return [];
        }

        // Campos donde buscar
        $search_fields = ['glpi_tickets.name', 'glpi_tickets.content'];

        $criteria = [
            'SELECT' => [
                'glpi_tickets.id',
                'glpi_tickets.name',
                'glpi_tickets.status',
                'glpi_tickets.entities_id',
                'glpi_tickets.date',
                'glpi_tickets.closedate',
                'glpi_tickets.date_mod'
            ],
            'FROM'   => 'glpi_tickets',
            'WHERE'  => array_merge(
                $this->getMultiWordCriteria($search_fields),
                $entity_criteria
            ),
            'ORDER'  => 'glpi_tickets.date_mod DESC',
            'LIMIT'  => (int)$limit
        ];

        $iterator = $DB->request($criteria);
        $results  = [];

        foreach ($iterator as $row) {
            // Verificar permisos adicionales antes de incluir el resultado
            $ticket = new Ticket();
            if ($ticket->can($row['id'], READ)) {
                $row['status_name'] = Ticket::getStatus($row['status']);
                $row['tech_name'] = $this->getTechnicianName($row['id']);
                $results[] = $row;
            }
        }

        return $results;
    }

    /**
     * Búsqueda en proyectos
     */
    public function searchProjects($limit = 20)
    {
        global $DB;

        if (!Project::canView()) {
            return [];
        }

        // Obtener restricciones de entidades usando métodos estándar de GLPI
        $entity_criteria = $this->getEntityRestrictCriteria('Project', 'glpi_projects');

        if (is_numeric($this->raw_query)) {
            $criteria = [
                'SELECT' => [
                    'glpi_projects.id',
                    'glpi_projects.name',
                    'glpi_projects.projectstates_id',
                    'glpi_projects.entities_id',
                    'glpi_projects.plan_start_date',
                    'glpi_projects.plan_end_date',
                    'glpi_projects.date_mod',
                    'glpi_projects.date'
                ],
                'FROM'   => 'glpi_projects',
                'WHERE'  => array_merge(
                    ['glpi_projects.id' => $this->raw_query],
                    $entity_criteria
                )
            ];

            $iterator = $DB->request($criteria);
            $results = [];
            foreach ($iterator as $row) {
                // Verificar permisos adicionales
                $project = new Project();
                if ($project->can($row['id'], READ)) {
                    $results[] = $row;
                }
            }
            return $results;
        }

        if (mb_strlen($this->raw_query) < 3) {
            return [];
        }

        $search_fields = ['glpi_projects.name', 'glpi_projects.comment', 'glpi_projects.content'];

        $criteria = [
            'SELECT' => [
                'glpi_projects.id',
                'glpi_projects.name',
                'glpi_projects.projectstates_id',
                'glpi_projects.entities_id',
                'glpi_projects.plan_start_date',
                'glpi_projects.plan_end_date',
                'glpi_projects.date_mod',
                'glpi_projects.date'
            ],
            'FROM'   => 'glpi_projects',
            'WHERE'  => array_merge(
                $this->getMultiWordCriteria($search_fields),
                $entity_criteria
            ),
            'ORDER'  => 'glpi_projects.date_mod DESC',
            'LIMIT'  => (int)$limit
        ];

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            // Verificar permisos adicionales
            $project = new Project();
            if ($project->can($row['id'], READ)) {
                $results[] = $row;
            }
        }
        return $results;
    }

    /**
     * Búsqueda en documentos
     */
    public function searchDocuments($limit = 20)
    {
        global $DB;

        if (!Document::canView()) {
            return [];
        }

        // Obtener restricciones de entidades usando métodos estándar de GLPI
        $entity_criteria = $this->getEntityRestrictCriteria('Document', 'glpi_documents');

        if (is_numeric($this->raw_query)) {
            $criteria = [
                'SELECT' => [
                    'glpi_documents.id',
                    'glpi_documents.name',
                    'glpi_documents.filename',
                    'glpi_documents.entities_id',
                    'glpi_documents.date_mod',
                    'glpi_documents.documentcategories_id'
                ],
                'FROM'   => 'glpi_documents',
                'WHERE'  => array_merge(
                    [
                        'glpi_documents.id' => $this->raw_query,
                        'glpi_documents.is_deleted' => 0
                    ],
                    $entity_criteria
                )
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

        if (mb_strlen($this->raw_query) < 3) {
            return [];
        }

        $search_fields = ['glpi_documents.name', 'glpi_documents.filename', 'glpi_documents.comment'];

        $criteria = [
            'SELECT' => [
                'glpi_documents.id',
                'glpi_documents.name',
                'glpi_documents.filename',
                'glpi_documents.entities_id',
                'glpi_documents.date_mod',
                'glpi_documents.documentcategories_id'
            ],
            'FROM'   => 'glpi_documents',
            'WHERE'  => array_merge(
                $this->getMultiWordCriteria($search_fields),
                [
                    'glpi_documents.is_deleted' => 0
                ],
                $entity_criteria
            ),
            'ORDER'  => 'glpi_documents.date_mod DESC',
            'LIMIT'  => (int)$limit
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
    public function searchSoftware($limit = 20)
    {
        global $DB;

        if (!Software::canView()) {
            return [];
        }

        // Obtener restricciones de entidades usando métodos estándar de GLPI
        $entity_criteria = $this->getEntityRestrictCriteria('Software', 'glpi_softwares');

        if (is_numeric($this->raw_query)) {
            $criteria = [
                'SELECT' => [
                    'glpi_softwares.id',
                    'glpi_softwares.name',
                    'glpi_softwares.entities_id',
                    'glpi_softwares.date_mod',
                    'glpi_softwares.manufacturers_id'
                ],
                'FROM'   => 'glpi_softwares',
                'WHERE'  => array_merge(
                    [
                        'glpi_softwares.id' => $this->raw_query,
                        'glpi_softwares.is_deleted' => 0,
                        'glpi_softwares.is_template' => 0
                    ],
                    $entity_criteria
                )
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

        if (mb_strlen($this->raw_query) < 3) {
            return [];
        }

        $search_fields = ['glpi_softwares.name', 'glpi_softwares.comment'];

        $criteria = [
            'SELECT' => [
                'glpi_softwares.id',
                'glpi_softwares.name',
                'glpi_softwares.entities_id',
                'glpi_softwares.date_mod',
                'glpi_softwares.manufacturers_id'
            ],
            'FROM'   => 'glpi_softwares',
            'WHERE'  => array_merge(
                $this->getMultiWordCriteria($search_fields),
                [
                    'glpi_softwares.is_deleted' => 0,
                    'glpi_softwares.is_template' => 0
                ],
                $entity_criteria
            ),
            'ORDER'  => 'glpi_softwares.date_mod DESC',
            'LIMIT'  => (int)$limit
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
    public function searchUsers($limit = 20)
    {
        global $DB;

        if (!User::canView()) {
            return [];
        }

        // Los usuarios no tienen restricciones de entidad directas como otros items
        // pero debemos verificar permisos de visualización

        if (is_numeric($this->raw_query)) {
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
                'FROM'   => 'glpi_users',
                'WHERE'  => [
                    'glpi_users.id' => $this->raw_query,
                    'glpi_users.is_deleted' => 0
                ]
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

        if (mb_strlen($this->raw_query) < 3) {
            return [];
        }

        $search_fields = ['glpi_users.name', 'glpi_users.realname', 'glpi_users.firstname', 'glpi_users.phone', 'glpi_users.mobile'];

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
            'FROM'   => 'glpi_users',
            'WHERE'  => array_merge(
                $this->getMultiWordCriteria($search_fields),
                ['glpi_users.is_deleted' => 0]
            ),
            'ORDER'  => 'glpi_users.date_mod DESC',
            'LIMIT'  => (int)$limit
        ];

        $iterator = $DB->request($criteria);
        $results  = [];

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
    public function searchTicketTasks($limit = 20)
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
        } else {
            $where_criteria = $content_criteria;
        }

        // Combinar con restricciones de entidades
        $where_criteria = array_merge($where_criteria, $entity_criteria);

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
                'glpi_tickets.entities_id'
            ],
            'FROM'   => 'glpi_tickettasks',
            'INNER JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        'glpi_tickettasks' => 'tickets_id',
                        'glpi_tickets'     => 'id'
                    ]
                ]
            ],
            'WHERE'  => array_merge(
                $where_criteria,
                [
                    'OR' => [
                        'glpi_tickettasks.is_private' => 0,
                        'glpi_tickettasks.users_id'   => Session::getLoginUserID()
                    ]
                ]
            ),
            'ORDER'  => 'glpi_tickettasks.date_mod DESC',
            'LIMIT'  => (int)$limit
        ];

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            // Verificar permisos: el método can() ya verifica tareas privadas y otros permisos
            $tickettask = new TicketTask();
            if ($tickettask->can($row['id'], READ)) {
                $results[] = $row;
            }
        }
        return $results;
    }

    /**
     * Búsqueda en tareas de proyectos
     */
    public function searchProjectTasks($limit = 20)
    {
        global $DB;

        if (!ProjectTask::canView()) {
            return [];
        }

        // Obtener restricciones de entidades usando métodos estándar de GLPI
        $entity_criteria = $this->getEntityRestrictCriteria('ProjectTask', 'glpi_projecttasks');

        if (is_numeric($this->raw_query)) {
            $criteria = [
                'SELECT' => [
                    'glpi_projecttasks.id',
                    'glpi_projecttasks.name',
                    'glpi_projecttasks.content',
                    'glpi_projecttasks.projects_id',
                    'glpi_projecttasks.entities_id',
                    'glpi_projecttasks.date_mod',
                    'glpi_projecttasks.plan_start_date',
                    'glpi_projecttasks.is_private'
                ],
                'FROM'   => 'glpi_projecttasks',
                'WHERE'  => array_merge(
                    [
                        'glpi_projecttasks.id' => $this->raw_query,
                        'glpi_projecttasks.is_template' => 0
                    ],
                    $entity_criteria,
                    [
                        'OR' => [
                            'glpi_projecttasks.is_private' => 0,
                            'glpi_projecttasks.users_id'   => Session::getLoginUserID()
                        ]
                    ]
                )
            ];

            $iterator = $DB->request($criteria);
            $results = [];
            foreach ($iterator as $row) {
                // Verificar permisos: el método can() ya verifica tareas privadas y otros permisos
                $projecttask = new ProjectTask();
                if ($projecttask->can($row['id'], READ)) {
                    $results[] = $row;
                }
            }
            return $results;
        }

        if (mb_strlen($this->raw_query) < 3) {
            return [];
        }

        $search_fields = ['glpi_projecttasks.name', 'glpi_projecttasks.content', 'glpi_projecttasks.comment'];

        $criteria = [
            'SELECT' => [
                'glpi_projecttasks.id',
                'glpi_projecttasks.name',
                'glpi_projecttasks.content',
                'glpi_projecttasks.projects_id',
                'glpi_projecttasks.entities_id',
                'glpi_projecttasks.date_mod',
                'glpi_projecttasks.plan_start_date',
                'glpi_projecttasks.is_private',
                'glpi_projecttasks.users_id'
            ],
            'FROM'   => 'glpi_projecttasks',
            'WHERE'  => array_merge(
                $this->getMultiWordCriteria($search_fields),
                [
                    'glpi_projecttasks.is_template' => 0
                ],
                $entity_criteria,
                [
                    'OR' => [
                        'glpi_projecttasks.is_private' => 0,
                        'glpi_projecttasks.users_id'   => Session::getLoginUserID()
                    ]
                ]
            ),
            'ORDER'  => 'glpi_projecttasks.date_mod DESC',
            'LIMIT'  => (int)$limit
        ];

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            // Verificar permisos: el método can() ya verifica tareas privadas y otros permisos
            $projecttask = new ProjectTask();
            if ($projecttask->can($row['id'], READ)) {
                $results[] = $row;
            }
        }
        return $results;
    }
}
