<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAdmin();
$page = 'admin';
$regMode = getRegistrationMode();
require_once __DIR__ . '/includes/header.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= h(__('page.admin.title')) ?></h1>
            <p class="page-subtitle"><?= h(__('page.admin.subtitle')) ?></p>
        </div>
        <button class="btn btn-primary" data-action="open-create-user-modal">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon-svg"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?= h(__('page.admin.add_user')) ?>
        </button>
    </div>

    <section class="data-card" style="margin-bottom:1.25rem">
        <h2 style="margin:0 0 .75rem 0;font-size:1.05rem"><?= h(__('page.admin.settings_title')) ?></h2>
        <div class="form-group" style="max-width:440px;margin:0">
            <label for="reg-mode-select"><?= h(__('page.admin.reg_mode_label')) ?></label>
            <select id="reg-mode-select" data-change="save-reg-mode">
                <option value="off" <?= $regMode === 'off' ? 'selected' : '' ?>><?= h(__('page.admin.reg_mode.off')) ?></option>
                <option value="open" <?= $regMode === 'open' ? 'selected' : '' ?>><?= h(__('page.admin.reg_mode.open')) ?></option>
                <option value="approval" <?= $regMode === 'approval' ? 'selected' : '' ?>><?= h(__('page.admin.reg_mode.approval')) ?></option>
            </select>
            <div class="form-hint"><?= h(__('page.admin.reg_mode_help')) ?></div>
        </div>
    </section>

    <div id="pending-wrap"></div>

    <div id="users-table-wrap">
        <div class="loading-spinner"></div>
    </div>
</main>
<script nonce="<?= h(cspNonce()) ?>">
let allUsers = [];
const myId = <?= (int)$_SESSION['user_id'] ?>;

async function loadUsers() {
    try {
        allUsers = await api.get('/api/admin.php?action=users');
        renderUsers();
    } catch(e) { showError('users-table-wrap', e.message); }
}

function renderUsers() {
    const pending  = allUsers.filter(u => Number(u.is_approved) === 0);
    const approved = allUsers.filter(u => Number(u.is_approved) !== 0);
    renderPending(pending);

    const el = document.getElementById('users-table-wrap');
    if (!approved.length) {
        el.innerHTML = `<div class="empty-state"><p>${esc(t('js.admin.empty'))}</p></div>`;
        return;
    }
    const loc = window.LOCALE === 'de' ? 'de-DE' : 'en-US';
    el.innerHTML = `
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>${esc(t('js.admin.col_username'))}</th>
                    <th>${esc(t('js.admin.col_role'))}</th>
                    <th>${esc(t('js.admin.col_status'))}</th>
                    <th>${esc(t('js.admin.col_created'))}</th>
                    <th>${esc(t('js.admin.col_actions'))}</th>
                </tr>
            </thead>
            <tbody>
                ${approved.map(u => `
                <tr class="${u.id == myId ? 'current-user-row' : ''}">
                    <td>#${u.id}</td>
                    <td>
                        <strong>${esc(u.username)}</strong>
                        ${u.id == myId ? ` <span class="badge badge-info">${esc(t('js.admin.you'))}</span>` : ''}
                    </td>
                    <td>
                        <span class="badge ${u.is_admin ? 'badge-warning' : 'badge-muted'}">
                            ${esc(u.is_admin ? t('js.admin.role_admin') : t('js.admin.role_user'))}
                        </span>
                    </td>
                    <td>
                        ${u.locked_until && Date.now()/1000 < u.locked_until
                            ? `<span class="badge badge-danger">${esc(t('js.admin.locked'))}</span>`
                            : u.failed_attempts > 0
                                ? `<span class="badge badge-warning">${esc(t('js.admin.failed_attempts', u.failed_attempts))}</span>`
                                : `<span class="badge badge-success">${esc(t('js.admin.ok'))}</span>`
                        }
                    </td>
                    <td>${u.created_at ? new Date(u.created_at*1000).toLocaleDateString(loc) : '—'}</td>
                    <td>
                        <div class="card-actions">
                            <button class="btn btn-sm btn-secondary" data-action="open-reset-pw-modal" data-id="${u.id}" data-username="${esc(u.username)}">${esc(t('js.admin.btn.reset_pw'))}</button>
                            <button class="btn btn-sm btn-secondary" data-action="force-logout"        data-id="${u.id}" data-username="${esc(u.username)}">${esc(t('js.admin.btn.force_logout'))}</button>
                            ${u.locked_until ? `<button class="btn btn-sm btn-secondary" data-action="unlock-user" data-id="${u.id}">${esc(t('js.admin.btn.unlock'))}</button>` : ''}
                            ${u.id != myId ? `
                            <button class="btn btn-sm btn-secondary"     data-action="toggle-admin" data-id="${u.id}" data-is-admin="${u.is_admin ? 1 : 0}">${esc(u.is_admin ? t('js.admin.btn.demote') : t('js.admin.btn.promote'))}</button>
                            <button class="btn btn-sm btn-danger-soft"   data-action="delete-user"  data-id="${u.id}" data-username="${esc(u.username)}">🗑️</button>
                            ` : ''}
                        </div>
                    </td>
                </tr>`).join('')}
            </tbody>
        </table>
    </div>`;
}

