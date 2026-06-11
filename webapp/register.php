<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

startSecureSession();

// Sprachschalter analog index.php: ?lang=xx setzt Cookie und redirected ohne Param.
if (isset($_GET['lang'])) {
    $chosen = tryLocale($_GET['lang']);
    if ($chosen !== null) setLocaleCookie($chosen);
    header('Location: /register.php');
    exit;
}

// Bereits angemeldet → kein Registrierungsformular nötig.
if (tryAutoLogin()) {
    header('Location: /dashboard.php');
    exit;
}

// Harte serverseitige Sperre: ist Selbstregistrierung aus, gibt es diese Seite nicht.
// (Das Ausblenden der Links allein reicht nicht — der Endpoint muss selbst dichtmachen.)
if (!isRegistrationVisible()) {
    header('Location: /index.php');
    exit;
}

$error    = '';
$pending  = false; // true → Account angelegt, wartet auf Admin-Freigabe (approval-Modus)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['form_token'] ?? ''), $_POST['form_token'] ?? '')) {
        $error = __('login.form_token_invalid');
    } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
        // Honeypot: dieses Feld ist für Menschen unsichtbar. Ist es ausgefüllt,
        // war es mit hoher Wahrscheinlichkeit ein Bot → kommentarlos abweisen.
        $error = __('register.error_generic');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        $result = registerUser($username, $password, $confirm);
        if ($result['success']) {
            if (($result['mode'] ?? '') === 'open' && !empty($result['logged_in'])) {
                header('Location: /dashboard.php');
                exit;
            }
            if (($result['mode'] ?? '') === 'open') {
                // Account existiert, Auto-Login schlug unerwartet fehl → zur Anmeldung.
                header('Location: /index.php');
                exit;
            }
            // approval-Modus: Erfolgsseite mit Freigabe-Hinweis.
            $pending = true;
        } else {
            $error = $result['error'];
        }
    }
}

// Eigenes Form-Token für dieses Formular (wie index.php).
if (empty($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$formToken      = $_SESSION['form_token'];
$registerLocale = resolveLocale();
?>
<!DOCTYPE html>
<html lang="<?= h($registerLocale) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(__('register.title')) ?> · <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="auth-body">
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-logo">
            <span class="brand-logo-lg">RN</span>
            <h1><?= h(APP_NAME) ?></h1>
            <p class="auth-subtitle"><?= h(__('login.tagline')) ?></p>
        </div>

        <?php if ($pending): ?>
        <div class="auth-badge"><?= h(__('register.success_title')) ?></div>
        <div class="alert alert-success"><?= h(__('register.success_approval')) ?></div>
        <a href="/index.php" class="btn btn-primary btn-full"><?= h(__('register.back_to_login')) ?></a>
        <?php else: ?>

        <div class="auth-badge"><?= h(__('register.badge')) ?></div>

        <?php if ($error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off" novalidate>
            <input type="hidden" name="form_token" value="<?= h($formToken) ?>">

            <?php /* Honeypot — für Menschen unsichtbar, nur Bots füllen es aus. */ ?>
            <div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden">
                <label for="website">Website</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>

            <div class="form-group">
                <label for="username"><?= h(__('login.username')) ?></label>
                <input type="text" id="username" name="username" required autofocus
                       minlength="3" maxlength="64"
                       value="<?= h($_POST['username'] ?? '') ?>"
                       placeholder="<?= h(__('login.username_ph')) ?>">
            </div>

            <div class="form-group">
                <label for="password"><?= h(__('login.password')) ?></label>
                <div class="password-wrap">
                    <input type="password" id="password" name="password" required
                           minlength="<?= MIN_PASSWORD_LENGTH ?>" maxlength="<?= MAX_PASSWORD_LENGTH ?>"
                           autocomplete="new-password"
                           data-pw-policy data-pw-min="<?= MIN_PASSWORD_LENGTH ?>"
                           placeholder="<?= h(__('login.password_ph')) ?>">
                    <button type="button" class="pw-toggle" data-toggle-pw="password" tabindex="-1" aria-label="<?= h(__('header.show_password')) ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <ul class="pw-rules" data-pw-rules>
                    <li class="pw-rules-title"><?= h(__('pw.requirements')) ?></li>
                    <li data-rule="length"><?= h(__('login.min_chars', MIN_PASSWORD_LENGTH)) ?></li>
                    <li data-rule="lower"><?= h(__('pw.rule.lower')) ?></li>
                    <li data-rule="upper"><?= h(__('pw.rule.upper')) ?></li>
                    <li data-rule="digit"><?= h(__('pw.rule.digit')) ?></li>
                    <li data-rule="special"><?= h(__('pw.rule.special')) ?></li>
                    <li data-rule="match"><?= h(__('pw.rule.match')) ?></li>
                </ul>
            </div>

            <div class="form-group">
                <label for="password_confirm"><?= h(__('login.password_confirm')) ?></label>
                <div class="password-wrap">
                    <input type="password" id="password_confirm" name="password_confirm" required
                           minlength="<?= MIN_PASSWORD_LENGTH ?>" maxlength="<?= MAX_PASSWORD_LENGTH ?>"
                           autocomplete="new-password" data-pw-confirm
                           placeholder="<?= h(__('login.password_confirm_ph')) ?>">
                    <button type="button" class="pw-toggle" data-toggle-pw="password_confirm" tabindex="-1" aria-label="<?= h(__('header.show_password')) ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-full"><?= h(__('register.submit')) ?></button>
        </form>

        <div style="text-align:center;margin-top:1.25rem;font-size:.9rem;color:var(--text-muted)">
            <?= h(__('register.have_account')) ?>
            <a href="/index.php" style="color:var(--accent-l)"><?= h(__('btn.sign_in')) ?></a>
        </div>
        <?php endif; ?>

        <div class="auth-lang-switch" style="text-align:center;margin-top:1.25rem;font-size:.85rem;color:var(--text-muted)">
            <a href="?lang=de" style="color:inherit;<?= $registerLocale === 'de' ? 'font-weight:600;color:var(--accent-l)' : '' ?>">Deutsch</a>
            ·
            <a href="?lang=en" style="color:inherit;<?= $registerLocale === 'en' ? 'font-weight:600;color:var(--accent-l)' : '' ?>">English</a>
        </div>
    </div>
</div>
<script nonce="<?= h(cspNonce()) ?>">
function togglePassword(fieldId) {
    const f = document.getElementById(fieldId);
    f.type = f.type === 'password' ? 'text' : 'password';
}
document.addEventListener('click', e => {
    const t = e.target.closest('[data-toggle-pw]');
    if (t) togglePassword(t.dataset.togglePw);
});
</script>
<script src="/assets/auth.js"></script>
</body>
</html>
