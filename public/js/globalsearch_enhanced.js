/**
 * Global Search Enhanced - Pagination, Filters, Highlighting and Column Management
 */

(function () {
    'use strict';

    const ITEMS_PER_PAGE = 20;
    const STORAGE_PREFIX = 'globalsearch_columns_';

    /**
     * Cookie utilities
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
     * Initialize all functionalities for each table
     */
    function initAllTables() {
        try {
            const tables = document.querySelectorAll('.search-results-table');

            tables.forEach(function (table) {
                try {
                    // Prevent double initialization
                    if (table.classList.contains('initialized')) {
                        return;
                    }

                    const tableId = table.getAttribute('id') || 'table-' + Math.random().toString(36).substr(2, 9);
                    table.setAttribute('id', tableId);

                    initPagination(table);
                    initFilters(table);
                    initColumnToggle(table);
                    initSorting(table);
                    applyHighlight(table);

                    table.classList.add('initialized');
                } catch (e) {
                    console.error('GlobalSearch: Error initializing table', e);
                }
            });
        } catch (e) {
            console.error('GlobalSearch: Error in initAllTables', e);
        }
    }

    /**
     * Initialize column sorting
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

                // Clear sorting classes from all headers
                headers.forEach(h => h.classList.remove('sort-asc', 'sort-desc'));

                // Apply class to the selected one
                th.classList.add('sort-' + currentOrder);

                sortRows(table, index, currentOrder);
            });
        });
    }

    /**
     * Sort table rows
     */
    function sortRows(table, columnIndex, order) {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        // Get all valid data rows
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
                // Check if they are IDs (#123 format)
                const idA = valA.startsWith('#') ? parseInt(valA.substring(1)) : NaN;
                const idB = valB.startsWith('#') ? parseInt(valB.substring(1)) : NaN;

                if (!isNaN(idA) && !isNaN(idB)) {
                    comparison = idA - idB;
                } else {
                    // Normal alphabetical sorting
                    comparison = valA.localeCompare(valB, undefined, { numeric: true, sensitivity: 'base' });
                }
            }

            return order === 'asc' ? comparison : -comparison;
        });

        // Re-insert sorted rows into the DOM
        rows.forEach(row => tbody.appendChild(row));

        // Update pagination if it exists
        if (table._pagination) {
            // If there are active filters, use filter logic to get visible rows
            const activeFilters = table.querySelectorAll('.filter-input');
            let hasActiveFilter = false;
            activeFilters.forEach(input => {
                if (input.value && input.value.trim() !== '') hasActiveFilter = true;
            });

            if (hasActiveFilter) {
                // Re-apply filters to maintain state
                applyFilters(table);
            } else {
                // Only update original rows and show first page
                table._pagination.getRows = () => rows;
                table._pagination.showPage(0);
            }
        }

        // Re-aplicar resaltado
        applyHighlight(table);
    }

    /**
     * Client-side pagination
     */
    function initPagination(table) {
        const tbody = table.querySelector('tbody');
        if (!tbody) {
            return;
        }

        // Get all data rows
        let allRows = Array.from(tbody.querySelectorAll('tr'));

        // Filter rows that have cells and are not messages
        let rows = allRows.filter(function (row) {
            const cells = row.querySelectorAll('td');
            const hasCells = cells.length > 0;
            const isNoResults = row.classList.contains('no-results') ||
                row.textContent.trim().toLowerCase().includes('no results');
            return hasCells && !isNoResults;
        });

        const totalRows = rows.length;

        if (totalRows <= ITEMS_PER_PAGE) {
            return; // No pagination needed
        }

        const card = table.closest('.card');
        if (!card) return;

        // Create pagination controls if they don't exist
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
            // Get current rows (they may be filtered)
            const currentRows = table._pagination ? table._pagination.getRows() : rows;
            const currentTotalRows = currentRows.length;
            const currentTotalPages = Math.ceil(currentTotalRows / ITEMS_PER_PAGE);

            const start = page * ITEMS_PER_PAGE;
            const end = Math.min(start + ITEMS_PER_PAGE, currentTotalRows);

            // First hide all rows
            rows.forEach(function (row) {
                row.style.display = 'none';
            });

            // Then show only the rows of the current page that are in currentRows
            currentRows.forEach(function (row, index) {
                if (index >= start && index < end) {
                    row.style.display = '';
                }
            });

            // Update information
            const startNum = currentTotalRows > 0 ? start + 1 : 0;
            const endNum = end;
            paginationInfo.textContent = `Showing ${startNum} - ${endNum} of ${currentTotalRows}`;

            // Update buttons
            prevBtn.disabled = (page === 0);
            nextBtn.disabled = (page >= currentTotalPages - 1);

            currentPage = page;

            // Re-apply highlighting after changing page
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

        // Initialize first page
        showPage(0);

        // Save reference for filters
        table._pagination = {
            showPage: showPage,
            getCurrentPage: () => currentPage,
            getTotalPages: () => Math.ceil((table._pagination.getRows().length) / ITEMS_PER_PAGE),
            getRows: () => rows, // Original unfiltered rows
            getFilteredRows: () => rows.filter(r => r.style.display !== 'none' && r.offsetParent !== null) // Visible rows after filters
        };
    }

    /**
     * Detect if a column is of date type
     */
    function isDateColumn(headerText, table, columnIndex) {
        const text = headerText.trim().toLowerCase();

        // Explicitly exclude ID columns
        const excludeKeywords = ['id', 'identificador'];
        const hasExcludeKeyword = excludeKeywords.some(function (keyword) {
            return text === keyword || text === keyword + 's';
        });

        if (hasExcludeKeyword) {
            return false;
        }

        const dateKeywords = ['date', 'fecha', 'update', 'actualización', 'created', 'creado', 'modified', 'modificado', 'time', 'tiempo'];

        // Check for date keywords in the header
        const hasDateKeyword = dateKeywords.some(function (keyword) {
            return text.includes(keyword);
        });

        // If the header has date keywords, check the content to confirm
        if (hasDateKeyword) {
            const tbody = table.querySelector('tbody');
            if (tbody) {
                const sampleRows = Array.from(tbody.querySelectorAll('tr:not(.no-results)')).slice(0, 5);
                if (sampleRows.length === 0) {
                    return true; // If no rows, trust the header
                }

                let dateCount = 0;
                let totalSamples = 0;

                sampleRows.forEach(function (row) {
                    const cells = row.querySelectorAll('td');
                    if (cells[columnIndex]) {
                        const cellText = getCellText(cells[columnIndex]).trim();
                        // Ignore values that are just numbers (probably IDs)
                        if (cellText && !/^\d+$/.test(cellText)) {
                            totalSamples++;
                            if (parseDateIgnoreTime(cellText) !== null) {
                                dateCount++;
                            }
                        }
                    }
                });

                // If there are valid samples and at least half are dates, confirm as date
                if (totalSamples > 0 && dateCount >= Math.ceil(totalSamples / 2)) {
                    return true;
                }
                // If no valid samples but the header has a date keyword, trust the header
                return totalSamples === 0;
            }
            return true; // If no tbody, trust the header
        }

        // If the header DOES NOT have date keywords, do not consider the column as a date
        // (prevents false positives when numeric values coincidentally match date formats)
        return false;
    }

    /**
     * Parse date ignoring hours (only year, month and day)
     */
    function parseDateIgnoreTime(cellText) {
        if (!cellText || cellText.trim() === '') {
            return null;
        }

        const text = cellText.trim();
        let date = null;

        // Try multiple common date formats
        // YYYY-MM-DD (ISO) format
        if (/^\d{4}-\d{2}-\d{2}/.test(text)) {
            const match = text.match(/^(\d{4})-(\d{2})-(\d{2})/);
            if (match) {
                date = new Date(parseInt(match[1]), parseInt(match[2]) - 1, parseInt(match[3]));
                if (!isNaN(date.getTime())) {
                    return date;
                }
            }
        }

        // DD/MM/YYYY format
        if (/^\d{2}\/\d{2}\/\d{4}/.test(text)) {
            const match = text.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
            if (match) {
                date = new Date(parseInt(match[3]), parseInt(match[2]) - 1, parseInt(match[1]));
                if (!isNaN(date.getTime())) {
                    return date;
                }
            }
        }

        // MM/DD/YYYY format
        if (/^\d{2}\/\d{2}\/\d{4}/.test(text)) {
            const match = text.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
            if (match) {
                date = new Date(parseInt(match[3]), parseInt(match[1]) - 1, parseInt(match[2]));
                if (!isNaN(date.getTime())) {
                    return date;
                }
            }
        }

        // Try direct parsing with Date (can handle various formats)
        date = new Date(text);
        if (!isNaN(date.getTime())) {
            // Normalize to midnight to ignore hours
            date.setHours(0, 0, 0, 0);
            return date;
        }

        return null;
    }

    /**
     * Normalize date to midnight (00:00:00) for comparison
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
     * Table filters
     */
    function initFilters(table) {
        const thead = table.querySelector('thead');
        if (!thead) return;

        const headerRow = thead.querySelector('tr');
        if (!headerRow) return;

        // Check if a filter row already exists
        const existingFilterRow = thead.querySelector('.table-filters');
        if (existingFilterRow) {
            return; // Already exists, don't create another one
        }

        // Create filter row
        const filterRow = document.createElement('tr');
        filterRow.className = 'table-filters';
        const headers = headerRow.querySelectorAll('th');

        headers.forEach(function (th, index) {
            const filterCell = document.createElement('td');
            const columnText = th.textContent.trim().toLowerCase();

            // Text input for most columns
            if (columnText.includes('status')) {
                // Select for status
                const select = document.createElement('select');
                select.className = 'form-control form-control-sm filter-input';
                select.setAttribute('data-column-index', index);
                select.innerHTML = '<option value="">All</option>';
                // Get unique status values from the table
                const statusValues = getUniqueColumnValues(table, index);
                statusValues.forEach(function (val) {
                    const option = document.createElement('option');
                    option.value = val;
                    option.textContent = val;
                    select.appendChild(option);
                });
                filterCell.appendChild(select);
            } else if (isDateColumn(th.textContent, table, index)) {
                // Container for date range
                const dateRangeContainer = document.createElement('div');
                dateRangeContainer.className = 'filter-date-range';
                dateRangeContainer.setAttribute('data-column-index', index);

                // "From" input
                const fromLabel = document.createElement('label');
                fromLabel.className = 'filter-date-label';
                fromLabel.textContent = 'From:';
                fromLabel.setAttribute('for', 'filter-date-from-' + index);

                const fromInput = document.createElement('input');
                fromInput.type = 'date';
                fromInput.className = 'form-control form-control-sm filter-input filter-date-input';
                fromInput.id = 'filter-date-from-' + index;
                fromInput.setAttribute('data-column-index', index);
                fromInput.setAttribute('data-date-type', 'from');

                // "To" input
                const toLabel = document.createElement('label');
                toLabel.className = 'filter-date-label';
                toLabel.textContent = 'To:';
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

        // Add cell with "Apply filters" button
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

        // Create second row for the button
        const buttonRow = document.createElement('tr');
        buttonRow.className = 'table-filters-actions';
        buttonRow.appendChild(buttonCell);

        thead.appendChild(filterRow);
        thead.appendChild(buttonRow);

        // Event listeners for buttons
        applyButton.addEventListener('click', function () {
            applyFilters(table);
        });

        clearButton.addEventListener('click', function () {
            clearFilters(table);
        });

        // Allow Enter in inputs to apply filters
        const filterInputs = filterRow.querySelectorAll('.filter-input');
        filterInputs.forEach(function (input) {
            input.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    applyFilters(table);
                }
            });
        });

        // For date inputs, apply filters automatically on change
        const dateInputs = filterRow.querySelectorAll('.filter-date-input');
        dateInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                applyFilters(table);
            });
        });
    }

    /**
     * Get cell text (without HTML, text only)
     */
    function getCellText(cell) {
        if (!cell) return '';

        // If there is original saved HTML, use that text
        const originalHTML = cell.getAttribute('data-original-html');
        if (originalHTML) {
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = originalHTML;
            return tempDiv.textContent || tempDiv.innerText || '';
        }

        // Otherwise, use textContent (which ignores HTML)
        return cell.textContent || cell.innerText || '';
    }

    /**
     * Apply filters to the table
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

        // Collect active filters (including date ranges)
        const activeFilters = [];

        // Group date inputs by column
        const dateFiltersByColumn = {};
        const dateRangeContainers = table.querySelectorAll('.filter-date-range');
        dateRangeContainers.forEach(function (container) {
            const columnIndex = parseInt(container.getAttribute('data-column-index'));
            const fromInput = container.querySelector('input[data-date-type="from"]');
            const toInput = container.querySelector('input[data-date-type="to"]');

            const cell = container.closest('td');
            if (cell && (cell.offsetParent === null || getComputedStyle(cell).display === 'none')) {
                return; // The column is hidden
            }

            const fromValue = fromInput ? fromInput.value.trim() : '';
            const toValue = toInput ? toInput.value.trim() : '';

            // If at least one of them has a value, consider the filter active
            if (fromValue || toValue) {
                dateFiltersByColumn[columnIndex] = {
                    type: 'date',
                    from: fromValue,
                    to: toValue,
                    columnIndex: columnIndex
                };
            }
        });

        // Collect normal text/select filters
        Array.from(allFilterInputs).forEach(function (input) {
            if (input.disabled) return;

            // Ignore date inputs (already processed above)
            if (input.classList.contains('filter-date-input')) {
                return;
            }

            if (!input.value || input.value.trim() === '') return;

            const cell = input.closest('td');
            if (cell && (cell.offsetParent === null || getComputedStyle(cell).display === 'none')) {
                // The column is hidden, do not apply this filter
                return;
            }

            activeFilters.push({
                type: 'text',
                input: input,
                columnIndex: parseInt(input.getAttribute('data-column-index')),
                value: input.value.trim()
            });
        });

        // Combine all active filters
        const allActiveFilters = activeFilters.concat(Object.values(dateFiltersByColumn));

        // If no active filters, show all rows and restore pagination
        if (allActiveFilters.length === 0) {
            // Get all valid rows
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

        // Get all valid rows (not counting "no results" messages)
        let allRows = Array.from(tbody.querySelectorAll('tr'));
        let rows = allRows.filter(function (row) {
            const cells = row.querySelectorAll('td');
            const hasCells = cells.length > 0;
            const isNoResults = row.classList.contains('no-results') ||
                row.textContent.trim().toLowerCase().includes('no results');
            return hasCells && !isNoResults;
        });

        // Apply filters
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
                    // Date range filtering
                    const cellText = getCellText(cell).trim();
                    const cellDate = parseDateIgnoreTime(cellText);

                    if (cellDate === null) {
                        // If the cell does not contain a valid date, hide the row
                        showRow = false;
                        return;
                    }

                    const normalizedCellDate = normalizeDateToMidnight(cellDate);

                    // Apply range logic
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
                    // Normal text filtering
                    const filterValue = filter.value.toLowerCase();
                    const cellText = getCellText(cell).trim().toLowerCase();
                    if (!cellText.includes(filterValue)) {
                        showRow = false;
                    }
                }
            });

            // Save filter state in a data attribute
            row.setAttribute('data-filter-visible', showRow ? 'true' : 'false');

            if (showRow) {
                filteredRows.push(row);
            }
        });

        // Re-apply pagination with filtered rows
        if (table._pagination) {
            // Update getRows function to return only filtered rows
            table._pagination.getRows = () => filteredRows;

            if (filteredRows.length > 0) {
                // Show pagination if it was hidden
                const pagination = table.closest('.card').querySelector('.search-pagination');
                if (pagination) {
                    pagination.style.display = '';
                }
                // Reset to the first page with filtered rows
                table._pagination.showPage(0);
            } else {
                // If there are no visible rows, hide pagination
                const pagination = table.closest('.card').querySelector('.search-pagination');
                if (pagination) {
                    pagination.style.display = 'none';
                }
                // Hide all rows
                rows.forEach(function (row) {
                    row.style.display = 'none';
                });
            }
        }

        // Re-apply highlighting only to visible rows
        applyHighlight(table);
    }

    /**
     * Clear filters
     */
    function clearFilters(table) {
        const filterInputs = table.querySelectorAll('.filter-input');
        filterInputs.forEach(function (input) {
            input.value = '';
        });

        // Get all original rows
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

        // Remove filter attributes
        rows.forEach(function (row) {
            row.removeAttribute('data-filter-visible');
        });

        // Restore original pagination
        if (table._pagination) {
            const card = table.closest('.card');
            const pagination = card ? card.querySelector('.search-pagination') : null;
            if (pagination) {
                pagination.style.display = '';
            }
            // Restore original row list
            table._pagination.getRows = () => rows;
            // Show first page with all rows
            table._pagination.showPage(0);
        }

        // Re-aplicar resaltado
        applyHighlight(table);
    }

    /**
     * Get unique column values
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
     * Highlight search terms while preserving HTML links
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
                // Save original HTML if not already saved
                if (!cell.hasAttribute('data-original-html')) {
                    cell.setAttribute('data-original-html', cell.innerHTML);
                }

                // Get original HTML
                const originalHTML = cell.getAttribute('data-original-html');

                // Create a temporary element to work with the HTML
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = originalHTML;

                // Recursive function to highlight text in nodes
                function highlightTextNodes(node) {
                    if (node.nodeType === Node.TEXT_NODE) {
                        // It is a text node, apply highlighting
                        let text = node.textContent;
                        let highlightedText = text;

                        terms.forEach(function (term) {
                            const regex = new RegExp(`(${escapeRegex(term)})`, 'gi');
                            highlightedText = highlightedText.replace(regex, '<mark>$1</mark>');
                        });

                        // If there are changes, replace the text node
                        if (highlightedText !== text) {
                            const tempSpan = document.createElement('span');
                            tempSpan.innerHTML = highlightedText;

                            // Replace the text node with the span nodes
                            while (tempSpan.firstChild) {
                                node.parentNode.insertBefore(tempSpan.firstChild, node);
                            }
                            node.parentNode.removeChild(node);
                        }
                    } else if (node.nodeType === Node.ELEMENT_NODE) {
                        // It is an element, process its children recursively
                        // Do not process <mark> nodes to avoid nesting
                        if (node.tagName !== 'MARK') {
                            const children = Array.from(node.childNodes);
                            children.forEach(highlightTextNodes);
                        }
                    }
                }

                // Process all nodes
                const allNodes = Array.from(tempDiv.childNodes);
                allNodes.forEach(highlightTextNodes);

                // Restore processed HTML
                cell.innerHTML = tempDiv.innerHTML;
            });
        });
    }

    /**
     * Get search query from URL or input
     */
    function getSearchQuery() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('globalsearch') || '';
    }

    /**
     * Escape special characters for regex
     */
    function escapeRegex(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Management of visible columns
     */
    function initColumnToggle(table) {
        const thead = table.querySelector('thead');
        if (!thead) return;

        const headerRow = thead.querySelector('tr');
        if (!headerRow) return;

        const tableId = table.getAttribute('id');
        const storageKey = STORAGE_PREFIX + tableId;

        // Load saved preferences
        const savedPreferences = loadColumnPreferences(storageKey);

        // Add column management button
        const card = table.closest('.card');
        if (card) {
            const cardHeader = card.querySelector('.card-header');
            if (cardHeader) {
                let columnToggleBtn = cardHeader.querySelector('.column-toggle-btn');
                if (!columnToggleBtn) {
                    // Container for the dropdown
                    const dropdownContainer = document.createElement('div');
                    dropdownContainer.className = 'dropdown';

                    columnToggleBtn = document.createElement('button');
                    columnToggleBtn.className = 'btn btn-sm btn-outline-secondary column-toggle-btn';
                    columnToggleBtn.innerHTML = '<i class="fas fa-columns"></i> Columns';
                    columnToggleBtn.setAttribute('data-bs-toggle', 'dropdown');
                    columnToggleBtn.setAttribute('aria-expanded', 'false');

                    // Create dropdown menu
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

                    // Initialize dropdown (Bootstrap or manual)
                    if (typeof bootstrap !== 'undefined') {
                        new bootstrap.Dropdown(columnToggleBtn);
                    } else {
                        // Manual fallback for dropdown
                        columnToggleBtn.addEventListener('click', function (e) {
                            e.preventDefault();
                            e.stopPropagation();
                            dropdown.classList.toggle('show');
                            dropdownContainer.classList.toggle('show');
                        });

                        // Close when clicking outside
                        document.addEventListener('click', function (e) {
                            if (!dropdownContainer.contains(e.target)) {
                                dropdown.classList.remove('show');
                                dropdownContainer.classList.remove('show');
                            }
                        });
                    }

                    // Event listeners for checkboxes
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

        // Apply saved preferences
        if (savedPreferences) {
            Object.keys(savedPreferences).forEach(function (index) {
                const isVisible = savedPreferences[index] !== false;
                toggleColumn(table, parseInt(index), isVisible);
            });
        }
    }

    /**
     * Show/hide column
     */
    function toggleColumn(table, columnIndex, isVisible) {
        const thead = table.querySelector('thead');
        const tbody = table.querySelector('tbody');
        if (!thead || !tbody) return;

        // Hide/show header (only <th>)
        const headerCells = thead.querySelectorAll('th');
        if (headerCells[columnIndex]) {
            headerCells[columnIndex].style.display = isVisible ? '' : 'none';
        }

        // Hide/show corresponding filter cell
        const filterRow = thead.querySelector('tr.table-filters');
        if (filterRow) {
            const filterCells = filterRow.querySelectorAll('td');
            const filterCell = filterCells[columnIndex];
            if (filterCell) {
                filterCell.style.display = isVisible ? '' : 'none';

                // Handle date inputs (range)
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
                    // Handle normal inputs (text or select)
                    const filterInput = filterCell.querySelector('.filter-input');
                    if (filterInput) {
                        if (isVisible) {
                            // Re-enable filter when the column is shown
                            filterInput.disabled = false;
                        } else {
                            // Clear and disable filter when the column is hidden
                            filterInput.value = '';
                            filterInput.disabled = true;
                        }
                    }
                }
            }
        }

        // Hide/show data cells
        const rows = tbody.querySelectorAll('tr');
        rows.forEach(function (row) {
            const cells = row.querySelectorAll('td');
            if (cells[columnIndex]) {
                cells[columnIndex].style.display = isVisible ? '' : 'none';
            }
        });
    }

    /**
     * Save column preferences in cookies
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
            // Save in cookie with 365 days expiration
            setCookie(storageKey, jsonData, 365);
        } catch (e) {
            console.warn('GlobalSearch: Could not save column preferences:', e);
        }
    }

    /**
     * Load column preferences from cookies
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
            // Create loading overlay if it doesn't exist
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

    // Hide frontend loader
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

    // Initialize when the DOM is ready
    function initialize() {
        // Show frontend loader
        showFrontendLoader();

        const tables = document.querySelectorAll('.search-results-table');

        if (tables.length === 0) {
            hideFrontendLoader();
            // Try again after a delay
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

        // Initialize tables (this may take time)
        setTimeout(function () {
            initAllTables();
            hideFrontendLoader();
        }, 50);
    }

    // Wait for everything to be fully loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(initialize, 100);
        });
    } else if (document.readyState === 'interactive') {
        setTimeout(initialize, 100);
    } else {
        // DOM fully loaded
        setTimeout(initialize, 100);
    }

    // Also try when the window is fully loaded
    window.addEventListener('load', function () {
        setTimeout(initialize, 200);
    });

})();

