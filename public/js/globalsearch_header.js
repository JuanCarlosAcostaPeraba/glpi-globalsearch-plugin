document.addEventListener('DOMContentLoaded', function () {
    // Evitar duplicados si el script se inyecta múltiples veces
    if (document.querySelector('.globalsearch-btn')) {
        return;
    }

    // Buscar el formulario de búsqueda global nativo de GLPI
    // Está en: .ms-md-auto o .ms-lg-auto que contiene un form con input[name="globalsearch"]
    const nativeSearchForm = document.querySelector('form[data-submit-once] input[name="globalsearch"]');

    if (!nativeSearchForm) {
        // Si no existe la barra de búsqueda nativa, no hacemos nada
        //console.warn('[globalsearch] No se ha encontrado la barra de búsqueda nativa de GLPI.');
        return;
    }

    // El contenedor padre del formulario (donde insertaremos el botón)
    const searchContainer = nativeSearchForm.closest('div[class*="ms-"]');

    if (!searchContainer) {
        // Si no existe el contenedor esperado, no insertamos el botón
        //console.warn('[globalsearch] No se ha encontrado el contenedor de la barra de búsqueda.');
        return;
    }

    // Crear botón "Buscar" con estilo similar al de GLPI
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-ghost-secondary globalsearch-btn me-2';
    btn.title = 'Búsqueda global avanzada';
    // Icono de búsqueda con zoom (para diferenciarlo del nativo) + texto visible
    btn.innerHTML = '<i class="ti ti-search me-1" aria-hidden="true"></i><span>Búsqueda global</span>';

    // Insertar botón justo ANTES del contenedor de búsqueda (a su izquierda)
    searchContainer.parentNode.insertBefore(btn, searchContainer);

    // Crear modal
    const modal = document.createElement('div');
    modal.className = 'globalsearch-modal d-none';

    // Estructura del modal
    modal.innerHTML = `
        <div class="globalsearch-backdrop"></div>
        <div class="globalsearch-dialog card shadow-lg">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title mb-0">Búsqueda global</h3>
                <button type="button" class="btn btn-link p-0 m-0 globalsearch-close text-secondary" title="Cerrar">
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
                                   placeholder="Buscar tickets, proyectos (mín. 3 caracteres)..."
                                   autocomplete="off"
                                   autofocus />
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-outline-secondary globalsearch-close">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary globalsearch-submit">
                            Buscar
                        </button>
                    </div>
                </form>

                <div class="globalsearch-modal-loader d-none text-center mt-3">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Buscando...</span>
                    </div>
                    <p class="mt-2 text-muted">Buscando, por favor espera...</p>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    // Construir action del formulario usando CFG_GLPI.root_doc (disponible globalmente en GLPI)
    const form = modal.querySelector('form.globalsearch-form');
    const rootDoc = (typeof CFG_GLPI !== 'undefined' && CFG_GLPI.root_doc) ? CFG_GLPI.root_doc : '';
    const actionUrl = rootDoc + '/plugins/globalsearch/front/search.php';
    form.setAttribute('action', actionUrl);

    const modalLoader = modal.querySelector('.globalsearch-modal-loader');
    const submitButton = modal.querySelector('.globalsearch-submit');

    // Helpers abrir/cerrar
    function openModal() {
        modal.classList.remove('d-none');
        // Pequeño delay para asegurar que el modal es visible antes del focus
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

        // Ocultar loader y reactivar controles si el modal se cierra sin navegar
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

    // Eventos
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

    // Mostrar loader al enviar el formulario
    if (form) {
        form.addEventListener('submit', function (e) {
            // Evitar doble envío
            if (form.getAttribute('data-submitting') === '1') {
                return;
            }
            form.setAttribute('data-submitting', '1');

            // Asegurar que el valor de búsqueda se envía correctamente
            const searchInput = form.querySelector('input[name="globalsearch"]');
            if (searchInput) {
                const value = searchInput.value != null ? String(searchInput.value) : '';

                // Si por alguna razón el input pudiera ser deshabilitado por otros scripts,
                // creamos un campo oculto con el mismo nombre y valor.
                let hidden = form.querySelector('input[type="hidden"][name="globalsearch"]');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'globalsearch';
                    form.appendChild(hidden);
                }
                hidden.value = value;

                // Asegurarnos de que el input visible no esté deshabilitado
                searchInput.disabled = false;
            }

            // Mostrar loader
            if (modalLoader) {
                modalLoader.classList.remove('d-none');
            }

            // Desactivar solo los botones para evitar dobles envíos,
            // pero mantener los inputs habilitados para que se envíen sus valores
            const buttons = form.querySelectorAll('button');
            buttons.forEach(function (btnEl) {
                btnEl.disabled = true;
            });

            // Mantener el submit real (no llamar preventDefault) para que GLPI navegue a la página de resultados
        });
    }
});


