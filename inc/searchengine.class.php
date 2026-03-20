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

        // Support for "#123" prefix => ID-only mode
        if ($raw !== '' && mb_substr($raw, 0, 1) === '#') {
            $id = trim(mb_substr($raw, 1));
            if (is_numeric($id)) {
                $this->id_only = true;
                $this->raw_query = $id;
                return;
            }
            // If there is no number after #, use the full query
        }

        $this->raw_query = $raw;
    }

    /**
     * Gets the entity restriction criteria using standard GLPI methods.
     * Considers recursive entities (is_recursive = 1).
     * If an item is in a parent entity with is_recursive=1, it will be visible from child entities.
     *
     * @param string $itemtype Item type (Ticket, Project, etc.)
     * @param string $table_alias Table alias in the query (e.g.: 'glpi_tickets')
     * @return array WHERE criteria for entity restrictions
     */
    private function getEntityRestrictCriteria($itemtype, $table_alias = null)
    {
        $table = $itemtype::getTable();
        $field = 'entities_id';

        // Get all active entities for the user
        $active_entities = [];
        if (isset($_SESSION['glpiactiveentities']) && is_array($_SESSION['glpiactiveentities'])) {
            $active_entities = $_SESSION['glpiactiveentities'];
        }

        if (empty($active_entities)) {
            return [];
        }

        // Get recursion information for the user
        $recursive_entities = [];
        if (isset($_SESSION['glpiactiveentities_recursive']) && is_array($_SESSION['glpiactiveentities_recursive'])) {
            $recursive_entities = $_SESSION['glpiactiveentities_recursive'];
        }

        // Build complete list of accessible entities
        // In GLPI, if an item is in a parent entity with is_recursive=1,
        // that item is visible from any child entity.
        // Therefore, we need to include:
        // 1. All active entities of the user
        // 2. All child entities of the active entities with is_recursive=1
        // 3. All parent entities of the active entities (to see items from parents with is_recursive)
        $all_entities = [];

        foreach ($active_entities as $entity_id) {
            // Add the active entity
            $all_entities[$entity_id] = $entity_id;

            // If the entity has is_recursive=1, get all its child entities
            if (isset($recursive_entities[$entity_id]) && $recursive_entities[$entity_id] == 1) {
                // Get all child entities recursively
                $sons = Entity::getSonsOf($entity_id);
                foreach ($sons as $son_id) {
                    $all_entities[$son_id] = $son_id;
                }
            }

            // Include all ancestors of this active entity
            // This allows seeing items in parent entities with is_recursive=1
            // We need to create an Entity instance to use getAncestors()
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
     * Gets the name of the technician assigned to a ticket.
     * Technicians are stored in glpi_tickets_users with type = 2 (assigned).
     *
     * @param int $ticket_id Ticket ID
     * @return string Technician name or "Not assigned"
     */
    private function getTechnicianName($ticket_id)
    {
        global $DB;

        // Find assigned technician (type = 2 means "assigned")
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
     * Generates "Google-style" search criteria.
     * Splits the query into words and requires ALL words to appear
     * in at least one of the provided fields.
     *
     * @param array $fields Array of field names (e.g.: ['name', 'content'])
     * @return array Array compatible with DBmysqlIterator WHERE
     */
    private function getMultiWordCriteria(array $fields)
    {
        // Regex to find "literal phrases" or standalone words.
        // Captures content between quotes in the first group or unquoted words in the second.
        preg_match_all('/"([^"]+)"|(\S+)/', $this->raw_query, $matches);

        $terms = [];
        foreach ($matches[1] as $key => $phrase) {
            if ($phrase !== '') {
                // It's a quoted phrase
                $terms[] = $phrase;
            } else {
                // It's a standalone word (or a malformed quote)
                $term = $matches[2][$key];
                if ($term !== '') {
                    // If the term is just a quote ("), skip it to avoid LIKE '%%%'
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
            // Each term (word or phrase) must be found in ANY of the fields (OR)
            $or_criteria = [];
            foreach ($fields as $field) {
                $or_criteria[$field] = ['LIKE', '%' . $term . '%'];
            }
            // Add this OR block to the main AND block
            $and_criteria[] = ['OR' => $or_criteria];
        }

        // If there's only one term, return the OR directly to flatten the SQL.
        // If there are multiple, wrap them in an AND.
        return (count($and_criteria) === 1) ? $and_criteria[0] : ['AND' => $and_criteria];
    }

    /**
     * Gets the permission restriction criteria for tickets.
     * Considers whether the user can see unassigned tickets, only assigned ones, etc.
     *
     * @return array WHERE criteria for permission restrictions
     */
    private function getTicketPermissionCriteria()
    {
        $user_id = Session::getLoginUserID();
        $criteria = [];

        // Check if the user can see all tickets or only assigned ones
        // In GLPI, this is controlled through profiles and rights
        // If the user doesn't have permission to see unassigned tickets,
        // we should filter only tickets assigned to them or their groups

        // Check if the user has the right to see all tickets
        // This is done by checking the user's profile
        $can_see_all = false;
        if (isset($_SESSION['glpiactiveprofile']['ticket'])) {
            // If they have the right to see all tickets (typically ticket = ALL)
            // In GLPI, rights are stored in $_SESSION['glpiactiveprofile']
            // To simplify, we use the can() method later, but we try to
            // apply some basic restrictions in SQL when possible
        }

        // If they can't see all tickets, apply assigned ticket restriction
        // Note: This is an approximation. The full verification is done with can()
        // but we can optimize by filtering in SQL when possible
        if (!$can_see_all) {
            // We don't apply a restriction here because it's complex to determine
            // all cases (groups, observers, etc.)
            // We let can() handle it later
        }

        return $criteria;
    }

    /**
     * Executes all supported searches.
     *
     * @return array
     */
    public function searchAll()
    {
        $results = [];

        // Check configuration for each search type
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

        if (PluginGlobalsearchConfig::isEnabled('Change')) {
            $results['Change'] = $this->searchChanges();
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
     * Search in tickets (including closed/resolved).
     * Returns all results without limit, using Bulk Load strategy for technicians.
     * Applies permission restrictions based on the user's rights.
     *
     * @return array
     */
    public function searchTickets()
    {
        global $DB;

        if (!Ticket::canView()) {
            return [];
        }

        // Get entity restrictions using standard GLPI methods
        $entity_criteria = $this->getEntityRestrictCriteria('Ticket', 'glpi_tickets');

        // Build common WHERE criteria
        $where = [];
        $search_fields = ['glpi_tickets.name', 'glpi_tickets.content'];

        if ($this->id_only) {
            $where = ['glpi_tickets.id' => $this->raw_query];
        } else {
            $main_criteria = [];
            if (is_numeric($this->raw_query)) {
                $id_criteria = ['glpi_tickets.id' => $this->raw_query];
                $content_criteria = $this->getMultiWordCriteria($search_fields);
                $main_criteria = !empty($content_criteria) ? ['OR' => [$id_criteria, $content_criteria]] : $id_criteria;
            } elseif (mb_strlen($this->raw_query) >= 3) {
                $main_criteria = $this->getMultiWordCriteria($search_fields);
            } else {
                return [];
            }

            // Enhanced search: match tickets if any of their tasks match the criteria
            $task_subquery_criteria = $this->getMultiWordCriteria(['content']);
            if (!empty($task_subquery_criteria)) {
                $task_match = [
                    'glpi_tickets.id' => [
                        'IN',
                        new QuerySubQuery([
                            'SELECT' => 'tickets_id',
                            'FROM' => 'glpi_tickettasks',
                            'WHERE' => $task_subquery_criteria
                        ])
                    ]
                ];
                $where = ['OR' => array_filter([$main_criteria, $task_match])];
            } else {
                $where = $main_criteria;
            }
        }

        // Apply permission restrictions for tickets
        $permission_criteria = $this->getTicketPermissionCriteria();

        $common_where = [
            'AND' => array_filter([
                $where,
                $entity_criteria,
                $permission_criteria,
                ['glpi_tickets.is_deleted' => 0]
            ])
        ];

        // 1. GET ALL TICKETS (NO LIMIT, NO JOIN of technicians to avoid duplicates)
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
            // Verify permissions before adding
            if ($ticket_obj->can($row['id'], READ)) {
                $row['status_name'] = Ticket::getStatus($row['status']);
                $row['tech_name'] = __('Not assigned'); // Initial value
                $row['requester_name'] = __('Not assigned'); // Initial value
                $tickets[$row['id']] = $row;
                $ticket_ids[] = $row['id'];
            }
        }

        // 2. BULK LOAD TECHNICIANS
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

            // Assign concatenated names
            foreach ($techs_by_ticket as $tid => $names) {
                if (isset($tickets[$tid])) {
                    $tickets[$tid]['tech_name'] = implode(', ', $names);
                }
            }
        }

        // 3. BULK LOAD REQUESTERS
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

            // Assign concatenated names
            foreach ($requesters_by_ticket as $tid => $names) {
                if (isset($tickets[$tid])) {
                    $tickets[$tid]['requester_name'] = implode(', ', $names);
                }
            }
        }

        return array_values($tickets);
    }

    /**
     * Search in projects
     */
    public function searchProjects()
    {
        global $DB;

        if (!Project::canView()) {
            return [];
        }

        // Get entity restrictions using standard GLPI methods
        $entity_criteria = $this->getEntityRestrictCriteria('Project', 'glpi_projects');

        $search_fields = ['glpi_projects.name', 'glpi_projects.comment', 'glpi_projects.content'];

        if ($this->id_only) {
            $where = ['glpi_projects.id' => $this->raw_query];
        } else {
            $main_criteria = [];
            if (is_numeric($this->raw_query)) {
                $id_criteria = ['glpi_projects.id' => $this->raw_query];
                $content_criteria = $this->getMultiWordCriteria($search_fields);
                $main_criteria = !empty($content_criteria) ? ['OR' => [$id_criteria, $content_criteria]] : $id_criteria;
            } elseif (mb_strlen($this->raw_query) >= 3) {
                $main_criteria = $this->getMultiWordCriteria($search_fields);
            } else {
                return [];
            }

            // Enhanced search: match projects if any of their tasks match the criteria
            $task_subquery_criteria = $this->getMultiWordCriteria(['content', 'name']);
            if (!empty($task_subquery_criteria)) {
                $task_match = [
                    'glpi_projects.id' => [
                        'IN',
                        new QuerySubQuery([
                            'SELECT' => 'projects_id',
                            'FROM' => 'glpi_projecttasks',
                            'WHERE' => $task_subquery_criteria
                        ])
                    ]
                ];
                $where = ['OR' => array_filter([$main_criteria, $task_match])];
            } else {
                $where = $main_criteria;
            }
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
            'WHERE' => [
                'AND' => array_filter([
                    $where,
                    $entity_criteria
                ])
            ],
            'ORDER' => 'glpi_projects.date_mod DESC'
        ];

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            // Verify additional permissions
            $project = new Project();
            if ($project->can($row['id'], READ)) {
                // Build requester name
                $fullname = trim(($row['requester_firstname'] ?? '') . ' ' . ($row['requester_realname'] ?? ''));
                $row['requester_name'] = $fullname ?: ($row['requester_uname'] ?? __('Unknown'));
                $results[] = $row;
            }
        }
        return $results;
    }

    /**
     * Search in documents
     */
    public function searchDocuments()
    {
        global $DB;

        if (!Document::canView()) {
            return [];
        }

        // Get entity restrictions using standard GLPI methods
        $entity_criteria = $this->getEntityRestrictCriteria('Document', 'glpi_documents');

        $search_fields = ['glpi_documents.name', 'glpi_documents.filename', 'glpi_documents.comment'];

        if (is_numeric($this->raw_query)) {
            // ID-based criteria
            $id_criteria = ['glpi_documents.id' => $this->raw_query];

            if ($this->id_only) {
                // ID-only mode
                $where = $id_criteria;
            } else {
                // Content-based criteria
                $content_criteria = $this->getMultiWordCriteria($search_fields);

                // Combine both with OR
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
            'WHERE' => [
                'AND' => array_filter([
                    $where,
                    ['glpi_documents.is_deleted' => 0],
                    $entity_criteria
                ])
            ],
            'ORDER' => 'glpi_documents.date_mod DESC'
        ];

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            // Verify additional permissions
            $document = new Document();
            if ($document->can($row['id'], READ)) {
                $results[] = $row;
            }
        }
        return $results;
    }

    /**
     * Search in software
     */
    public function searchSoftware()
    {
        global $DB;

        if (!Software::canView()) {
            return [];
        }

        // Get entity restrictions using standard GLPI methods
        $entity_criteria = $this->getEntityRestrictCriteria('Software', 'glpi_softwares');

        $search_fields = ['glpi_softwares.name', 'glpi_softwares.comment'];

        if (is_numeric($this->raw_query)) {
            // ID-based criteria
            $id_criteria = ['glpi_softwares.id' => $this->raw_query];

            if ($this->id_only) {
                // ID-only mode
                $where = $id_criteria;
            } else {
                // Content-based criteria
                $content_criteria = $this->getMultiWordCriteria($search_fields);

                // Combine both with OR
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
            'WHERE' => [
                'AND' => array_filter([
                    $where,
                    ['glpi_softwares.is_deleted' => 0],
                    ['glpi_softwares.is_template' => 0],
                    $entity_criteria
                ])
            ],
            'ORDER' => 'glpi_softwares.date_mod DESC'
        ];

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            // Verify additional permissions
            $software = new Software();
            if ($software->can($row['id'], READ)) {
                $results[] = $row;
            }
        }
        return $results;
    }

    /**
     * Search in users
     */
    public function searchUsers()
    {
        global $DB;

        if (!User::canView()) {
            return [];
        }

        // Users don't have direct entity restrictions like other items,
        // but we must verify view permissions

        $search_fields = ['glpi_users.name', 'glpi_users.realname', 'glpi_users.firstname', 'glpi_users.phone', 'glpi_users.mobile'];

        if (is_numeric($this->raw_query)) {
            // ID-based criteria
            $id_criteria = ['glpi_users.id' => $this->raw_query];

            if ($this->id_only) {
                // ID-only mode
                $where = $id_criteria;
            } else {
                // Content-based criteria
                $content_criteria = $this->getMultiWordCriteria($search_fields);

                // Combine both with OR
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
            'WHERE' => [
                'AND' => array_filter([
                    $where,
                    ['glpi_users.is_deleted' => 0]
                ])
            ],
            'ORDER' => 'glpi_users.date_mod DESC'
        ];

        $iterator = $DB->request($criteria);
        $results = [];

        foreach ($iterator as $row) {
            // Verify additional permissions
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
     * Search in changes
     */
    public function searchChanges()
    {
        global $DB;

        if (!Change::canView()) {
            return [];
        }

        $entity_criteria = $this->getEntityRestrictCriteria('Change', 'glpi_changes');

        $search_fields = ['glpi_changes.name', 'glpi_changes.content'];

        if ($this->id_only) {
            $where = ['glpi_changes.id' => $this->raw_query];
        } else {
            $main_criteria = [];
            if (is_numeric($this->raw_query)) {
                $id_criteria = ['glpi_changes.id' => $this->raw_query];
                $content_criteria = $this->getMultiWordCriteria($search_fields);
                $main_criteria = !empty($content_criteria) ? ['OR' => [$id_criteria, $content_criteria]] : $id_criteria;
            } elseif (mb_strlen($this->raw_query) >= 3) {
                $main_criteria = $this->getMultiWordCriteria($search_fields);
            } else {
                return [];
            }

            $where = $main_criteria;
        }

        $common_where = [
            'AND' => array_filter([
                $where,
                $entity_criteria,
                ['glpi_changes.is_deleted' => 0]
            ])
        ];

        $iterator = $DB->request([
            'SELECT' => [
                'glpi_changes.id',
                'glpi_changes.name',
                'glpi_changes.status',
                'glpi_changes.entities_id',
                'glpi_changes.date',
                'glpi_changes.closedate',
                'glpi_changes.date_mod'
            ],
            'FROM' => 'glpi_changes',
            'WHERE' => $common_where,
            'ORDER' => 'glpi_changes.date_mod DESC'
        ]);

        $changes = [];
        $change_ids = [];
        $change_obj = new Change();

        foreach ($iterator as $row) {
            if ($change_obj->can($row['id'], READ)) {
                $row['status_name'] = Change::getStatus($row['status']);
                $row['tech_name'] = __('Not assigned');
                $row['requester_name'] = __('Not assigned');
                $changes[$row['id']] = $row;
                $change_ids[] = $row['id'];
            }
        }

        // Bulk load technicians
        if (!empty($change_ids)) {
            $tech_iter = $DB->request([
                'SELECT' => [
                    'glpi_changes_users.changes_id',
                    'glpi_users.firstname',
                    'glpi_users.realname',
                    'glpi_users.name AS uname'
                ],
                'FROM' => 'glpi_changes_users',
                'INNER JOIN' => [
                    'glpi_users' => [
                        'ON' => ['glpi_changes_users' => 'users_id', 'glpi_users' => 'id']
                    ]
                ],
                'WHERE' => [
                    'glpi_changes_users.changes_id' => $change_ids,
                    'glpi_changes_users.type' => 2 // Assigned
                ]
            ]);

            $techs_by_change = [];
            foreach ($tech_iter as $tech_row) {
                $cid = $tech_row['changes_id'];
                $fullname = trim($tech_row['firstname'] . ' ' . $tech_row['realname']);
                if (empty($fullname)) {
                    $fullname = $tech_row['uname'];
                }
                $techs_by_change[$cid][] = $fullname;
            }

            foreach ($techs_by_change as $cid => $names) {
                if (isset($changes[$cid])) {
                    $changes[$cid]['tech_name'] = implode(', ', $names);
                }
            }
        }

        // Bulk load requesters
        if (!empty($change_ids)) {
            $requester_iter = $DB->request([
                'SELECT' => [
                    'glpi_changes_users.changes_id',
                    'glpi_users.firstname',
                    'glpi_users.realname',
                    'glpi_users.name AS uname'
                ],
                'FROM' => 'glpi_changes_users',
                'INNER JOIN' => [
                    'glpi_users' => [
                        'ON' => ['glpi_changes_users' => 'users_id', 'glpi_users' => 'id']
                    ]
                ],
                'WHERE' => [
                    'glpi_changes_users.changes_id' => $change_ids,
                    'glpi_changes_users.type' => 1 // Requester
                ]
            ]);

            $requesters_by_change = [];
            foreach ($requester_iter as $req_row) {
                $cid = $req_row['changes_id'];
                $fullname = trim($req_row['firstname'] . ' ' . $req_row['realname']);
                if (empty($fullname)) {
                    $fullname = $req_row['uname'];
                }
                $requesters_by_change[$cid][] = $fullname;
            }

            foreach ($requesters_by_change as $cid => $names) {
                if (isset($changes[$cid])) {
                    $changes[$cid]['requester_name'] = implode(', ', $names);
                }
            }
        }

        return array_values($changes);
    }

    /**
     * Search in ticket tasks
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

        // Get entity restrictions for tickets (tasks are linked to tickets)
        $entity_criteria = $this->getEntityRestrictCriteria('Ticket', 'glpi_tickets');

        // Fields to search in
        $search_fields = ['glpi_tickettasks.content'];

        // Build content search criteria
        $content_criteria = $this->getMultiWordCriteria($search_fields);

        // If numeric, also search by task ID or ticket ID
        if (is_numeric($this->raw_query)) {
            $id_criteria = [
                'OR' => [
                    'glpi_tickettasks.id' => $this->raw_query,
                    'glpi_tickettasks.tickets_id' => $this->raw_query
                ]
            ];

            if ($this->id_only) {
                $where_criteria = $id_criteria;
            } else {
                $where_criteria = !empty($content_criteria) ? ['OR' => [$content_criteria, $id_criteria]] : $id_criteria;
            }
        } else {
            if (mb_strlen($this->raw_query) < 3) {
                return [];
            }
            $where_criteria = $content_criteria;
        }

        // Apply permission restrictions for private tasks
        $user_id = Session::getLoginUserID();
        $private_criteria = [
            'OR' => [
                'glpi_tickettasks.is_private' => 0,
                'glpi_tickettasks.users_id' => $user_id
            ]
        ];

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
            'FROM' => 'glpi_tickettasks',
            'INNER JOIN' => [
                'glpi_tickets' => [
                    'ON' => [
                        'glpi_tickettasks' => 'tickets_id',
                        'glpi_tickets' => 'id'
                    ]
                ]
            ],
            'WHERE' => [
                'AND' => array_filter([
                    $where_criteria,
                    $entity_criteria,
                    ['glpi_tickets.is_deleted' => 0],
                    $private_criteria
                ])
            ],
            'ORDER' => 'glpi_tickettasks.date_mod DESC'
        ];

        $iterator = $DB->request($criteria);
        $tasks = [];
        $ticket_ids = [];
        $task_obj = new TicketTask();

        foreach ($iterator as $row) {
            // Verify permissions with can()
            if ($task_obj->can($row['id'], READ)) {
                $row['requester_name'] = __('Unknown'); // Initial value
                $tasks[$row['id']] = $row;
                $ticket_ids[] = $row['tickets_id'];
            }
        }

        // Bulk load requesters
        if (!empty($ticket_ids)) {
            $ticket_ids = array_unique($ticket_ids);
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

            foreach ($tasks as &$task) {
                $tid = $task['tickets_id'];
                if (isset($requesters_by_ticket[$tid])) {
                    $task['requester_name'] = implode(', ', $requesters_by_ticket[$tid]);
                }
            }
        }

        return array_values($tasks);
    }

    /**
     * Search in project tasks
     */
    public function searchProjectTasks()
    {
        global $DB;

        if (!ProjectTask::canView()) {
            return [];
        }

        // Get entity restrictions using standard GLPI methods
        $entity_criteria = $this->getEntityRestrictCriteria('ProjectTask', 'glpi_projecttasks');

        $has_private_field = $DB->fieldExists('glpi_projecttasks', 'is_private');

        $search_fields = ['glpi_projecttasks.name', 'glpi_projecttasks.content', 'glpi_projecttasks.comment'];

        if (is_numeric($this->raw_query)) {
            // ID-based criteria
            $id_criteria = ['glpi_projecttasks.id' => $this->raw_query];

            if ($this->id_only) {
                // ID-only mode
                $where = $id_criteria;
            } else {
                // Content-based criteria
                $content_criteria = $this->getMultiWordCriteria($search_fields);

                // Combine both with OR
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
            'WHERE' => [
                'AND' => array_filter([
                    $where,
                    ['glpi_projecttasks.is_template' => 0],
                    $entity_criteria,
                    $has_private_field ? [
                        'OR' => [
                            'glpi_projecttasks.is_private' => 0,
                            'glpi_projecttasks.users_id' => Session::getLoginUserID()
                        ]
                    ] : []
                ])
            ],
            'ORDER' => 'glpi_projecttasks.date_mod DESC'
        ];

        $iterator = $DB->request($criteria);
        $results = [];
        foreach ($iterator as $row) {
            // Verify permissions: can() already checks private tasks and other permissions
            $projecttask = new ProjectTask();
            if ($projecttask->can($row['id'], READ)) {
                // Build requester name
                $fullname = trim(($row['requester_firstname'] ?? '') . ' ' . ($row['requester_realname'] ?? ''));
                $row['requester_name'] = $fullname ?: ($row['requester_uname'] ?? __('Unknown'));
                $results[] = $row;
            }
        }
        return $results;
    }
}
