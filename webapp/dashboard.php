<?php
try {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/functions.php';
    requireAuth();
} catch (Throwable $e) {
    http_response_code(500);
    die('<pre style="color:red;padding:2rem">' . htmlspecialchars(__('msg.error_generic', $e->getMessage())) . '</pre>');
}
$page = 'dashboard';

// Aktuelle Charakter-Infos für den Greeting-Text
$charName = '';
if (getCurrentCharId()) {
    $stmt = getDB()->prepare("SELECT name, server, avatar_color FROM characters WHERE id = ?");
    $stmt->execute([getCurrentCharId()]);
    $charRow  = $stmt->fetch();
    $charName = $charRow ? $charRow['name'] : '';
}

$hour = (int)date('G');
$period = $hour < 6 ? 'night' : ($hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening'));
// Tageszeit → 3 Varianten aus dem Lang-File. Index wechselt täglich.
$variantIdx = (int)date('z') % 3;
$greeting   = __('dash.greet.' . $period . '.' . $variantIdx);

// Datum + Uhrzeit lokalisiert. setlocale für strftime ist deprecated; manuelle Mapping reicht.
$currentLocale = resolveLocale();
$weekdays = $currentLocale === 'de'
    ? ['Sonntag','Montag','Dienstag','Mittwoch','Donnerstag','Freitag','Samstag']
    : ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$months = $currentLocale === 'de'
    ? ['Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember']
    : ['January','February','March','April','May','June','July','August','September','October','November','December'];
$dateLine = $weekdays[(int)date('w')] . ', '
    . ($currentLocale === 'de'
        ? date('d.') . ' ' . $months[(int)date('n')-1] . ' ' . date('Y')
        : $months[(int)date('n')-1] . ' ' . date('d') . ', ' . date('Y'))
    . ' · ' . date('H:i') . __('dash.time_suffix');

require_once __DIR__ . '/includes/header.php';
?>
<main class="main-content dashboard">

    <!-- Greeting -->
    <div class="dash-greeting">
        <div class="dash-greeting-text">
            <h1><?= h($greeting) ?><?= $charName ? ', <span class="char-highlight">' . h($charName) . '</span>' : '' ?> 👋</h1>
            <p class="page-subtitle"><?= h($dateLine) ?></p>
        </div>
        <?php if ($charName): ?>
        <div class="dash-char-badge">
            <div class="contact-avatar" style="background:<?= h(!empty($charRow['avatar_color']) ? $charRow['avatar_color'] : avatarBgColor($charName)) ?>;width:48px;height:48px;font-size:1rem">
                <?= h(avatarInitials($charName)) ?>
            </div>
            <div>
                <div style="font-weight:600"><?= h($charName) ?></div>
                <?php if (!empty($charRow['server'])): ?>
                <div style="font-size:.78rem;color:var(--text-muted)"><?= h($charRow['server']) ?></div>
                <?php endif; ?>
            </div>
            <div class="dash-char-actions">
                <button class="btn btn-sm btn-secondary" data-action="open-edit-character-modal" title="<?= h(__('btn.edit')) ?>"><?= h(__('dash.btn.edit_char')) ?></button>
                <button class="btn btn-sm btn-danger-soft" data-action="dash-delete-char" title="<?= h(__('dash.btn.delete_char')) ?>">🗑️</button>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="dash-stats" id="dash-stats">
        <div class="loading-spinner"></div>
    </div>

    <!-- Dashboard Grid -->
    <div class="dash-grid" id="dash-grid">
        <div class="loading-spinner"></div>
    </div>

</main>

<script nonce="<?= h(cspNonce()) ?>">
async function loadDashboard() {
    if (!window.CURRENT_CHAR_ID) {
        document.getElementById('dash-stats').innerHTML = '';
        document.getElementById('dash-grid').innerHTML  = `<div class="empty-state"><p>${esc(t('msg.no_character_dash'))}</p></div>`;
        return;
    }
    try {
        const d = await api.get('/api/dashboard.php');
        renderStats(d.stats);
        renderGrid(d);
    } catch(e) { showError('dash-grid', e.message); }
}

function currentHiddenModules() {
    const chars = window.ALL_CHARACTERS || [];
    const cur = chars.find(c => c.id == window.CURRENT_CHAR_ID);
    return new Set(cur && cur.hidden_modules ? cur.hidden_modules : []);
}

function renderStats(s) {
    const hidden = currentHiddenModules();
    const items = [
        { key: 'contacts',    icon: '👤', label: t('js.dash.stat.contacts'),    value: s.contacts,    href: '/contacts.php',    alert: false },
        { key: 'items',       icon: '📖', label: t('js.dash.stat.recipes'),     value: s.recipes,     href: '/items.php',       alert: false },
        { key: 'locations',   icon: '📍', label: t('js.dash.stat.locations'),   value: s.locations,   href: '/locations.php',   alert: false },
        { key: 'vehicles',    icon: '🚗', label: t('js.dash.stat.vehicles'),    value: s.vehicles,    href: '/vehicles.php',    alert: false },
        { key: 'orders',      icon: '📋', label: t('js.dash.stat.open_orders'), value: s.open_orders, href: '/orders.php',      alert: s.open_orders > 0 },
        { key: 'liabilities', icon: '📉', label: t('js.dash.stat.open_liab'),   value: s.open_liab,   href: '/liabilities.php', alert: s.open_liab > 0 },
        { key: 'claims',      icon: '📈', label: t('js.dash.stat.open_claims'), value: s.open_claims, href: '/claims.php',      alert: s.open_claims > 0 },
    ].filter(i => !hidden.has(i.key));
    document.getElementById('dash-stats').innerHTML = items.map(i => `
        <a href="${i.href}" class="stat-card ${i.alert && i.value > 0 ? 'stat-card-alert' : ''}">
            <div class="stat-icon">${i.icon}</div>
            <div class="stat-value">${i.value}</div>
            <div class="stat-label">${i.label}</div>
        </a>
    `).join('');
}

const PRIO_BADGE = {1:'badge-danger',2:'badge-warning',3:'badge-success'};
function prioText(p) { return t('enum.priority.' + p); }

function renderGrid(d) {
    const hidden = currentHiddenModules();
    const sections = [
        {
            key:   'contacts',
            title: t('js.dash.section.contacts'),
            href:  '/contacts.php',
            html:  d.recent_contacts.length ? d.recent_contacts.map(c => `
                <div class="dash-item">
                    <div class="dash-item-avatar" style="background:${avatarBg(c.name)}">${initials(c.name)}</div>
                    <div class="dash-item-body">
                        <strong>${esc(c.name)}</strong>
                        ${c.role_job ? `<span class="dash-item-sub">${esc(c.role_job)}</span>` : ''}
                        ${c.company  ? `<span class="dash-item-sub">${esc(c.company)}</span>` : ''}
                    </div>
                    ${c.phone ? `<div class="dash-item-extra">${esc(c.phone)}</div>` : ''}
                </div>`).join('') : `<p class="text-muted" style="padding:.5rem 0">${esc(t('js.dash.no_contacts'))}</p>`
        },
        {
            key:   'liabilities',
            title: t('js.dash.section.liab'),
            href:  '/liabilities.php',
            html:  d.open_liab.length ? d.open_liab.map(r => `
                <div class="dash-item">
                    <div class="dash-item-body">
                        <strong>${esc(r.name)}</strong>
                        ${r.amount ? `<span class="dash-item-sub amount-highlight">${esc(r.amount)}</span>` : ''}
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.2rem">
                        <span class="badge ${PRIO_BADGE[r.priority]}">${esc(prioText(r.priority))}</span>
                        ${r.date ? `<span class="dash-item-extra">${esc(r.date)}</span>` : ''}
                    </div>
                </div>`).join('') : `<p class="text-muted" style="padding:.5rem 0">${esc(t('js.dash.no_liab'))}</p>`
        },
        {
            key:   'claims',
            title: t('js.dash.section.claims'),
            href:  '/claims.php',
            html:  d.open_claims.length ? d.open_claims.map(r => `
                <div class="dash-item">
                    <div class="dash-item-body">
                        <strong>${esc(r.name)}</strong>
                        ${r.amount ? `<span class="dash-item-sub amount-positive">${esc(r.amount)}</span>` : ''}
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.2rem">
                        <span class="badge ${PRIO_BADGE[r.priority]}">${esc(prioText(r.priority))}</span>
                        ${r.date ? `<span class="dash-item-extra">${esc(r.date)}</span>` : ''}
                    </div>
                </div>`).join('') : `<p class="text-muted" style="padding:.5rem 0">${esc(t('js.dash.no_claims'))}</p>`
        },
        {
            key:   'orders',
            title: t('js.dash.section.orders'),
            href:  '/orders.php',
            html:  d.open_orders.length ? d.open_orders.map(r => {
                const isLate = r.until_when && new Date(r.until_when) < new Date();
                return `
                <div class="dash-item ${isLate ? 'dash-item-overdue' : ''}">
                    <div class="dash-item-body">
                        <strong>${esc(r.name)}</strong>
                        ${r.what ? `<span class="dash-item-sub">${esc(r.what)}${r.how_much ? ' · ' + esc(r.how_much) : ''}</span>` : ''}
                    </div>
                    <div style="text-align:right">
                        ${r.until_when ? `<span class="dash-item-extra ${isLate?'text-danger':''}">${isLate?'⚠️ ':''}${esc(r.until_when)}</span>` : ''}
                    </div>
                </div>`;}).join('')
                : `<p class="text-muted" style="padding:.5rem 0">${esc(t('js.dash.no_orders'))}</p>`
        },
    ];

    const visibleSections = sections.filter(s => !hidden.has(s.key));

    // Steckbrief-Karte: kurze Statusübersicht, einklappbar wenn 'biography' ausgeblendet
    let bioCard = '';
    if (!hidden.has('biography')) {
        const bio = d.bio || { filled_fields: 0, public_enabled: false };
        const publicBadge = bio.public_enabled
            ? `<span class="badge badge-success">${esc(t('js.dash.bio.public'))}</span>`
            : `<span class="badge badge-muted">${esc(t('js.dash.bio.private'))}</span>`;
        const status = bio.filled_fields > 0
            ? t('js.dash.bio.filled', bio.filled_fields)
            : t('js.dash.bio.empty');
        bioCard = `
        <div class="dash-card">
            <div class="dash-card-header">
                <span class="dash-card-title">${esc(t('js.dash.section.bio'))}</span>
                <a href="/biography.php" class="dash-card-link">${esc(t('js.dash.link_open'))}</a>
            </div>
            <div class="dash-card-body">
                <div class="dash-item">
                    <div class="dash-item-body">
                        <strong>${esc(status)}</strong>
                        <span class="dash-item-sub">${esc(t('js.dash.bio.shareable'))}</span>
                    </div>
                    <div>${publicBadge}</div>
                </div>
            </div>
        </div>`;
    }

    document.getElementById('dash-grid').innerHTML =
        visibleSections.map(s => `
        <div class="dash-card">
            <div class="dash-card-header">
                <span class="dash-card-title">${esc(s.title)}</span>
                <a href="${s.href}" class="dash-card-link">${esc(t('js.dash.link_all'))}</a>
            </div>
            <div class="dash-card-body">${s.html}</div>
        </div>`).join('') + bioCard;
}

function dashDeleteChar() {
    const chars = window.ALL_CHARACTERS || [];
    if (chars.length <= 1) {
        toast(t('js.toast.last_char'), 'error');
        return;
    }
    const c = chars.find(x => x.id == window.CURRENT_CHAR_ID);
    if (c) deleteCharacter(c.id, c.name);
}

document.addEventListener('DOMContentLoaded', () => {
    registerAction('open-edit-character-modal', () => openEditCharacterModal());
    registerAction('dash-delete-char',          () => dashDeleteChar());
    loadDashboard();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
