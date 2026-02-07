/**
 * Coyote Linux Web Admin - JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    if (csrfToken && typeof window.fetch === 'function') {
        var originalFetch = window.fetch.bind(window);
        window.fetch = function(resource, init) {
            var options = init || {};
            var method = String(options.method || 'GET').toUpperCase();

            if (method === 'POST' || method === 'PUT' || method === 'DELETE' || method === 'PATCH') {
                var headers = new Headers(options.headers || {});
                if (!headers.has('X-CSRF-Token')) {
                    headers.set('X-CSRF-Token', csrfToken);
                }
                if (!headers.has('X-Requested-With')) {
                    headers.set('X-Requested-With', 'xmlhttprequest');
                }
                options = Object.assign({}, options, { headers: headers });
            }

            return originalFetch(resource, options);
        };
    }

    // Auto-dismiss only transient flash alerts (those with data-auto-dismiss attribute)
    // Static informational alerts should NOT be auto-dismissed
    document.querySelectorAll('.alert[data-auto-dismiss]').forEach(function(alert) {
        var delay = parseInt(alert.dataset.autoDismiss, 10) || 5000;
        setTimeout(function() {
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.remove();
            }, 300);
        }, delay);
    });

    // Confirm dangerous actions
    document.querySelectorAll('[data-confirm]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            if (!confirm(this.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });

    // Auto-refresh pages that have data-auto-refresh attribute
    var autoRefreshEl = document.querySelector('[data-auto-refresh]');
    if (autoRefreshEl) {
        var interval = parseInt(autoRefreshEl.dataset.autoRefresh, 10) * 1000 || 30000;
        setInterval(function() {
            // Only refresh if page is visible
            if (!document.hidden) {
                location.reload();
            }
        }, interval);
    }

    // Mobile Sidebar Toggle
    var sidebar = document.getElementById('sidebar');
    var sidebarToggle = document.getElementById('sidebar-toggle');
    var sidebarOverlay = document.getElementById('sidebar-overlay');

    if (sidebar && sidebarToggle && sidebarOverlay) {
        function toggleSidebar() {
            sidebar.classList.toggle('open');
            sidebarOverlay.classList.toggle('active');
        }

        sidebarToggle.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', toggleSidebar);
    }
});
