<?php

if (!defined('GLPI_ROOT')) {
    die('Direct access not allowed');
}

class PluginGlobalsearchSearchEngine
{
    /** @var string */
    private $raw_query;

    /** @var array */
    private $allowed_entities = [];

    /**
     * @param string $raw_query
     */
    public function __construct($raw_query)
    {
        $this->raw_query = trim($raw_query);
        // Asegurar que es un array válido
        $this->allowed_entities = (isset($_SESSION['glpiactiveentities']) && is_array($_SESSION['glpiactiveentities']))
            ? $_SESSION['glpiactiveentities']
            : [];
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

        // Búsqueda por ID exacta si es numérico
        if (is_numeric($this->raw_query)) {
            if (!Ticket::canView()) return [];

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
                'WHERE'  => ['glpi_tickets.id' => $this->raw_query]
            ];

            if (!empty($this->allowed_entities)) {
                $criteria['WHERE']['glpi_tickets.entities_id'] = $this->allowed_entities;
            }

            $iterator = $DB->request($criteria);
            $results = [];
            foreach ($iterator as $row) {
                $row['status_name'] = Ticket::getStatus($row['status']);
                $row['tech_name'] = $this->getTechnicianName($row['id']);
                $results[] = $row;
            }
            return $results;
        }

        if (mb_strlen($this->raw_query) < 3) {
            return [];
        }

        if (!Ticket::canView()) {
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
            'WHERE'  => $this->getMultiWordCriteria($search_fields),
            'ORDER'  => 'glpi_tickets.date_mod DESC',
            'LIMIT'  => (int)$limit
        ];

        if (!empty($this->allowed_entities)) {
            $criteria['WHERE']['glpi_tickets.entities_id'] = $this->allowed_entities;
        }

        $iterator = $DB->request($criteria);
        $results  = [];

        foreach ($iterator as $row) {
            $row['status_name'] = Ticket::getStatus($row['status']);
            $row['tech_name'] = $this->getTechnicianName($row['id']);
            $results[] = $row;
        }

