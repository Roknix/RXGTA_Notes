<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

startSecureSession();

// Sprachschalter: ?lang=xx setzt das Cookie und redirected ohne Param, damit
// Folge-Requests stabil bleiben. tryLocale() filtert über Whitelist — nur 'en'/'de'.
if (isset($_GET['lang'])) {
    $chosen = tryLocale($_GET['lang']);
    if ($chosen !== null) setLocaleCookie($chosen);
    header('Location: /');
    exit;
}

// Aktive Session ODER gültiges Remember-Me-Cookie → direkt zum Dashboard.
if (tryAutoLogin()) {
    header('Location: /dashboard.php');
    exit;
}

$firstRun = isFirstRun();
$error    = '';
$success  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['form_token'] ?? ''), $_POST['form_token'] ?? '')) {
        $error = __('login.form_token_invalid');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($firstRun) {
            $confirm = $_POST['password_confirm'] ?? '';
            if ($password !== $confirm) {
                $error = __('login.passwords_mismatch');
            } else {
                $result = registerFirstAdmin($username, $password);
                if ($result['success']) {
                    header('Location: /dashboard.php');
                    exit;
                }
                $error = $result['error'];
            }
        } else {
            $remember = !empty($_POST['remember']);
            $result = attemptLogin($username, $password, $remember);
            if ($result['success']) {
                header('Location: /dashboard.php');
                exit;
            }
            $error = $result['error'];
        }
    }
}

// Form CSRF token (different from session CSRF, used only for this login form)
if (empty($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$formToken = $_SESSION['form_token'];
$loginLocale = resolveLocale();
?>
<!DOCTYPE html>
<html lang="<?= h($loginLocale) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($firstRun ? __('login.first_run.title') : __('login.title')) ?> · <?= h(APP_NAME) ?></title>
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

        <?php if ($firstRun): ?>
        <div class="auth-badge"><?= h(__('login.first_run.badge')) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off" novalidate>
            <input type="hidden" name="form_token" value="<?= h($formToken) ?>">

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
                           minlength="<?= MIN_PASSWORD_LENGTH ?>"
                           placeholder="<?= h(__('login.password_ph')) ?>">
                    <button type="button" class="pw-toggle" data-toggle-pw="password" tabindex="-1" aria-label="<?= h(__('header.show_password')) ?>">
                        <svg id="pw-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <?php if ($firstRun): ?>
                <div class="form-hint"><?= h(__('login.min_chars', MIN_PASSWORD_LENGTH)) ?></div>
                <?php endif; ?>
            </div>

            <?php if ($firstRun): ?>
            <div class="form-group">
                <label for="password_confirm"><?= h(__('login.password_confirm')) ?></label>
                <div class="password-wrap">
                    <input type="password" id="password_confirm" name="password_confirm" required
                           minlength="<?= MIN_PASSWORD_LENGTH ?>"
                           placeholder="<?= h(__('login.password_confirm_ph')) ?>">
                    <button type="button" class="pw-toggle" data-toggle-pw="password_confirm" tabindex="-1" aria-label="<?= h(__('header.show_password')) ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$firstRun): ?>
            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="remember" value="1" <?= !empty($_POST['remember']) ? 'checked' : '' ?>>
                    <span class="toggle-text"><?= h(__('btn.sign_in_keep')) ?></span>
                </label>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-full">
                <?= h($firstRun ? __('login.first_run.submit') : __('btn.sign_in')) ?>
            </button>
        </form>

        <div class="auth-lang-switch" style="text-align:center;margin-top:1.25rem;font-size:.85rem;color:var(--text-muted)">
            <a href="?lang=de" style="color:inherit;<?= $loginLocale === 'de' ? 'font-weight:600;color:var(--accent-l)' : '' ?>">Deutsch</a>
            ·
            <a href="?lang=en" style="color:inherit;<?= $loginLocale === 'en' ? 'font-weight:600;color:var(--accent-l)' : '' ?>">English</a>
        </div>
    </div>
</div>
<script nonce="<?= h(cspNonce()) ?>">
function togglePassword(fieldId) {
    const f = document.getElementById(fieldId);
    f.type = f.type === 'password' ? 'text' : 'password';
}
// Login-Seite lädt kein app.js → eigenständiger Click-Listener für data-toggle-pw.
document.addEventListener('click', e => {
    const t = e.target.closest('[data-toggle-pw]');
    if (t) togglePassword(t.dataset.togglePw);
});
</script>
</body>
</html>
