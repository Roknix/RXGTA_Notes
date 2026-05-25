<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAuth();
$page = 'account';
require_once __DIR__ . '/includes/header.php';
?>
<?php
$adminExtra = !empty($_SESSION['is_admin']) ? __('page.account.admin_extra') : '';
$currentUserLocale = resolveLocale();
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= h(__('page.account.title')) ?></h1>
            <p class="page-subtitle"><?= h(sprintf(__('page.account.subtitle'), $adminExtra)) ?></p>
        </div>
    </div>

    <section style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem;margin-bottom:1.5rem">
        <h2 style="margin-top:0"><?= h(__('account.lang.title')) ?></h2>
        <p class="page-subtitle" style="margin:.25rem 0 1rem 0"><?= h(__('account.lang.hint')) ?></p>
        <form data-submit="save-locale" style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
            <label class="toggle-label" style="margin:0">
                <input type="radio" name="locale" value="en" <?= $currentUserLocale === 'en' ? 'checked' : '' ?>>
                <span class="toggle-text"><?= h(__('account.lang.en')) ?></span>
            </label>
            <label class="toggle-label" style="margin:0">
                <input type="radio" name="locale" value="de" <?= $currentUserLocale === 'de' ? 'checked' : '' ?>>
                <span class="toggle-text"><?= h(__('account.lang.de')) ?></span>
            </label>
            <button type="submit" class="btn btn-primary"><?= h(__('account.lang.save')) ?></button>
        </form>
    </section>

    <section style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem;margin-bottom:1.5rem">
        <h2 style="margin-top:0"><?= h(__('account.modules.title')) ?></h2>
        <p class="page-subtitle" style="margin:.25rem 0 1rem 0">
            <?= h(__('account.modules.hint')) ?>
        </p>
        <div id="modules-wrap">
            <div class="loading-spinner"></div>
        </div>
    </section>

    <section style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem;margin-bottom:1.5rem">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem">
            <h2 style="margin:0"><?= h(__('account.sessions.title')) ?></h2>
            <button class="btn btn-secondary" data-action="revoke-other-sessions"><?= h(__('account.sessions.revoke_others')) ?></button>
        </div>
        <p class="page-subtitle" style="margin:.25rem 0 1rem 0">
            <?= h(__('account.sessions.hint')) ?>
        </p>
        <div id="sessions-wrap">
            <div class="loading-spinner"></div>
        </div>
    </section>

    <?php if (!empty($_SESSION['is_admin'])): ?>
    <section style="background:var(--card);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem">
        <h2 style="margin-top:0"><?= h(__('account.admin.title')) ?></h2>
        <p class="page-subtitle" style="margin:.25rem 0 1rem 0"><?= h(__('account.admin.hint')) ?></p>
        <a href="/admin.php" class="btn btn-primary"><?= h(__('account.admin.link')) ?></a>
    </section>
    <?php endif; ?>
</main>

