<?php
// $page must be set by the including file: 'contacts','items','buyers','locations','storage','liabilities','claims','orders','admin'
$currentUser   = getCurrentUser();
$characters    = getCharactersForUser((int)$_SESSION['user_id']);
$currentCharId = getCurrentCharId();
$csrfToken     = generateCSRFToken();

// Alle Module in feststehender Reihenfolge. 'dashboard' ist immer sichtbar (nicht ausblendbar).
$allNavItems = [
    'dashboard'   => ['label' => __('nav.dashboard'),   'icon' => 'home'],
    'biography'   => ['label' => __('nav.biography'),   'icon' => 'user'],
    'contacts'    => ['label' => __('nav.contacts'),    'icon' => 'users'],
    'items'       => ['label' => __('nav.items'),       'icon' => 'book-open'],
    'buyers'      => ['label' => __('nav.buyers'),      'icon' => 'shopping-bag'],
    'locations'   => ['label' => __('nav.locations'),   'icon' => 'map-pin'],
    'storage'     => ['label' => __('nav.storage'),     'icon' => 'archive'],
    'vehicles'    => ['label' => __('nav.vehicles'),    'icon' => 'truck'],
    'liabilities' => ['label' => __('nav.liabilities'), 'icon' => 'trending-down'],
    'claims'      => ['label' => __('nav.claims'),      'icon' => 'trending-up'],
    'orders'      => ['label' => __('nav.orders'),      'icon' => 'clipboard'],
    'notes'       => ['label' => __('nav.notes'),       'icon' => 'edit'],
];

// hidden_modules des aktiven Charakters filtert die Navigation.
// Außerdem die avatar_color des aktiven Chars als Indikator-Dot im Switcher.
$hiddenForCurrent = [];
$currentCharColor = '#7c3aed';
if ($currentCharId) {
    foreach ($characters as $c) {
        if ((int)$c['id'] === (int)$currentCharId) {
            $hiddenForCurrent  = $c['hidden_modules'] ?? [];
            $currentCharColor  = !empty($c['avatar_color']) ? $c['avatar_color'] : '#7c3aed';
            break;
        }
    }
}
$navItems = array_filter($allNavItems, function($k) use ($hiddenForCurrent) {
    return $k === 'dashboard' || !in_array($k, $hiddenForCurrent, true);
}, ARRAY_FILTER_USE_KEY);

// Verwaltung & Admin liegen jetzt im User-Dropdown, nicht mehr in der Hauptnavigation.
// Damit Page-Title-Logik trotzdem den Label findet (alle bekannten Seiten, auch ausgeblendete):
$pageTitles = $allNavItems
    + ['account' => ['label' => __('nav.account'), 'icon' => 'settings']]
    + ['admin'   => ['label' => __('nav.admin'),   'icon' => 'shield']];
$currentLocale = resolveLocale();
?>
<!DOCTYPE html>
<html lang="<?= h($currentLocale) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitles[$page]['label'] ?? 'App') ?> · <?= h(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php $cssVer = @filemtime(__DIR__ . '/../assets/style.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/style.css?v=<?= (int)$cssVer ?>">
    <script nonce="<?= h(cspNonce()) ?>">
        window.CSRF_TOKEN      = <?= json_encode($csrfToken) ?>;
        window.CURRENT_CHAR_ID = <?= json_encode($currentCharId) ?>;
        window.IS_ADMIN        = <?= json_encode(!empty($_SESSION['is_admin'])) ?>;
        window.ALL_CHARACTERS  = <?= json_encode($characters) ?>;
        window.LOCALE          = <?= json_encode($currentLocale) ?>;
        window.I18N            = <?= json_encode(jsLocaleStrings(), JSON_UNESCAPED_UNICODE) ?>;
    </script>
</head>
<body>

