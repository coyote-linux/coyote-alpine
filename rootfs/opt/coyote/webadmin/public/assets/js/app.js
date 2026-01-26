/**
 * Coyote Linux Web Admin - JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    // Auto-dismiss alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.remove();
            }, 300);
        }, 5000);
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
});
