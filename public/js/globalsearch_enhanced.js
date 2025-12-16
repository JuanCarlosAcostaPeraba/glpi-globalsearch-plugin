/**
 * Global Search Enhanced - Paginación, Filtros, Resaltado y Gestión de Columnas
 */

(function () {
    'use strict';

    const ITEMS_PER_PAGE = 20;
    const STORAGE_PREFIX = 'globalsearch_columns_';

    /**
     * Inicializar todas las funcionalidades para cada tabla
     */
    function initAllTables() {
        try {
            const tables = document.querySelectorAll('.search-results-table');
            console.log('GlobalSearch: Found', tables.length, 'tables to initialize');

            tables.forEach(function (table) {
                try {
                    const tableId = table.getAttribute('id') || 'table-' + Math.random().toString(36).substr(2, 9);
                    table.setAttribute('id', tableId);

                    console.log('GlobalSearch: Initializing table', tableId);
                    initPagination(table);
                    initFilters(table);
                    initColumnToggle(table);
                    applyHighlight(table);
                    console.log('GlobalSearch: Table', tableId, 'initialized successfully');
                } catch (e) {
                    console.error('GlobalSearch: Error initializing table', e);
                }
            });
        } catch (e) {
            console.error('GlobalSearch: Error in initAllTables', e);
        }
    }

    /**
     * Paginación del lado del cliente
     */
    function initPagination(table) {
        const tbody = table.querySelector('tbody');
        if (!tbody) {
            console.log('GlobalSearch: No tbody found for pagination');
            return;
        }

        // Obtener todas las filas de datos
        let allRows = Array.from(tbody.querySelectorAll('tr'));
        console.log('GlobalSearch: Pagination - All rows found:', allRows.length);

        // Filtrar filas que tienen celdas y no son mensajes
        let rows = allRows.filter(function (row) {
            const cells = row.querySelectorAll('td');
            const hasCells = cells.length > 0;
            const isNoResults = row.classList.contains('no-results') ||
                row.textContent.trim().toLowerCase().includes('no results');
            return hasCells && !isNoResults;
        });

        const totalRows = rows.length;
        console.log('GlobalSearch: Pagination - Valid rows:', totalRows);

        if (totalRows <= ITEMS_PER_PAGE) {
            console.log('GlobalSearch: No pagination needed (rows <=', ITEMS_PER_PAGE, ')');
            return; // No necesita paginación
        }

        const card = table.closest('.card');
        if (!card) return;

        // Crear controles de paginación si no existen
        let pagination = card.querySelector('.search-pagination');
        if (!pagination) {
            console.log('GlobalSearch: Creating pagination controls');
            const cardBody = card.querySelector('.card-body');
            pagination = document.createElement('div');
            pagination.className = 'card-footer d-flex justify-content-between align-items-center bg-light search-pagination';
            pagination.innerHTML = `
                <small class="text-muted search-pagination-info"></small>
                <div>
                    <button class="btn btn-sm btn-outline-primary search-pagination-prev" disabled>
                        <i class="fas fa-chevron-left"></i> Previous
                    </button>
                    <button class="btn btn-sm btn-outline-primary ms-1 search-pagination-next">
                        Next <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            `;
            card.appendChild(pagination);
            console.log('GlobalSearch: Pagination controls created');
        }

        const paginationInfo = pagination.querySelector('.search-pagination-info');
        const prevBtn = pagination.querySelector('.search-pagination-prev');
        const nextBtn = pagination.querySelector('.search-pagination-next');

        let currentPage = 0;
        const totalPages = Math.ceil(totalRows / ITEMS_PER_PAGE);

        function showPage(page) {
            const start = page * ITEMS_PER_PAGE;
            const end = Math.min(start + ITEMS_PER_PAGE, totalRows);

            rows.forEach(function (row, index) {
                if (index >= start && index < end) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });

            // Actualizar información
            const startNum = start + 1;
            const endNum = end;
            paginationInfo.textContent = `Showing ${startNum} - ${endNum} of ${totalRows}`;

            // Actualizar botones
            prevBtn.disabled = (page === 0);
            nextBtn.disabled = (page >= totalPages - 1);

            currentPage = page;

            // Re-aplicar resaltado después de cambiar de página
            applyHighlight(table);
        }

        prevBtn.addEventListener('click', function () {
            if (currentPage > 0) {
                showPage(currentPage - 1);
            }
        });

        nextBtn.addEventListener('click', function () {
            if (currentPage < totalPages - 1) {
                showPage(currentPage + 1);
            }
        });

        // Inicializar primera página
        showPage(0);

        // Guardar referencia para filtros
        table._pagination = {
            showPage: showPage,
            getCurrentPage: () => currentPage,
            getTotalPages: () => totalPages,
            getRows: () => rows
        };
    }

    /**
     * Filtros de tabla
     */
    function initFilters(table) {
        const thead = table.querySelector('thead');
        if (!thead) return;

        const headerRow = thead.querySelector('tr');
        if (!headerRow) return;

        // Crear fila de filtros
        const filterRow = document.createElement('tr');
        filterRow.className = 'table-filters';
        const headers = headerRow.querySelectorAll('th');

        headers.forEach(function (th, index) {
            const filterCell = document.createElement('td');
            const columnText = th.textContent.trim().toLowerCase();

            // Input de texto para la mayoría de columnas
            if (columnText.includes('status')) {
                // Select para status
                const select = document.createElement('select');
                select.className = 'form-control form-control-sm filter-input';
                select.setAttribute('data-column-index', index);
                select.innerHTML = '<option value="">All</option>';
                // Obtener valores únicos de status de la tabla
                const statusValues = getUniqueColumnValues(table, index);
                statusValues.forEach(function (val) {
                    const option = document.createElement('option');
                    option.value = val;
                    option.textContent = val;
                    select.appendChild(option);
                });
                filterCell.appendChild(select);
            } else {
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-control form-control-sm filter-input';
                input.setAttribute('data-column-index', index);
                input.placeholder = 'Filter...';
                filterCell.appendChild(input);
            }

            filterRow.appendChild(filterCell);
        });

        thead.appendChild(filterRow);

        // Event listeners para filtros
        const filterInputs = filterRow.querySelectorAll('.filter-input');
        filterInputs.forEach(function (input) {
            input.addEventListener('input', function () {
                applyFilters(table);
            });
        });
    }

    /**
     * Aplicar filtros a la tabla
     */
    function applyFilters(table) {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const filterInputs = table.querySelectorAll('.filter-input');
        const rows = Array.from(tbody.querySelectorAll('tr:not(.no-results)'));

        rows.forEach(function (row) {
            let showRow = true;
            const cells = row.querySelectorAll('td');

            filterInputs.forEach(function (input) {
                const columnIndex = parseInt(input.getAttribute('data-column-index'));
                const filterValue = input.value.trim().toLowerCase();
                const cell = cells[columnIndex];

                if (filterValue && cell) {
                    const cellText = cell.textContent.trim().toLowerCase();
                    if (!cellText.includes(filterValue)) {
                        showRow = false;
                    }
                }
            });

            row.style.display = showRow ? '' : 'none';
        });

        // Re-aplicar paginación con filas filtradas
        if (table._pagination) {
            const visibleRows = rows.filter(r => r.style.display !== 'none');
            table._pagination.getRows = () => visibleRows;
            table._pagination.showPage(0);
        }

        // Re-aplicar resaltado
        applyHighlight(table);
    }

    /**
     * Obtener valores únicos de una columna
     */
    function getUniqueColumnValues(table, columnIndex) {
        const tbody = table.querySelector('tbody');
        if (!tbody) return [];

        const values = new Set();
        const rows = tbody.querySelectorAll('tr:not(.no-results)');

        rows.forEach(function (row) {
            const cell = row.querySelectorAll('td')[columnIndex];
            if (cell) {
                const value = cell.textContent.trim();
                if (value) {
                    values.add(value);
                }
            }
        });

        return Array.from(values).sort();
    }

    /**
     * Resaltar términos de búsqueda
     */
    function applyHighlight(table) {
        const searchQuery = getSearchQuery();
        if (!searchQuery) return;

        const terms = searchQuery.toLowerCase().split(/\s+/).filter(t => t.length > 0);
        if (terms.length === 0) return;

        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const rows = tbody.querySelectorAll('tr:not(.no-results)');
        rows.forEach(function (row) {
            if (row.style.display === 'none') return;

            const cells = row.querySelectorAll('td');
            cells.forEach(function (cell) {
                const originalText = cell.getAttribute('data-original-text') || cell.textContent;
                cell.setAttribute('data-original-text', originalText);

                let highlightedText = originalText;
                terms.forEach(function (term) {
                    const regex = new RegExp(`(${escapeRegex(term)})`, 'gi');
                    highlightedText = highlightedText.replace(regex, '<mark>$1</mark>');
                });

                cell.innerHTML = highlightedText;
            });
        });
    }

    /**
     * Obtener query de búsqueda desde la URL o input
     */
    function getSearchQuery() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('globalsearch') || '';
    }

    /**
     * Escapar caracteres especiales para regex
     */
    function escapeRegex(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Gestión de columnas visibles
     */
    function initColumnToggle(table) {
        const thead = table.querySelector('thead');
        if (!thead) return;

        const headerRow = thead.querySelector('tr');
        if (!headerRow) return;

        const tableId = table.getAttribute('id');
        const storageKey = STORAGE_PREFIX + tableId;

        // Cargar preferencias guardadas
        const savedPreferences = loadColumnPreferences(storageKey);

        // Añadir botón de gestión de columnas
        const card = table.closest('.card');
        if (card) {
            const cardHeader = card.querySelector('.card-header');
            if (cardHeader) {
                let columnToggleBtn = cardHeader.querySelector('.column-toggle-btn');
                if (!columnToggleBtn) {
                    // Contenedor para el dropdown
                    const dropdownContainer = document.createElement('div');
                    dropdownContainer.className = 'dropdown';

                    columnToggleBtn = document.createElement('button');
                    columnToggleBtn.className = 'btn btn-sm btn-outline-secondary column-toggle-btn';
                    columnToggleBtn.innerHTML = '<i class="fas fa-columns"></i> Columns';
                    columnToggleBtn.setAttribute('data-bs-toggle', 'dropdown');
                    columnToggleBtn.setAttribute('aria-expanded', 'false');

                    // Crear menú desplegable
                    const dropdown = document.createElement('div');
                    dropdown.className = 'dropdown-menu column-toggle-menu';
                    dropdown.setAttribute('aria-labelledby', 'columnToggle');

                    dropdownContainer.appendChild(columnToggleBtn);
                    dropdownContainer.appendChild(dropdown);
                    cardHeader.appendChild(dropdownContainer);

                    const headers = headerRow.querySelectorAll('th');
                    headers.forEach(function (th, index) {
                        const menuItem = document.createElement('label');
                        menuItem.className = 'dropdown-item';
                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.className = 'column-toggle-checkbox';
                        checkbox.setAttribute('data-column-index', index);
                        checkbox.checked = savedPreferences ? (savedPreferences[index] !== false) : true;

                        menuItem.appendChild(checkbox);
                        menuItem.appendChild(document.createTextNode(' ' + th.textContent.trim()));
                        menuItem.addEventListener('click', function (e) {
                            e.stopPropagation();
                        });

                        dropdown.appendChild(menuItem);
                    });

                    // Inicializar dropdown (Bootstrap o manual)
                    if (typeof bootstrap !== 'undefined') {
                        new bootstrap.Dropdown(columnToggleBtn);
                    } else {
                        // Fallback manual para dropdown
                        columnToggleBtn.addEventListener('click', function (e) {
                            e.preventDefault();
                            e.stopPropagation();
                            dropdown.classList.toggle('show');
                            dropdownContainer.classList.toggle('show');
                        });

                        // Cerrar al hacer clic fuera
                        document.addEventListener('click', function (e) {
                            if (!dropdownContainer.contains(e.target)) {
                                dropdown.classList.remove('show');
                                dropdownContainer.classList.remove('show');
                            }
                        });
                    }

                    // Event listeners para checkboxes
                    dropdown.querySelectorAll('.column-toggle-checkbox').forEach(function (checkbox) {
                        checkbox.addEventListener('change', function () {
                            const columnIndex = parseInt(this.getAttribute('data-column-index'));
                            const isVisible = this.checked;
                            toggleColumn(table, columnIndex, isVisible);
                            saveColumnPreferences(storageKey, table);
                        });
                    });
                }
            }
        }

        // Aplicar preferencias guardadas
        if (savedPreferences) {
            Object.keys(savedPreferences).forEach(function (index) {
                const isVisible = savedPreferences[index] !== false;
                toggleColumn(table, parseInt(index), isVisible);
            });
        }
    }

    /**
     * Mostrar/ocultar columna
     */
    function toggleColumn(table, columnIndex, isVisible) {
        const thead = table.querySelector('thead');
        const tbody = table.querySelector('tbody');
        if (!thead || !tbody) return;

        // Ocultar/mostrar header
        const headerCells = thead.querySelectorAll('th, .table-filters td');
        if (headerCells[columnIndex]) {
            headerCells[columnIndex].style.display = isVisible ? '' : 'none';
        }

        // Ocultar/mostrar celdas
        const rows = tbody.querySelectorAll('tr');
        rows.forEach(function (row) {
            const cells = row.querySelectorAll('td');
            if (cells[columnIndex]) {
                cells[columnIndex].style.display = isVisible ? '' : 'none';
            }
        });
    }

    /**
     * Guardar preferencias de columnas
     */
    function saveColumnPreferences(storageKey, table) {
        const preferences = {};
        const card = table.closest('.card');
        if (!card) return;

        const checkboxes = card.querySelectorAll('.column-toggle-checkbox');

        checkboxes.forEach(function (checkbox) {
            const index = parseInt(checkbox.getAttribute('data-column-index'));
            preferences[index] = checkbox.checked;
        });

        try {
            localStorage.setItem(storageKey, JSON.stringify(preferences));
        } catch (e) {
            console.warn('Could not save column preferences:', e);
        }
    }

    /**
     * Cargar preferencias de columnas
     */
    function loadColumnPreferences(storageKey) {
        try {
            const saved = localStorage.getItem(storageKey);
            return saved ? JSON.parse(saved) : null;
        } catch (e) {
            console.warn('Could not load column preferences:', e);
            return null;
        }
    }

    // Inicializar cuando el DOM esté listo
    function initialize() {
        console.log('GlobalSearch Enhanced: Initializing...');
        console.log('GlobalSearch Enhanced: Document ready state:', document.readyState);

        const tables = document.querySelectorAll('.search-results-table');
        console.log('GlobalSearch Enhanced: Found', tables.length, 'tables');

        if (tables.length === 0) {
            console.warn('GlobalSearch Enhanced: No tables found with class .search-results-table');
            console.log('GlobalSearch Enhanced: Available tables:', document.querySelectorAll('table').length);
            // Intentar de nuevo después de un delay
            setTimeout(function () {
                const retryTables = document.querySelectorAll('.search-results-table');
                console.log('GlobalSearch Enhanced: Retry - Found', retryTables.length, 'tables');
                if (retryTables.length > 0) {
                    initAllTables();
                }
            }, 500);
            return;
        }

        initAllTables();
        console.log('GlobalSearch Enhanced: Initialization complete');
    }

    // Esperar a que todo esté completamente cargado
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(initialize, 100);
        });
    } else if (document.readyState === 'interactive') {
        setTimeout(initialize, 100);
    } else {
        // DOM completamente cargado
        setTimeout(initialize, 100);
    }

    // También intentar cuando la ventana esté completamente cargada
    window.addEventListener('load', function () {
        setTimeout(initialize, 200);
    });

})();