        return $results;
    }

    /**
     * Búsqueda en proyectos
     */
    public function searchProjects($limit = 20)
    {
        global $DB;

        if (!Project::canView()) return [];

        if (is_numeric($this->raw_query)) {
            $criteria = [
                'SELECT' => ['id', 'name', 'projectstates_id', 'entities_id', 'plan_start_date', 'plan_end_date', 'date_mod', 'date'],
                'FROM'   => 'glpi_projects',
                'WHERE'  => ['id' => $this->raw_query]
            ];
            if (!empty($this->allowed_entities)) $criteria['WHERE']['entities_id'] = $this->allowed_entities;

            $iterator = $DB->request($criteria);
            $results = [];
            foreach ($iterator as $row) {
                $results[] = $row;
            }
            return $results;
        }

        if (mb_strlen($this->raw_query) < 3) return [];

        $search_fields = ['name', 'comment', 'content'];

        $criteria = [
            'SELECT' => [
                'id',
                'name',
                'projectstates_id',
                'entities_id',
                'plan_start_date',
                'plan_end_date',
                'date_mod',
                'date'
            ],
            'FROM'   => 'glpi_projects',
            'WHERE'  => $this->getMultiWordCriteria($search_fields),
            'ORDER'  => 'date_mod DESC',
            'LIMIT'  => (int)$limit
        ];

        if (!empty($this->allowed_entities)) {
            $criteria['WHERE']['entities_id'] = $this->allowed_entities;
        }

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            $results[] = $row;
        }
        return $results;
    }

    /**
     * Búsqueda en documentos
     */
    public function searchDocuments($limit = 20)
    {
        global $DB;

        if (!Document::canView()) return [];

        if (is_numeric($this->raw_query)) {
            $criteria = [
                'SELECT' => ['id', 'name', 'filename', 'entities_id', 'date_mod', 'documentcategories_id'],
                'FROM'   => 'glpi_documents',
                'WHERE'  => ['id' => $this->raw_query]
            ];
            if (!empty($this->allowed_entities)) $criteria['WHERE']['entities_id'] = $this->allowed_entities;

            $iterator = $DB->request($criteria);
            $results = [];
            foreach ($iterator as $row) {
                $results[] = $row;
            }
            return $results;
        }

        if (mb_strlen($this->raw_query) < 3) return [];

        $search_fields = ['name', 'filename', 'comment'];

        $criteria = [
            'SELECT' => ['id', 'name', 'filename', 'entities_id', 'date_mod', 'documentcategories_id'],
            'FROM'   => 'glpi_documents',
            'WHERE'  => $this->getMultiWordCriteria($search_fields),
            'ORDER'  => 'date_mod DESC',
            'LIMIT'  => (int)$limit
        ];

        // Filtro extra: no eliminados
        $criteria['WHERE']['is_deleted'] = 0;

        if (!empty($this->allowed_entities)) {
            $criteria['WHERE']['entities_id'] = $this->allowed_entities;
        }

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            $results[] = $row;
        }
        return $results;
    }

    /**
     * Búsqueda en software
     */
    public function searchSoftware($limit = 20)
    {
        global $DB;

        if (!Software::canView()) return [];

        if (is_numeric($this->raw_query)) {
            $criteria = [
                'SELECT' => ['id', 'name', 'entities_id', 'date_mod', 'manufacturers_id'],
                'FROM'   => 'glpi_softwares',
                'WHERE'  => ['id' => $this->raw_query]
            ];
            if (!empty($this->allowed_entities)) $criteria['WHERE']['entities_id'] = $this->allowed_entities;

            $iterator = $DB->request($criteria);
            $results = [];
            foreach ($iterator as $row) {
                $results[] = $row;
            }
            return $results;
        }

        if (mb_strlen($this->raw_query) < 3) return [];

        $search_fields = ['name', 'comment'];

        $criteria = [
            'SELECT' => ['id', 'name', 'entities_id', 'date_mod', 'manufacturers_id'],
            'FROM'   => 'glpi_softwares',
            'WHERE'  => $this->getMultiWordCriteria($search_fields),
            'ORDER'  => 'date_mod DESC',
            'LIMIT'  => (int)$limit
        ];

        $criteria['WHERE']['is_deleted'] = 0;
        $criteria['WHERE']['is_template'] = 0;

        if (!empty($this->allowed_entities)) {
            $criteria['WHERE']['entities_id'] = $this->allowed_entities;
        }

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            $results[] = $row;
        }
        return $results;
    }

    /**
     * Búsqueda en usuarios
     */
    public function searchUsers($limit = 20)
    {
        global $DB;

        if (!User::canView()) return [];

        if (is_numeric($this->raw_query)) {
            // Búsqueda por ID simplificada
            $criteria = [
                'SELECT' => ['id', 'name', 'realname', 'firstname', 'phone', 'mobile', 'date_mod'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => $this->raw_query, 'is_deleted' => 0]
            ];
            // Usuarios no siempre tienen entidades estrictas (pueden ser recursivos), 
            // pero para simplificar y seguridad, no añadimos restricción fuerte de entidad aquí 
            // a menos que la lógica de negocio lo exija.

            $iterator = $DB->request($criteria);
            $results = [];
            foreach ($iterator as $row) {
                $fullname = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
                $row['fullname'] = $fullname ?: $row['name'];
                $results[] = $row;
            }
            return $results;
        }

        if (mb_strlen($this->raw_query) < 3) return [];

        $search_fields = ['name', 'realname', 'firstname', 'phone', 'mobile'];

        $criteria = [
            'SELECT' => ['id', 'name', 'realname', 'firstname', 'phone', 'mobile', 'date_mod'],
            'FROM'   => 'glpi_users',
            'WHERE'  => $this->getMultiWordCriteria($search_fields),
            'ORDER'  => 'date_mod DESC',
            'LIMIT'  => (int)$limit
        ];

        $criteria['WHERE']['is_deleted'] = 0;

        $iterator = $DB->request($criteria);
        $results  = [];

        foreach ($iterator as $row) {
            $fullname = trim(($row['firstname'] ?? '') . ' ' . ($row['realname'] ?? ''));
            $row['fullname'] = $fullname ?: $row['name'];
            $results[] = $row;
        }

        return $results;
    }

    /**
     * Búsqueda en tareas de tickets
     */
    public function searchTicketTasks($limit = 20)
    {
        global $DB;

        if (mb_strlen($this->raw_query) < 1) return [];
        if (!TicketTask::canView()) return [];

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

        $criteria = [
            'SELECT' => [
                'glpi_tickettasks.id',
                'glpi_tickettasks.tickets_id',
                'glpi_tickettasks.content',
                'glpi_tickettasks.date',
                'glpi_tickettasks.users_id',
                'glpi_tickettasks.date_mod',
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
            'WHERE'  => $where_criteria,
            'ORDER'  => 'glpi_tickettasks.date_mod DESC',
            'LIMIT'  => (int)$limit
        ];

        if (!empty($this->allowed_entities)) {
            $criteria['WHERE']['glpi_tickets.entities_id'] = $this->allowed_entities;
        }

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            $results[] = $row;
        }
        return $results;
    }

    /**
     * Búsqueda en tareas de proyectos
     */
    public function searchProjectTasks($limit = 20)
    {
        global $DB;

        if (!ProjectTask::canView()) return [];

        if (is_numeric($this->raw_query)) {
            $criteria = [
                'SELECT' => ['id', 'name', 'content', 'projects_id', 'entities_id', 'date_mod', 'plan_start_date'],
                'FROM'   => 'glpi_projecttasks',
                'WHERE'  => ['id' => $this->raw_query]
            ];
            if (!empty($this->allowed_entities)) $criteria['WHERE']['entities_id'] = $this->allowed_entities;

            $iterator = $DB->request($criteria);
            $results = [];
            foreach ($iterator as $row) {
                $results[] = $row;
            }
            return $results;
        }

        if (mb_strlen($this->raw_query) < 3) return [];

        $search_fields = ['name', 'content', 'comment'];

        $criteria = [
            'SELECT' => ['id', 'name', 'content', 'projects_id', 'entities_id', 'date_mod', 'plan_start_date'],
            'FROM'   => 'glpi_projecttasks',
            'WHERE'  => $this->getMultiWordCriteria($search_fields),
            'ORDER'  => 'date_mod DESC',
            'LIMIT'  => (int)$limit
        ];

        $criteria['WHERE']['is_template'] = 0;

        if (!empty($this->allowed_entities)) {
            $criteria['WHERE']['entities_id'] = $this->allowed_entities;
        }

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            $results[] = $row;
        }
        return $results;
    }
}
