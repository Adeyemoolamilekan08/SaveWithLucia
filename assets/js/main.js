// SaveWithLucia — main.js
document.addEventListener('DOMContentLoaded', function () {
    // Auto-dismiss alert messages after 7 seconds
    document.querySelectorAll('.alert').forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity .5s ease';
            el.style.opacity = '0';
            setTimeout(function () {
                if (el.parentNode) el.parentNode.removeChild(el);
            }, 500);
        }, 7000);
    });
});