<!-- Header -->
<header class="app-header">
    <a href="/dashboard.php" class="header-brand" title="<?= h(__('header.back_to_dash')) ?>">
        <span class="brand-logo">RN</span>
        <span class="brand-name"><?= h(APP_NAME) ?></span>
    </a>

    <!-- Character Switcher -->
    <div class="char-switcher">
        <div class="char-switcher-label"><?= h(__('header.char_label')) ?></div>
        <div class="char-dropdown-wrap">
            <?php if (!empty($characters) && $currentCharId): ?>
                <span id="char-color-dot" class="char-color-dot" style="background:<?= h($currentCharColor) ?>" aria-hidden="true"></span>
            <?php endif; ?>
            <select id="char-select" data-change="switch-character" title="<?= h(__('header.switch_char')) ?>">
                <?php if (empty($characters)): ?>
                    <option value=""><?= h(__('header.no_character')) ?></option>
                <?php else: ?>
                    <?php foreach ($characters as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $c['id'] == $currentCharId ? 'selected' : '' ?>>
                            <?= h($c['name']) ?><?= !empty($c['server']) ? ' · ' . h($c['server']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <button class="btn-icon" data-action="open-new-character-modal" title="<?= h(__('header.add_character')) ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </button>
        </div>
    </div>

    <!-- Navigation (mit Scroll-Buttons für Desktop) -->
    <button class="nav-arrow" id="nav-prev" data-action="nav-scroll" data-dir="-1" aria-label="<?= h(__('header.previous')) ?>" tabindex="-1">&#8249;</button>
    <nav class="main-nav" id="main-nav">
        <?php foreach ($navItems as $key => $item): ?>
            <a href="/<?= $key ?>.php" class="nav-link <?= ($page ?? '') === $key ? 'active' : '' ?>">
                <?= h($item['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <button class="nav-arrow" id="nav-next" data-action="nav-scroll" data-dir="1" aria-label="<?= h(__('header.next')) ?>" tabindex="-1">&#8250;</button>

    <!-- User menu -->
    <div class="user-menu">
        <div class="user-avatar" style="background:<?= h(avatarBgColor($currentUser['username'] ?? 'U')) ?>">
            <?= h(avatarInitials($currentUser['username'] ?? 'U')) ?>
        </div>
        <div class="user-dropdown">
            <span class="user-name"><?= h($currentUser['username'] ?? '') ?></span>
            <a href="/account.php" class="dropdown-item"><?= h(__('nav.account')) ?></a>
            <button data-action="open-change-password-modal" class="dropdown-item"><?= h(__('header.change_password')) ?></button>
            <?php if (!empty($_SESSION['is_admin'])): ?>
                <a href="/admin.php" class="dropdown-item"><?= h(__('nav.admin')) ?></a>
            <?php endif; ?>
            <a href="#" data-action="logout" class="dropdown-item dropdown-item-danger"><?= h(__('header.logout')) ?></a>
            <form id="logout-form" method="post" action="/logout.php" style="display:none">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            </form>
        </div>
    </div>

    <button class="hamburger" id="hamburger" data-action="toggle-nav" aria-label="<?= h(__('header.menu')) ?>">
        <span></span><span></span><span></span>
    </button>
</header>

<!-- Global Modal -->
<div id="modal-overlay" class="modal-overlay" data-action="modal-overlay-click">
    <div class="modal">
        <div class="modal-header">
            <h2 id="modal-title">Modal</h2>
            <button class="modal-close" data-action="close-modal" aria-label="<?= h(__('header.close')) ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body" id="modal-body"></div>
    </div>
</div>

<!-- Contact Quick-View Modal -->
<div id="contact-preview" class="contact-preview-overlay" data-action="contact-preview-overlay-click">
    <div class="contact-preview-box">
        <button class="modal-close" data-action="close-contact-preview">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <div id="contact-preview-content"></div>
    </div>
</div>

<!-- Toast Container -->
<div id="toast-container"></div>

<!-- No character warning -->
<?php if (empty($characters)): ?>
<div class="no-char-banner">
    <span><?= h(__('header.no_char_banner')) ?></span>
</div>
<?php endif; ?>