function renderPending(pending) {
    const el = document.getElementById('pending-wrap');
    if (!pending.length) { el.innerHTML = ''; return; }
    const loc = window.LOCALE === 'de' ? 'de-DE' : 'en-US';
    el.innerHTML = `
    <section class="data-card" style="margin-bottom:1.25rem">
        <h2 style="margin:0 0 .75rem 0;font-size:1.05rem">
            ${esc(t('page.admin.pending_title'))}
            <span class="badge badge-warning">${pending.length}</span>
        </h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>${esc(t('js.admin.col_username'))}</th>
                        <th>${esc(t('js.admin.pending.col_requested'))}</th>
                        <th>${esc(t('js.admin.col_actions'))}</th>
                    </tr>
                </thead>
                <tbody>
                    ${pending.map(u => `
                    <tr>
                        <td>#${u.id}</td>
                        <td><strong>${esc(u.username)}</strong></td>
                        <td>${u.created_at ? new Date(u.created_at*1000).toLocaleDateString(loc) : '—'}</td>
                        <td>
                            <div class="card-actions">
                                <button class="btn btn-sm btn-primary"     data-action="approve-user" data-id="${u.id}">${esc(t('js.admin.pending.approve'))}</button>
                                <button class="btn btn-sm btn-danger-soft" data-action="reject-user"  data-id="${u.id}" data-username="${esc(u.username)}">${esc(t('js.admin.pending.reject'))}</button>
                            </div>
                        </td>
                    </tr>`).join('')}
                </tbody>
            </table>
        </div>
    </section>`;
}

function openCreateUserModal() {
    openModal(t('js.admin.modal.create'), `
        <form id="create-user-form" data-submit="create-user">
            <div class="form-group">
                <label>${esc(t('js.admin.label.username'))}</label>
                <input type="text" name="username" required minlength="3" maxlength="64" placeholder="${esc(t('js.admin.ph.username'))}">
            </div>
            <div class="form-group">
                <label>${esc(t('js.admin.label.password'))}</label>
                <input type="password" name="password" required minlength="8" placeholder="${esc(t('js.admin.ph.password'))}">
            </div>
            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="is_admin">
                    <span class="toggle-text">${esc(t('js.admin.label.give_admin'))}</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.cancel'))}</button>
                <button type="submit" class="btn btn-primary">${esc(t('js.admin.create_btn'))}</button>
            </div>
        </form>
    `);
}

async function createUser(e) {
    e.preventDefault();
    const f = e.target;
    try {
        await api.post('/api/admin.php', { action: 'create_user',
            username: f.username.value, password: f.password.value, is_admin: f.is_admin.checked });
        toast(t('js.admin.toast.user_created'));
        closeModal();
        loadUsers();
    } catch(err) { toast(err.message, 'error'); }
}

