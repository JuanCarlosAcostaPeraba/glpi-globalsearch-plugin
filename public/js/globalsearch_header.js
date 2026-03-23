document.addEventListener('DOMContentLoaded', function () {
    // Locale strings from front/lang.php
    const L = window.GLOBALSEARCH_LANG || {};

    // Prevent duplicates if the script is injected multiple times
    if (document.querySelector('.globalsearch-btn')) {
        return;
    }

    // Search for GLPI's native global search form
    // It is in: .ms-md-auto or .ms-lg-auto containing a form with input[name="globalsearch"]
    const nativeSearchForm = document.querySelector('form[data-submit-once] input[name="globalsearch"]');

    if (!nativeSearchForm) {
        // If the native search bar does not exist, do nothing
        //console.warn('[globalsearch] Native GLPI search bar not found.');
        return;
    }

    // The parent container of the form (where we will insert the button)
    const searchContainer = nativeSearchForm.closest('div[class*="ms-"]');

    if (!searchContainer) {
        // If the expected container does not exist, we do not insert the button
        //console.warn('[globalsearch] Search bar container not found.');
        return;
    }

    // Create "Search" button with styles similar to GLPI
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-ghost-secondary globalsearch-btn me-2';
    btn.title = L.btn_title || 'Advanced global search';
    // Search icon with zoom (to differentiate it from native) + visible text
    btn.innerHTML = `<i class="ti ti-search me-1" aria-hidden="true"></i><span>${L.btn_label || 'Global search'}</span>`;

    // Insert button just BEFORE the search container (to its left)
    searchContainer.parentNode.insertBefore(btn, searchContainer);

    // Create modal
    const modal = document.createElement('div');
    modal.className = 'globalsearch-modal d-none';

    // Modal structure
    modal.innerHTML = `
        <div class="globalsearch-backdrop"></div>
        <div class="globalsearch-dialog card shadow-lg">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">${L.modal_title || 'Global search'}</h3>
                <button type="button" class="btn btn-link p-0 m-0 globalsearch-close text-secondary" title="${L.close || 'Close'}">
                    <i class="ti ti-x" aria-hidden="true"></i>
                </button>
            </div>
            <div class="card-body">
                <form method="get" class="globalsearch-form">
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="ti ti-search"></i>
                            </span>
                            <input type="text"
                                   name="globalsearch"
                                   class="form-control form-control-lg"
                                   placeholder="${L.placeholder || 'Search tickets, projects (min. 3 characters)...'}"
                                   autocomplete="off"
                                   autofocus />
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted">
                            <i class="ti ti-info-circle me-1"></i>
                            ${L.help_text || 'Search by ID (e.g. #123), exact phrases (e.g. "web server") or individual words.'}
                        </div>
                    </div>
                    <div class="d-flex justify-content-end align-items-center gap-2">
                        <div class="me-auto d-none align-items-center gap-2 text-muted small globalsearch-modal-loader">
                            <span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span>
                            <span>${L.searching || 'Searching…'}</span>
                        </div>
                        <button type="button" class="btn btn-outline-secondary globalsearch-close">
                            ${L.cancel || 'Cancel'}
                        </button>
                        <button type="submit" class="btn btn-primary globalsearch-submit">
                            ${L.search || 'Search'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    // Build form action using CFG_GLPI.root_doc (available globally in GLPI)
    const form = modal.querySelector('form.globalsearch-form');
    const rootDoc = (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.root_doc) ? CFG_GLPI.root_doc : '';
    const actionUrl = rootDoc + '/plugins/globalsearch/front/search.php';
    form.setAttribute('action', actionUrl);

    const modalLoader = modal.querySelector('.globalsearch-modal-loader');
    const submitButton = modal.querySelector('.globalsearch-submit');

    // Open/Close helpers
    function openModal() {
        modal.classList.remove('d-none');
        // Small delay to ensure the modal is visible before focus
        setTimeout(() => {
            const input = modal.querySelector('input[name="globalsearch"]');
            if (input) {
                input.focus();
                input.select();
            }
        }, 50);
    }

    function closeModal() {
        modal.classList.add('d-none');

        // Hide loader and reactivate controls if the modal is closed without navigating
        if (modalLoader) {
            modalLoader.classList.add('d-none');
        }
        if (form) {
            form.removeAttribute('data-submitting');
            const controls = form.querySelectorAll('input, button');
            controls.forEach(function (el) {
                el.disabled = false;
            });
        }
    }

    // Events
    btn.addEventListener('click', openModal);

    modal.addEventListener('click', function (e) {
        if (e.target.classList.contains('globalsearch-backdrop')) {
            closeModal();
        }
    });

    modal.querySelectorAll('.globalsearch-close').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.classList.contains('d-none')) {
            closeModal();
        }
    });

    // Show loader when submitting the form (without touching the input value)
    if (form) {
        form.addEventListener('submit', function () {
            // Ensure only one input with name="globalsearch" exists
            const gsInputs = form.querySelectorAll('input[name="globalsearch"]');
            if (gsInputs.length > 1) {
                // Keep the first one and delete the rest
                gsInputs.forEach(function (inp, idx) {
                    if (idx > 0) {
                        inp.remove();
                    }
                });
            }

            if (modalLoader) {
                modalLoader.classList.remove('d-none');
            }
        });
    }
});


