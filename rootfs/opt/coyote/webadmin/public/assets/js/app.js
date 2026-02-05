/**
 * Coyote Linux Web Admin - JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
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
