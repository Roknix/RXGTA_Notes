<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAuth();
$page = 'storage';
require_once __DIR__ . '/includes/header.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= h(__('page.storage.title')) ?></h1>
            <p class="page-subtitle"><?= h(__('page.storage.subtitle')) ?></p>
        </div>
        <button class="btn btn-primary" data-action="open-storage-modal">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon-svg"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?= h(__('page.storage.add')) ?>
        </button>
    </div>
    <div class="filter-row">
        <div class="search-inline">
            <input type="search" id="storage-search" placeholder="<?= h(__('page.storage.search_ph')) ?>" data-input="apply-storage-filter">
        </div>
    </div>
    <div id="storage-list" class="cards-list">
        <div class="loading-spinner"></div>
    </div>
</main>

<script nonce="<?= h(cspNonce()) ?>">
let allStorage = [];

async function loadStorage() {
    if (!window.CURRENT_CHAR_ID) { document.getElementById('storage-list').innerHTML = ''; return; }
    try { allStorage = await api.get('/api/storage.php'); renderStorage(allStorage); }
    catch(e) { showError('storage-list', e.message); }
}

function applyStorageFilter(q) {
    renderStorage(fuzzyFilter(allStorage, q, ['storage_name','owner','location','notes']));
}

function renderStorage(items) {
    const el = document.getElementById('storage-list');
    if (!items.length) {
        el.innerHTML = `<div class="empty-state"><div class="empty-icon">📦</div><p>${esc(t('js.storage.empty'))}</p></div>`;
        return;
    }
    el.innerHTML = items.map(s => `
        <div class="data-card storage-card">
            <div class="data-card-header">
                <div class="data-card-title">
                    <span class="storage-icon">🏭</span>
                    <strong>${esc(s.storage_name)}</strong>
                </div>
                <div class="card-actions">
                    <button class="btn-icon-sm" data-action="open-storage-modal" data-id="${s.id}" title="${esc(t('js.btn.edit'))}">✏️</button>
                    <button class="btn-icon-sm btn-danger-sm" data-action="delete-storage" data-id="${s.id}" data-name="${esc(s.storage_name)}" title="${esc(t('js.btn.delete'))}">🗑️</button>
                </div>
            </div>
            <div class="data-card-body">
                ${s.owner          ? `<div class="data-row"><span class="data-label">${esc(t('js.storage.label.owner').replace(' *',''))}</span><span>${esc(s.owner)}</span></div>` : ''}
                ${s.location       ? `<div class="data-row"><span class="data-label">${esc(t('js.storage.label.location'))}</span><span>${esc(s.location)}</span></div>` : ''}
                ${s.storage_number ? `<div class="data-row"><span class="data-label">${esc(t('js.storage.label.number'))}</span><span class="monospace">${esc(s.storage_number)}</span></div>` : ''}
                ${s.pin            ? `<div class="data-row"><span class="data-label">PIN</span><span class="monospace pin-hidden" title="${esc(t('js.storage.pin_show'))}">••••</span><span class="pin-value monospace" style="display:none">${esc(s.pin)}</span></div>` : ''}
                ${s.notes          ? `<div class="data-row notes-row"><span class="data-label">${esc(t('js.storage.label.notes'))}</span><span>${esc(s.notes)}</span></div>` : ''}
            </div>
        </div>`
    ).join('');

    // PIN toggle logic
    document.querySelectorAll('.pin-hidden').forEach(el => {
        el.addEventListener('click', function() {
            const pinVal = this.nextElementSibling;
            if (pinVal.style.display === 'none') {
                this.style.display = 'none';
                pinVal.style.display = '';
            } else {
                this.style.display = '';
                pinVal.style.display = 'none';
            }
        });
    });
}

function openStorageModal(id = null) {
    const s = id ? allStorage.find(x => x.id == id) : null;
    openModal(s ? t('js.storage.modal.edit') : t('js.storage.modal.new'), `
        <form id="storage-form" data-submit="save-storage" data-id="${id || ''}">
            <div class="form-group">
                <label>${esc(t('js.storage.label.name'))}</label>
                <input type="text" name="storage_name" required value="${esc(s?.storage_name||'')}" placeholder="${esc(t('js.storage.ph.name'))}">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.storage.label.owner'))}</label>
                    <input type="text" name="owner" value="${esc(s?.owner||'')}" placeholder="${esc(t('js.storage.ph.owner'))}">
                </div>
                <div class="form-group">
                    <label>${esc(t('js.storage.label.location'))}</label>
                    <input type="text" name="location" value="${esc(s?.location||'')}" placeholder="${esc(t('js.storage.ph.location'))}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.storage.label.number'))}</label>
                    <input type="text" name="storage_number" value="${esc(s?.storage_number||'')}" placeholder="${esc(t('js.storage.ph.number'))}">
                </div>
                <div class="form-group">
                    <label>${esc(t('js.storage.label.pin'))}</label>
                    <input type="text" name="pin" value="${esc(s?.pin||'')}" placeholder="${esc(t('js.storage.ph.pin'))}" autocomplete="off">
                </div>
            </div>
            <div class="form-group">
                <label>${esc(t('js.storage.label.notes'))}</label>
                <textarea name="notes" rows="2" placeholder="${esc(t('js.storage.ph.notes'))}">${esc(s?.notes||'')}</textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.cancel'))}</button>
                <button type="submit" class="btn btn-primary">${esc(s ? t('js.btn.save') : t('js.btn.create'))}</button>
            </div>
        </form>
    `);
}

async function saveStorage(e, id) {
    e.preventDefault();
    const f = e.target;
    const data = { storage_name: f.storage_name.value, owner: f.owner.value,
                   location: f.location.value, storage_number: f.storage_number.value,
                   pin: f.pin.value, notes: f.notes.value };
    try {
        if (id) { await api.put('/api/storage.php', { ...data, id }); toast(t('js.toast.storage_saved')); }
        else     { await api.post('/api/storage.php', data); toast(t('js.toast.storage_created')); }
        closeModal(); loadStorage();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteStorage(id, name) {
    if (!confirm(t('js.storage.delete_warn', name))) return;
    try { await api.delete('/api/storage.php', { id }); toast(t('js.toast.deleted')); loadStorage(); }
    catch(err) { toast(err.message, 'error'); }
}

document.addEventListener('DOMContentLoaded', () => {
    registerAction('open-storage-modal',  (e, el, ds) => openStorageModal(ds.id ? parseInt(ds.id, 10) : null));
    registerAction('delete-storage',      (e, el, ds) => deleteStorage(parseInt(ds.id, 10), ds.name || ''));
    registerAction('save-storage',        (e, el, ds) => saveStorage(e, ds.id ? parseInt(ds.id, 10) : null));
    registerAction('apply-storage-filter',(e) => applyStorageFilter(e.target.value));
    loadStorage();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
