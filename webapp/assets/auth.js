/*
 * Live-Passwortprüfung für die Auth-Seiten (register.php, index.php Erst-Setup).
 *
 * Reines Progressive Enhancement: zeigt Anforderungen grün/grau an und markiert
 * die Felder rot/grün. Die eigentliche Durchsetzung passiert serverseitig in
 * passwordPolicyError() (includes/functions.php) — die Regeln hier MÜSSEN dazu
 * 1:1 passen. Diese Datei wird als 'self'-Skript geladen (kein Inline → CSP-konform)
 * und hält sich bewusst an ES5-freundliches, dependency-freies JS.
 */
(function () {
    'use strict';

    var pw = document.querySelector('[data-pw-policy]');
    if (!pw) return; // Seite ohne Passwort-Policy (z.B. normaler Login) → nichts tun.

    var min      = parseInt(pw.getAttribute('data-pw-min') || '8', 10);
    var rulesBox = document.querySelector('[data-pw-rules]');
    var confirm  = document.querySelector('[data-pw-confirm]');
    var form     = pw.closest('form');

    function evaluate() {
        var v = pw.value;
        return {
            length:  v.length >= min,
            lower:   /[a-z]/.test(v),
            upper:   /[A-Z]/.test(v),
            digit:   /[0-9]/.test(v),
            special: /[^A-Za-z0-9]/.test(v),
            // Match nur relevant, wenn es ein Bestätigungsfeld gibt.
            match:   confirm ? (confirm.value.length > 0 && confirm.value === v) : true
        };
    }

    // "Passwort selbst stark" (ohne match) — bestimmt die Farbe des Passwortfelds.
    function pwStrong(c) {
        return c.length && c.lower && c.upper && c.digit && c.special;
    }

    function setFieldState(el, touched, ok) {
        el.classList.remove('input-valid', 'input-invalid');
        if (!touched) return; // unberührtes Feld nicht einfärben
        el.classList.add(ok ? 'input-valid' : 'input-invalid');
    }

    function update() {
        var c = evaluate();

        if (rulesBox) {
            var items = rulesBox.querySelectorAll('[data-rule]');
            for (var i = 0; i < items.length; i++) {
                var li   = items[i];
                var rule = li.getAttribute('data-rule');
                if (rule === 'match' && !confirm) { li.style.display = 'none'; continue; }
                if (c[rule]) { li.classList.add('met'); }
                else         { li.classList.remove('met'); }
            }
        }

        setFieldState(pw, pw.value.length > 0, pwStrong(c));
        if (confirm) setFieldState(confirm, confirm.value.length > 0, c.match);

        return c;
    }

    function allValid(c) {
        return pwStrong(c) && c.match;
    }

    pw.addEventListener('input', update);
    if (confirm) confirm.addEventListener('input', update);

    if (form) {
        form.addEventListener('submit', function (e) {
            if (!allValid(evaluate())) {
                e.preventDefault();
                update();
                pw.focus();
            }
        });
    }

    update();
})();
