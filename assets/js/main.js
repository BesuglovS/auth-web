document.addEventListener('DOMContentLoaded', function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.3s';
            setTimeout(function() { alert.remove(); }, 300);
        }, 5000);
    });

    document.querySelectorAll('[data-password-toggle]').forEach(function(toggle) {
        toggle.addEventListener('click', function() {
            var field = toggle.parentNode.querySelector('input');
            if (!field) return;

            var show = field.type === 'password';
            var start = field.selectionStart;
            var end = field.selectionEnd;

            field.type = show ? 'text' : 'password';

            try {
                field.setSelectionRange(start, end);
            } catch (e) {
                /* некоторые браузеры не дают работать с выделением в password */
            }

            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            var label = show ? 'Скрыть пароль' : 'Показать пароль';
            toggle.setAttribute('aria-label', label);
            toggle.setAttribute('title', label);
            field.focus();
        });
    });
});