<script nonce="<?= h(cspNonce()) ?>">
function parseUserAgent(ua) {
    if (!ua) return { browser: window.LOCALE === 'de' ? 'Älteres Gerät' : 'Older device', os: '' };
    let browser = 'Browser', os = '';
    if (/Edg\//.test(ua))         browser = 'Edge';
    else if (/OPR\/|Opera/.test(ua)) browser = 'Opera';
    else if (/Firefox\//.test(ua))   browser = 'Firefox';
    else if (/Chrome\//.test(ua))    browser = 'Chrome';
    else if (/Safari\//.test(ua))    browser = 'Safari';
    if (/iPhone|iPad|iPod/.test(ua))      os = 'iOS';
    else if (/Android/.test(ua))          os = 'Android';
    else if (/Windows/.test(ua))          os = 'Windows';
    else if (/Mac OS X|Macintosh/.test(ua)) os = 'macOS';
    else if (/Linux/.test(ua))            os = 'Linux';
    return { browser, os };
}

function fmtDate(ts) {
    if (!ts) return '—';
    const loc = window.LOCALE === 'de' ? 'de-DE' : 'en-US';
    return new Date(ts*1000).toLocaleString(loc, { dateStyle: 'medium', timeStyle: 'short' });
}

async function loadSessions() {
    try {
        const list = await api.get('/api/profile.php?action=list_sessions');
        renderSessions(list);
    } catch(e) { showError('sessions-wrap', e.message); }
}

function renderSessions(list) {
    const el = document.getElementById('sessions-wrap');
    if (!list.length) {
        el.innerHTML = `<div class="empty-state"><p>${esc(t('js.empty.no_sessions'))}</p></div>`;
        return;
    }
    el.innerHTML = `
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>${esc(t('js.account.col_device'))}</th>
                    <th>${esc(t('js.account.col_since'))}</th>
                    <th>${esc(t('js.account.col_active'))}</th>
                    <th>${esc(t('js.account.col_expires'))}</th>
                    <th>${esc(t('js.account.col_action'))}</th>
                </tr>
            </thead>
            <tbody>
                ${list.map(s => {
                    const ua = parseUserAgent(s.user_agent);
                    return `
                    <tr class="${s.is_current ? 'current-user-row' : ''}">
                        <td>
                            <strong>${esc(ua.browser)}</strong>${ua.os ? ' · ' + esc(ua.os) : ''}
                            ${s.is_current ? ` <span class="badge badge-info">${esc(t('js.account.this_device'))}</span>` : ''}
                        </td>
                        <td>${fmtDate(s.created_at)}</td>
                        <td>${fmtDate(s.last_used_at)}</td>
                        <td>${fmtDate(s.expires_at)}</td>
                        <td>
                            ${s.is_current
                                ? `<span class="badge badge-muted">${esc(t('js.account.active_badge'))}</span>`
                                : `<button class="btn btn-sm btn-danger-soft" data-action="revoke-session" data-token-id="${s.id}">${esc(t('js.account.revoke'))}</button>`
                            }
                        </td>
                    </tr>`;
                }).join('')}
            </tbody>
        </table>
    </div>`;
}

async function revokeSession(tokenId) {
    if (!confirm(t('js.confirm.revoke_session'))) return;
    try {
        await api.post('/api/profile.php', { action: 'revoke_session', token_id: tokenId });
        toast(t('js.toast.session_revoked'));
        loadSessions();
    } catch(err) { toast(err.message, 'error'); }
}

async function revokeOtherSessions() {
    if (!confirm(t('js.confirm.revoke_others'))) return;
    try {
        await api.post('/api/profile.php', { action: 'revoke_others' });
        toast(t('js.toast.others_revoked'));
        loadSessions();
    } catch(err) { toast(err.message, 'error'); }
}

async function saveLocale(e) {
    e.preventDefault();
    const choice = e.target.querySelector('input[name="locale"]:checked');
    if (!choice) return;
    try {
        await api.post('/api/profile.php', { action: 'set_locale', locale: choice.value });
        toast(t('js.toast.lang_updated'));
        // Sprache wird global wirksam — Reload aktualisiert Navigation, Titel etc.
        setTimeout(() => location.reload(), 600);
    } catch(err) { toast(err.message, 'error'); }
}

function showError(elId, msg) {
    document.getElementById(elId).innerHTML = `<div class="empty-state"><p style="color:var(--danger)">${esc(msg)}</p></div>`;
}

// ===== Bereiche / Module pro Charakter =====
// Labels kommen aus window.I18N — Schlüssel folgen nav.* aus der Navigation.
const TOGGLEABLE_MODULES = [
    { key: 'biography',   navKey: 'nav.biography' },
    { key: 'contacts',    navKey: 'nav.contacts' },
    { key: 'items',       navKey: 'nav.items' },
    { key: 'buyers',      navKey: 'nav.buyers' },
    { key: 'locations',   navKey: 'nav.locations' },
    { key: 'storage',     navKey: 'nav.storage' },
    { key: 'vehicles',    navKey: 'nav.vehicles' },
    { key: 'liabilities', navKey: 'nav.liabilities' },
    { key: 'claims',      navKey: 'nav.claims' },
    { key: 'orders',      navKey: 'nav.orders' },
    { key: 'notes',       navKey: 'nav.notes' },
];

function renderModules() {
    const el = document.getElementById('modules-wrap');
    const charId = window.CURRENT_CHAR_ID;
    const chars  = window.ALL_CHARACTERS || [];
    const cur    = chars.find(c => c.id == charId);
    if (!cur) {
        el.innerHTML = `<div class="empty-state"><p>${esc(t('account.modules.first_char'))}</p></div>`;
        return;
    }
    const hidden = new Set(cur.hidden_modules || []);
    el.innerHTML = `
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem">
            <div class="contact-avatar" style="background:${cur.avatar_color||'#7c3aed'};width:36px;height:36px;font-size:.85rem">${initials(cur.name)}</div>
            <div>
                <div style="font-weight:600">${esc(cur.name)}</div>
                ${cur.server ? `<div style="font-size:.78rem;color:var(--text-muted)">${esc(cur.server)}</div>` : ''}
            </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.5rem .75rem">
            ${TOGGLEABLE_MODULES.map(m => `
                <label class="toggle-label" style="margin:0">
                    <input type="checkbox" data-mod="${m.key}" ${hidden.has(m.key) ? '' : 'checked'} data-change="save-modules">
                    <span class="toggle-text">${esc(t(m.navKey))}</span>
                </label>
            `).join('')}
        </div>
        <p class="page-subtitle" style="margin:.75rem 0 0 0;font-size:.78rem">${esc(t('account.modules.dash_hint'))}</p>
    `;
}

async function saveModules() {
    const hidden = [];
    document.querySelectorAll('#modules-wrap input[data-mod]').forEach(cb => {
        if (!cb.checked) hidden.push(cb.dataset.mod);
    });
    try {
        await api.post('/api/characters.php', {
            action: 'set_hidden_modules',
            character_id: window.CURRENT_CHAR_ID,
            hidden_modules: hidden,
        });
        // Lokale Repräsentation aktualisieren, damit die Nav nach Reload stimmt.
        const chars = window.ALL_CHARACTERS || [];
        const cur   = chars.find(c => c.id == window.CURRENT_CHAR_ID);
        if (cur) cur.hidden_modules = hidden;
        toast(t('js.toast.modules_updated'));
    } catch(e) { toast(e.message, 'error'); }
}

document.addEventListener('DOMContentLoaded', () => {
    // Action-Registry → data-action / data-change in den HTML-Templates oben.
    // (Im DOMContentLoaded, weil app.js erst nach diesem Inline-Block geladen wird.)
    registerAction('revoke-other-sessions', () => revokeOtherSessions());
    registerAction('revoke-session', (e, el, ds) => revokeSession(parseInt(ds.tokenId, 10)));
    registerAction('save-modules', () => saveModules());
    registerAction('save-locale',  (e) => saveLocale(e));

    loadSessions();
    renderModules();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