function openResetPwModal(userId, username) {
    openModal(t('js.admin.modal.reset', username), `
        <form id="reset-pw-form" data-submit="reset-password" data-id="${userId}">
            <div class="form-group">
                <label>${esc(t('js.pw.new'))}</label>
                <input type="password" name="password" required minlength="8" placeholder="${esc(t('js.admin.ph.password'))}">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.cancel'))}</button>
                <button type="submit" class="btn btn-primary">${esc(t('js.admin.set_pw_btn'))}</button>
            </div>
        </form>
    `);
}

async function resetPassword(e, userId) {
    e.preventDefault();
    try {
        await api.post('/api/admin.php', { action: 'reset_password', user_id: userId, password: e.target.password.value });
        toast(t('js.admin.toast.pw_reset'));
        closeModal();
    } catch(err) { toast(err.message, 'error'); }
}

async function forceLogout(userId, username) {
    if (!confirm(t('js.admin.confirm.force_logout', username))) return;
    try { await api.post('/api/admin.php', { action: 'force_logout_user', user_id: userId }); toast(t('js.admin.toast.logged_out')); }
    catch(err) { toast(err.message, 'error'); }
}

async function unlockUser(userId) {
    try { await api.post('/api/admin.php', { action: 'unlock_user', user_id: userId }); toast(t('js.admin.toast.unlocked')); loadUsers(); }
    catch(err) { toast(err.message, 'error'); }
}

async function toggleAdmin(userId, currentAdmin) {
    const msg = currentAdmin ? t('js.admin.confirm.demote') : t('js.admin.confirm.promote');
    if (!confirm(msg)) return;
    try { await api.post('/api/admin.php', { action: 'toggle_admin', user_id: userId }); toast(t('js.admin.toast.changed')); loadUsers(); }
    catch(err) { toast(err.message, 'error'); }
}

async function deleteUser(userId, username) {
    if (!confirm(t('js.admin.confirm.delete', username))) return;
    try { await api.delete('/api/admin.php', { action: 'delete_user', user_id: userId }); toast(t('js.admin.toast.user_deleted')); loadUsers(); }
    catch(err) { toast(err.message, 'error'); }
}

async function saveRegMode(e) {
    try { await api.post('/api/admin.php', { action: 'set_registration_mode', mode: e.target.value }); toast(t('js.admin.reg.saved')); }
    catch(err) { toast(err.message, 'error'); }
}

async function approveUser(userId) {
    try { await api.post('/api/admin.php', { action: 'approve_user', user_id: userId }); toast(t('js.admin.toast.approved')); loadUsers(); }
    catch(err) { toast(err.message, 'error'); }
}

async function rejectUser(userId, username) {
    if (!confirm(t('js.admin.confirm.reject', username))) return;
    try { await api.delete('/api/admin.php', { action: 'delete_user', user_id: userId }); toast(t('js.admin.toast.user_deleted')); loadUsers(); }
    catch(err) { toast(err.message, 'error'); }
}

document.addEventListener('DOMContentLoaded', () => {
    registerAction('open-create-user-modal', () => openCreateUserModal());
    registerAction('open-reset-pw-modal',    (e, el, ds) => openResetPwModal(parseInt(ds.id, 10), ds.username || ''));
    registerAction('force-logout',           (e, el, ds) => forceLogout(parseInt(ds.id, 10), ds.username || ''));
    registerAction('unlock-user',            (e, el, ds) => unlockUser(parseInt(ds.id, 10)));
    registerAction('toggle-admin',           (e, el, ds) => toggleAdmin(parseInt(ds.id, 10), parseInt(ds.isAdmin, 10) === 1));
    registerAction('delete-user',            (e, el, ds) => deleteUser(parseInt(ds.id, 10), ds.username || ''));
    registerAction('create-user',            (e) => createUser(e));
    registerAction('reset-password',         (e, el, ds) => resetPassword(e, parseInt(ds.id, 10)));
    registerAction('save-reg-mode',          (e) => saveRegMode(e));
    registerAction('approve-user',           (e, el, ds) => approveUser(parseInt(ds.id, 10)));
    registerAction('reject-user',            (e, el, ds) => rejectUser(parseInt(ds.id, 10), ds.username || ''));
    loadUsers();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
