/**
 * Global Search Enhanced - Paginación, Filtros, Resaltado y Gestión de Columnas
 */

(function () {
    'use strict';

    const ITEMS_PER_PAGE = 20;
    const STORAGE_PREFIX = 'globalsearch_columns_';

    /**
     * Utilidades para cookies
     */
    function setCookie(name, value, days) {
        const expires = new Date();
        expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + expires.toUTCString() + ';path=/';
    }

    function getCookie(name) {
        const nameEQ = name + '=';
        const ca = document.cookie.split(';');
        for (let i = 0; i < ca.length; i++) {
            let c = ca[i];
            while (c.charAt(0) === ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) === 0) {
                return decodeURIComponent(c.substring(nameEQ.length, c.length));
            }
        }
        return null;
    }

    function deleteCookie(name) {
        document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;';
    }

    /**
     * Inicializar todas las funcionalidades para cada tabla
     */
    function initAllTables() {
        try {
            const tables = document.querySelectorAll('.search-results-table');

            tables.forEach(function (table) {
                try {
                    const tableId = table.getAttribute('id') || 'table-' + Math.random().toString(36).substr(2, 9);
                    table.setAttribute('id', tableId);

                    initPagination(table);
                    initFilters(table);
                    initColumnToggle(table);
                    initSorting(table);
                    applyHighlight(table);
                } catch (e) {
                    console.error('GlobalSearch: Error initializing table', e);
                }
            });
        } catch (e) {
            console.error('GlobalSearch: Error in initAllTables', e);
        }
    }

    /**
     * Inicializar ordenación de columnas
     */
    function initSorting(table) {
        const thead = table.querySelector('thead');
        if (!thead) return;

        const headerRow = thead.querySelector('tr:not(.table-filters):not(.table-filters-actions)');
        if (!headerRow) return;

        const headers = headerRow.querySelectorAll('th');
        headers.forEach(function (th, index) {
            th.addEventListener('click', function () {
                const currentOrder = th.classList.contains('sort-asc') ? 'desc' : 'asc';

                // Limpiar clases de ordenación de todos los headers
                headers.forEach(h => h.classList.remove('sort-asc', 'sort-desc'));

                // Aplicar clase al seleccionado
                th.classList.add('sort-' + currentOrder);

                sortRows(table, index, currentOrder);
            });
        });
    }

    /**
     * Ordenar filas de la tabla
     */
    function sortRows(table, columnIndex, order) {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        // Obtener todas las filas de datos válidas
        let allRows = Array.from(tbody.querySelectorAll('tr'));
        let rows = allRows.filter(function (row) {
            const cells = row.querySelectorAll('td');
            const hasCells = cells.length > 0;
            const isNoResults = row.classList.contains('no-results') ||
                row.textContent.trim().toLowerCase().includes('no results');
            return hasCells && !isNoResults;
        });

        const isDate = isDateColumn(table.querySelectorAll('thead th')[columnIndex].textContent, table, columnIndex);

        rows.sort(function (a, b) {
            const cellA = a.querySelectorAll('td')[columnIndex];
            const cellB = b.querySelectorAll('td')[columnIndex];

            let valA = getCellText(cellA).trim();
            let valB = getCellText(cellB).trim();

            let comparison = 0;

            if (isDate) {
                const dateA = parseDateIgnoreTime(valA) || new Date(0);
                const dateB = parseDateIgnoreTime(valB) || new Date(0);
                comparison = dateA - dateB;
            } else {
                // Comprobar si son IDs (formato #123)
                const idA = valA.startsWith('#') ? parseInt(valA.substring(1)) : NaN;
                const idB = valB.startsWith('#') ? parseInt(valB.substring(1)) : NaN;

                if (!isNaN(idA) && !isNaN(idB)) {
                    comparison = idA - idB;
                } else {
                    // Ordenación alfabética normal
                    comparison = valA.localeCompare(valB, undefined, { numeric: true, sensitivity: 'base' });
                }
            }

            return order === 'asc' ? comparison : -comparison;
        });

        // Re-insertar filas ordenadas en el DOM
        rows.forEach(row => tbody.appendChild(row));

        // Actualizar paginación si existe
        if (table._pagination) {
            // Si hay filtros activos, usar la lógica de filtros para obtener las filas visibles
            const activeFilters = table.querySelectorAll('.filter-input');
            let hasActiveFilter = false;
            activeFilters.forEach(input => {
                if (input.value && input.value.trim() !== '') hasActiveFilter = true;
            });

            if (hasActiveFilter) {
                // Re-aplicar filtros para mantener el estado
                applyFilters(table);
            } else {
                // Solo actualizar las filas originales y mostrar primera página
                table._pagination.getRows = () => rows;
                table._pagination.showPage(0);
            }
        }

        // Re-aplicar resaltado
        applyHighlight(table);
    }

    /**
     * Paginación del lado del cliente
     */
    function initPagination(table) {
        const tbody = table.querySelector('tbody');
        if (!tbody) {
            return;
        }

        // Obtener todas las filas de datos
        let allRows = Array.from(tbody.querySelectorAll('tr'));

        // Filtrar filas que tienen celdas y no son mensajes
        let rows = allRows.filter(function (row) {
            const cells = row.querySelectorAll('td');
            const hasCells = cells.length > 0;
            const isNoResults = row.classList.contains('no-results') ||
                row.textContent.trim().toLowerCase().includes('no results');
            return hasCells && !isNoResults;
        });

        const totalRows = rows.length;

        if (totalRows <= ITEMS_PER_PAGE) {
            return; // No necesita paginación
        }

        const card = table.closest('.card');
        if (!card) return;

        // Crear controles de paginación si no existen
        let pagination = card.querySelector('.search-pagination');
        if (!pagination) {
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
        }

        const paginationInfo = pagination.querySelector('.search-pagination-info');
        const prevBtn = pagination.querySelector('.search-pagination-prev');
        const nextBtn = pagination.querySelector('.search-pagination-next');

        let currentPage = 0;
        const totalPages = Math.ceil(totalRows / ITEMS_PER_PAGE);

        function showPage(page) {
            // Obtener las filas actuales (pueden estar filtradas)
            const currentRows = table._pagination ? table._pagination.getRows() : rows;
            const currentTotalRows = currentRows.length;
            const currentTotalPages = Math.ceil(currentTotalRows / ITEMS_PER_PAGE);

            const start = page * ITEMS_PER_PAGE;
            const end = Math.min(start + ITEMS_PER_PAGE, currentTotalRows);

            // Primero ocultar todas las filas
            rows.forEach(function (row) {
                row.style.display = 'none';
            });

            // Luego mostrar solo las filas de la página actual que están en currentRows
            currentRows.forEach(function (row, index) {
                if (index >= start && index < end) {
                    row.style.display = '';
                }
            });

            // Actualizar información
            const startNum = currentTotalRows > 0 ? start + 1 : 0;
            const endNum = end;
            paginationInfo.textContent = `Showing ${startNum} - ${endNum} of ${currentTotalRows}`;

            // Actualizar botones
            prevBtn.disabled = (page === 0);
            nextBtn.disabled = (page >= currentTotalPages - 1);

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
            getTotalPages: () => Math.ceil((table._pagination.getRows().length) / ITEMS_PER_PAGE),
            getRows: () => rows, // Filas originales sin filtrar
            getFilteredRows: () => rows.filter(r => r.style.display !== 'none' && r.offsetParent !== null) // Filas visibles después de filtros
        };
    }

    /**
     * Detectar si una columna es de tipo fecha
     */
    function isDateColumn(headerText, table, columnIndex) {
        const text = headerText.trim().toLowerCase();

        // Excluir explícitamente columnas de ID
        const excludeKeywords = ['id', 'identificador'];
        const hasExcludeKeyword = excludeKeywords.some(function (keyword) {
            return text === keyword || text === keyword + 's';
        });

        if (hasExcludeKeyword) {
            return false;
        }

        const dateKeywords = ['date', 'fecha', 'update', 'actualización', 'created', 'creado', 'modified', 'modificado', 'time', 'tiempo'];

        // Verificar por palabras clave en el header
        const hasDateKeyword = dateKeywords.some(function (keyword) {
            return text.includes(keyword);
        });

        // Si el header tiene palabras clave de fecha, verificar el contenido para confirmar
        if (hasDateKeyword) {
            const tbody = table.querySelector('tbody');
            if (tbody) {
                const sampleRows = Array.from(tbody.querySelectorAll('tr:not(.no-results)')).slice(0, 5);
                if (sampleRows.length === 0) {
                    return true; // Si no hay filas, confiar en el header
                }

                let dateCount = 0;
                let totalSamples = 0;

                sampleRows.forEach(function (row) {
                    const cells = row.querySelectorAll('td');
                    if (cells[columnIndex]) {
                        const cellText = getCellText(cells[columnIndex]).trim();
                        // Ignorar valores que son solo números (probablemente IDs)
                        if (cellText && !/^\d+$/.test(cellText)) {
                            totalSamples++;
                            if (parseDateIgnoreTime(cellText) !== null) {
                                dateCount++;
                            }
                        }
                    }
                });

                // Si hay muestras válidas y al menos la mitad son fechas, confirmar como fecha
                if (totalSamples > 0 && dateCount >= Math.ceil(totalSamples / 2)) {
                    return true;
                }
                // Si no hay muestras válidas pero el header tiene palabra clave de fecha, confiar en el header
                return totalSamples === 0;
            }
            return true; // Si no hay tbody, confiar en el header
        }

        // Si el header NO tiene palabras clave de fecha, no considerar la columna como fecha
        // (evita falsos positivos cuando valores numéricos coinciden casualmente con formatos de fecha)
        return false;
    }

    /**
     * Parsear fecha ignorando horas (solo año, mes y día)
     */
    function parseDateIgnoreTime(cellText) {
        if (!cellText || cellText.trim() === '') {
            return null;
        }

        const text = cellText.trim();
        let date = null;

        // Intentar múltiples formatos de fecha comunes
        // Formato YYYY-MM-DD (ISO)
        if (/^\d{4}-\d{2}-\d{2}/.test(text)) {
            const match = text.match(/^(\d{4})-(\d{2})-(\d{2})/);
            if (match) {
                date = new Date(parseInt(match[1]), parseInt(match[2]) - 1, parseInt(match[3]));
                if (!isNaN(date.getTime())) {
                    return date;
                }
            }
        }

        // Formato DD/MM/YYYY
        if (/^\d{2}\/\d{2}\/\d{4}/.test(text)) {
            const match = text.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
            if (match) {
                date = new Date(parseInt(match[3]), parseInt(match[2]) - 1, parseInt(match[1]));
                if (!isNaN(date.getTime())) {
                    return date;
                }
            }
        }

        // Formato MM/DD/YYYY
        if (/^\d{2}\/\d{2}\/\d{4}/.test(text)) {
            const match = text.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
            if (match) {
                date = new Date(parseInt(match[3]), parseInt(match[1]) - 1, parseInt(match[2]));
                if (!isNaN(date.getTime())) {
                    return date;
                }
            }
        }

        // Intentar parseo directo con Date (puede manejar varios formatos)
        date = new Date(text);
        if (!isNaN(date.getTime())) {
            // Normalizar a medianoche para ignorar horas
            date.setHours(0, 0, 0, 0);
            return date;
        }

        return null;
    }

    /**
     * Normalizar fecha a medianoche (00:00:00) para comparación
     */
    function normalizeDateToMidnight(date) {
        if (!date || !(date instanceof Date)) {
            return null;
        }
        const normalized = new Date(date);
        normalized.setHours(0, 0, 0, 0);
        return normalized;
    }

    /**
     * Filtros de tabla
     */
    function initFilters(table) {
        const thead = table.querySelector('thead');
        if (!thead) return;

        const headerRow = thead.querySelector('tr');
        if (!headerRow) return;

        // Verificar si ya existe una fila de filtros
        const existingFilterRow = thead.querySelector('.table-filters');
        if (existingFilterRow) {
            return; // Ya existe, no crear otra
        }

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
            } else if (isDateColumn(th.textContent, table, index)) {
                // Contenedor para rango de fechas
                const dateRangeContainer = document.createElement('div');
                dateRangeContainer.className = 'filter-date-range';
                dateRangeContainer.setAttribute('data-column-index', index);

                // Input "Desde"
                const fromLabel = document.createElement('label');
                fromLabel.className = 'filter-date-label';
                fromLabel.textContent = 'Desde:';
                fromLabel.setAttribute('for', 'filter-date-from-' + index);

                const fromInput = document.createElement('input');
                fromInput.type = 'date';
                fromInput.className = 'form-control form-control-sm filter-input filter-date-input';
                fromInput.id = 'filter-date-from-' + index;
                fromInput.setAttribute('data-column-index', index);
                fromInput.setAttribute('data-date-type', 'from');

                // Input "Hasta"
                const toLabel = document.createElement('label');
                toLabel.className = 'filter-date-label';
                toLabel.textContent = 'Hasta:';
                toLabel.setAttribute('for', 'filter-date-to-' + index);

                const toInput = document.createElement('input');
                toInput.type = 'date';
                toInput.className = 'form-control form-control-sm filter-input filter-date-input';
                toInput.id = 'filter-date-to-' + index;
                toInput.setAttribute('data-column-index', index);
                toInput.setAttribute('data-date-type', 'to');

                dateRangeContainer.appendChild(fromLabel);
                dateRangeContainer.appendChild(fromInput);
                dateRangeContainer.appendChild(toLabel);
                dateRangeContainer.appendChild(toInput);

                filterCell.appendChild(dateRangeContainer);
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

        // Añadir celda con botón "Aplicar filtros"
        const buttonCell = document.createElement('td');
        buttonCell.colSpan = headers.length;
        buttonCell.className = 'text-end';
        buttonCell.style.padding = '0.5rem';

        const applyButton = document.createElement('button');
        applyButton.type = 'button';
        applyButton.className = 'btn btn-sm btn-primary filter-apply-btn';
        applyButton.innerHTML = '<i class="fas fa-filter"></i> Apply Filters';
        applyButton.setAttribute('data-table-id', table.getAttribute('id'));

        const clearButton = document.createElement('button');
        clearButton.type = 'button';
        clearButton.className = 'btn btn-sm btn-outline-secondary filter-clear-btn ms-2';
        clearButton.innerHTML = '<i class="fas fa-times"></i> Clear';
        clearButton.setAttribute('data-table-id', table.getAttribute('id'));

        buttonCell.appendChild(applyButton);
        buttonCell.appendChild(clearButton);

        // Crear segunda fila para el botón
        const buttonRow = document.createElement('tr');
        buttonRow.className = 'table-filters-actions';
        buttonRow.appendChild(buttonCell);

        thead.appendChild(filterRow);
        thead.appendChild(buttonRow);

        // Event listeners para botones
        applyButton.addEventListener('click', function () {
            applyFilters(table);
        });

        clearButton.addEventListener('click', function () {
            clearFilters(table);
        });

        // Permitir Enter en los inputs para aplicar filtros
        const filterInputs = filterRow.querySelectorAll('.filter-input');
        filterInputs.forEach(function (input) {
            input.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    applyFilters(table);
                }
            });
        });

        // Para inputs de fecha, aplicar filtros automáticamente al cambiar
        const dateInputs = filterRow.querySelectorAll('.filter-date-input');
        dateInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                applyFilters(table);
            });
        });
    }

    /**
     * Obtener texto de una celda (sin HTML, solo texto)
     */
    function getCellText(cell) {
        if (!cell) return '';

        // Si hay HTML original guardado, usar ese texto
        const originalHTML = cell.getAttribute('data-original-html');
        if (originalHTML) {
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = originalHTML;
            return tempDiv.textContent || tempDiv.innerText || '';
        }

        // Si no, usar textContent (que ignora HTML)
        return cell.textContent || cell.innerText || '';
    }

    /**
     * Aplicar filtros a la tabla
     */
    function applyFilters(table) {
        const tbody = table.querySelector('tbody');
        if (!tbody) {
            return;
        }

        const allFilterInputs = table.querySelectorAll('.filter-input');
        if (allFilterInputs.length === 0) {
            return;
        }

        // Recopilar filtros activos (incluyendo rangos de fechas)
        const activeFilters = [];

        // Agrupar inputs de fecha por columna
        const dateFiltersByColumn = {};
        const dateRangeContainers = table.querySelectorAll('.filter-date-range');
        dateRangeContainers.forEach(function (container) {
            const columnIndex = parseInt(container.getAttribute('data-column-index'));
            const fromInput = container.querySelector('input[data-date-type="from"]');
            const toInput = container.querySelector('input[data-date-type="to"]');

            const cell = container.closest('td');
            if (cell && (cell.offsetParent === null || getComputedStyle(cell).display === 'none')) {
                return; // La columna está oculta
            }

            const fromValue = fromInput ? fromInput.value.trim() : '';
            const toValue = toInput ? toInput.value.trim() : '';

            // Si al menos uno de los dos tiene valor, considerar el filtro activo
            if (fromValue || toValue) {
                dateFiltersByColumn[columnIndex] = {
                    type: 'date',
                    from: fromValue,
                    to: toValue,
                    columnIndex: columnIndex
                };
            }
        });

        // Recopilar filtros de texto/select normales
        Array.from(allFilterInputs).forEach(function (input) {
            if (input.disabled) return;

            // Ignorar inputs de fecha (ya los procesamos arriba)
            if (input.classList.contains('filter-date-input')) {
                return;
            }

            if (!input.value || input.value.trim() === '') return;

            const cell = input.closest('td');
            if (cell && (cell.offsetParent === null || getComputedStyle(cell).display === 'none')) {
                // La columna está oculta, no aplicar este filtro
                return;
            }

            activeFilters.push({
                type: 'text',
                input: input,
                columnIndex: parseInt(input.getAttribute('data-column-index')),
                value: input.value.trim()
            });
        });

        // Combinar todos los filtros activos
        const allActiveFilters = activeFilters.concat(Object.values(dateFiltersByColumn));

        // Si no hay filtros activos, mostrar todas las filas y restaurar paginación
        if (allActiveFilters.length === 0) {
            // Obtener todas las filas válidas
            let allRows = Array.from(tbody.querySelectorAll('tr'));
            let rows = allRows.filter(function (row) {
                const cells = row.querySelectorAll('td');
                const hasCells = cells.length > 0;
                const isNoResults = row.classList.contains('no-results') ||
                    row.textContent.trim().toLowerCase().includes('no results');
                return hasCells && !isNoResults;
            });

            rows.forEach(function (row) {
                row.style.display = '';
            });

            if (table._pagination) {
                const card = table.closest('.card');
                const pagination = card ? card.querySelector('.search-pagination') : null;
                if (pagination) {
                    pagination.style.display = '';
                }
                table._pagination.getRows = () => rows;
                table._pagination.showPage(0);
            }

            applyHighlight(table);
            return;
        }

        // Obtener todas las filas válidas (sin contar mensajes de "no results")
        let allRows = Array.from(tbody.querySelectorAll('tr'));
        let rows = allRows.filter(function (row) {
            const cells = row.querySelectorAll('td');
            const hasCells = cells.length > 0;
            const isNoResults = row.classList.contains('no-results') ||
                row.textContent.trim().toLowerCase().includes('no results');
            return hasCells && !isNoResults;
        });

        // Aplicar filtros
        const filteredRows = [];
        rows.forEach(function (row) {
            let showRow = true;
            const cells = row.querySelectorAll('td');

            allActiveFilters.forEach(function (filter) {
                const columnIndex = filter.columnIndex;
                const cell = cells[columnIndex];

                if (!cell) {
                    showRow = false;
                    return;
                }

                if (filter.type === 'date') {
                    // Filtrado por rango de fechas
                    const cellText = getCellText(cell).trim();
                    const cellDate = parseDateIgnoreTime(cellText);

                    if (cellDate === null) {
                        // Si la celda no contiene una fecha válida, ocultar la fila
                        showRow = false;
                        return;
                    }

                    const normalizedCellDate = normalizeDateToMidnight(cellDate);

                    // Aplicar lógica de rango
                    if (filter.from) {
                        const fromDate = normalizeDateToMidnight(new Date(filter.from));
                        if (normalizedCellDate < fromDate) {
                            showRow = false;
                            return;
                        }
                    }

                    if (filter.to) {
                        const toDate = normalizeDateToMidnight(new Date(filter.to));
                        if (normalizedCellDate > toDate) {
                            showRow = false;
                            return;
                        }
                    }
                } else {
                    // Filtrado de texto normal
                    const filterValue = filter.value.toLowerCase();
                    const cellText = getCellText(cell).trim().toLowerCase();
                    if (!cellText.includes(filterValue)) {
                        showRow = false;
                    }
                }
            });

            // Guardar el estado del filtro en un atributo data
            row.setAttribute('data-filter-visible', showRow ? 'true' : 'false');

            if (showRow) {
                filteredRows.push(row);
            }
        });

        // Re-aplicar paginación con filas filtradas
        if (table._pagination) {
            // Actualizar la función getRows para devolver solo las filas filtradas
            table._pagination.getRows = () => filteredRows;

            if (filteredRows.length > 0) {
                // Mostrar paginación si estaba oculta
                const pagination = table.closest('.card').querySelector('.search-pagination');
                if (pagination) {
                    pagination.style.display = '';
                }
                // Resetear a la primera página con las filas filtradas
                table._pagination.showPage(0);
            } else {
                // Si no hay filas visibles, ocultar paginación
                const pagination = table.closest('.card').querySelector('.search-pagination');
                if (pagination) {
                    pagination.style.display = 'none';
                }
                // Ocultar todas las filas
                rows.forEach(function (row) {
                    row.style.display = 'none';
                });
            }
        }

        // Re-aplicar resaltado solo a filas visibles
        applyHighlight(table);
    }

    /**
     * Limpiar filtros
     */
    function clearFilters(table) {
        const filterInputs = table.querySelectorAll('.filter-input');
        filterInputs.forEach(function (input) {
            input.value = '';
        });

        // Obtener todas las filas originales
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        let allRows = Array.from(tbody.querySelectorAll('tr'));
        let rows = allRows.filter(function (row) {
            const cells = row.querySelectorAll('td');
            const hasCells = cells.length > 0;
            const isNoResults = row.classList.contains('no-results') ||
                row.textContent.trim().toLowerCase().includes('no results');
            return hasCells && !isNoResults;
        });

        // Remover atributos de filtro
        rows.forEach(function (row) {
            row.removeAttribute('data-filter-visible');
        });

        // Restaurar paginación original
        if (table._pagination) {
            const card = table.closest('.card');
            const pagination = card ? card.querySelector('.search-pagination') : null;
            if (pagination) {
                pagination.style.display = '';
            }
            // Restaurar lista original de filas
            table._pagination.getRows = () => rows;
            // Mostrar primera página con todas las filas
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
     * Resaltar términos de búsqueda preservando enlaces HTML
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
                // Guardar HTML original si no está guardado
                if (!cell.hasAttribute('data-original-html')) {
                    cell.setAttribute('data-original-html', cell.innerHTML);
                }

                // Obtener HTML original
                const originalHTML = cell.getAttribute('data-original-html');

                // Crear un elemento temporal para trabajar con el HTML
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = originalHTML;

                // Función recursiva para resaltar texto en nodos
                function highlightTextNodes(node) {
                    if (node.nodeType === Node.TEXT_NODE) {
                        // Es un nodo de texto, aplicar resaltado
                        let text = node.textContent;
                        let highlightedText = text;

                        terms.forEach(function (term) {
                            const regex = new RegExp(`(${escapeRegex(term)})`, 'gi');
                            highlightedText = highlightedText.replace(regex, '<mark>$1</mark>');
                        });

                        // Si hay cambios, reemplazar el nodo de texto
                        if (highlightedText !== text) {
                            const tempSpan = document.createElement('span');
                            tempSpan.innerHTML = highlightedText;

                            // Reemplazar el nodo de texto con los nodos del span
                            while (tempSpan.firstChild) {
                                node.parentNode.insertBefore(tempSpan.firstChild, node);
                            }
                            node.parentNode.removeChild(node);
                        }
                    } else if (node.nodeType === Node.ELEMENT_NODE) {
                        // Es un elemento, procesar sus hijos recursivamente
                        // No procesar nodos <mark> para evitar anidación
                        if (node.tagName !== 'MARK') {
                            const children = Array.from(node.childNodes);
                            children.forEach(highlightTextNodes);
                        }
                    }
                }

                // Procesar todos los nodos
                const allNodes = Array.from(tempDiv.childNodes);
                allNodes.forEach(highlightTextNodes);

                // Restaurar el HTML procesado
                cell.innerHTML = tempDiv.innerHTML;
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

        // Ocultar/mostrar header (solo los <th>)
        const headerCells = thead.querySelectorAll('th');
        if (headerCells[columnIndex]) {
            headerCells[columnIndex].style.display = isVisible ? '' : 'none';
        }

        // Ocultar/mostrar celda de filtro correspondiente
        const filterRow = thead.querySelector('tr.table-filters');
        if (filterRow) {
            const filterCells = filterRow.querySelectorAll('td');
            const filterCell = filterCells[columnIndex];
            if (filterCell) {
                filterCell.style.display = isVisible ? '' : 'none';

                // Manejar inputs de fecha (rango)
                const dateRangeContainer = filterCell.querySelector('.filter-date-range');
                if (dateRangeContainer) {
                    const dateInputs = dateRangeContainer.querySelectorAll('.filter-date-input');
                    dateInputs.forEach(function (input) {
                        if (isVisible) {
                            input.disabled = false;
                        } else {
                            input.value = '';
                            input.disabled = true;
                        }
                    });
                } else {
                    // Manejar inputs normales (texto o select)
                    const filterInput = filterCell.querySelector('.filter-input');
                    if (filterInput) {
                        if (isVisible) {
                            // Volver a habilitar filtro cuando la columna se muestra
                            filterInput.disabled = false;
                        } else {
                            // Limpiar y deshabilitar filtro cuando la columna se oculta
                            filterInput.value = '';
                            filterInput.disabled = true;
                        }
                    }
                }
            }
        }

        // Ocultar/mostrar celdas de datos
        const rows = tbody.querySelectorAll('tr');
        rows.forEach(function (row) {
            const cells = row.querySelectorAll('td');
            if (cells[columnIndex]) {
                cells[columnIndex].style.display = isVisible ? '' : 'none';
            }
        });
    }

    /**
     * Guardar preferencias de columnas en cookies
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
            const jsonData = JSON.stringify(preferences);
            // Guardar en cookie con expiración de 365 días
            setCookie(storageKey, jsonData, 365);
        } catch (e) {
            console.warn('GlobalSearch: Could not save column preferences:', e);
        }
    }

    /**
     * Cargar preferencias de columnas desde cookies
     */
    function loadColumnPreferences(storageKey) {
        try {
            const saved = getCookie(storageKey);
            if (saved) {
                const parsed = JSON.parse(saved);
                return parsed;
            }
            return null;
        } catch (e) {
            console.warn('GlobalSearch: Could not load column preferences:', e);
            return null;
        }
    }

    // Mostrar loader del frontend
    function showFrontendLoader() {
        const resultsContainer = document.getElementById('globalsearch-results');
        if (resultsContainer) {
            // Crear overlay de carga si no existe
            let loader = document.getElementById('globalsearch-frontend-loader');
            if (!loader) {
                loader = document.createElement('div');
                loader.id = 'globalsearch-frontend-loader';
                loader.className = 'position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center';
                loader.style.cssText = 'background: rgba(255,255,255,0.8); z-index: 9999;';
                loader.innerHTML = `
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Processing...</span>
                        </div>
                        <p class="mt-3 text-muted">Processing search results...</p>
                    </div>
                `;
                document.body.appendChild(loader);
            }
        }
    }

    // Ocultar loader del frontend
    function hideFrontendLoader() {
        const loader = document.getElementById('globalsearch-frontend-loader');
        if (loader) {
            loader.style.opacity = '0';
            loader.style.transition = 'opacity 0.3s';
            setTimeout(function () {
                loader.remove();
            }, 300);
        }
    }

    // Inicializar cuando el DOM esté listo
    function initialize() {
        // Mostrar loader del frontend
        showFrontendLoader();

        const tables = document.querySelectorAll('.search-results-table');

        if (tables.length === 0) {
            hideFrontendLoader();
            // Intentar de nuevo después de un delay
            setTimeout(function () {
                const retryTables = document.querySelectorAll('.search-results-table');
                if (retryTables.length > 0) {
                    showFrontendLoader();
                    initAllTables();
                    hideFrontendLoader();
                }
            }, 500);
            return;
        }

        // Inicializar tablas (esto puede tardar)
        setTimeout(function () {
            initAllTables();
            hideFrontendLoader();
        }, 50);
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

