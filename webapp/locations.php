<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAuth();
$page = 'locations';
require_once __DIR__ . '/includes/header.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= h(__('page.locations.title')) ?></h1>
            <p class="page-subtitle"><?= h(__('page.locations.subtitle')) ?></p>
        </div>
        <button class="btn btn-primary" data-action="open-location-modal">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon-svg"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?= h(__('page.locations.add')) ?>
        </button>
    </div>

    <div class="filter-row">
        <button class="filter-btn active" data-filter="all"     data-action="filter-locs"><?= h(__('page.locations.filter_all')) ?></button>
        <button class="filter-btn"        data-filter="legal"   data-action="filter-locs"><?= h(__('page.locations.filter_legal')) ?></button>
        <button class="filter-btn"        data-filter="illegal" data-action="filter-locs"><?= h(__('page.locations.filter_illegal')) ?></button>
        <div class="search-inline">
            <input type="search" id="loc-search" placeholder="<?= h(__('common.search_ph')) ?>" data-input="apply-loc-filters">
        </div>
    </div>

    <div id="locations-list" class="cards-list">
        <div class="loading-spinner"></div>
    </div>
</main>

<script nonce="<?= h(cspNonce()) ?>">
let allLocations = [];
let activeLocFilter = 'all';

async function loadLocations() {
    if (!window.CURRENT_CHAR_ID) { document.getElementById('locations-list').innerHTML = ''; return; }
    try { allLocations = await api.get('/api/locations.php'); applyLocFilters(); }
    catch(e) { showError('locations-list', e.message); }
}

function filterLocs(f, btn) {
    activeLocFilter = f;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyLocFilters();
}

function applyLocFilters() {
    const q = (document.getElementById('loc-search')?.value || '').trim();
    let list = activeLocFilter === 'all' ? allLocations
        : activeLocFilter === 'illegal' ? allLocations.filter(l => +l.illegal)
        : allLocations.filter(l => !+l.illegal);
    if (q) list = fuzzyFilter(list, q, ['name','description','requires','zip']);
    renderLocations(list);
}

function renderLocations(locs) {
    const el = document.getElementById('locations-list');
    if (!locs.length) {
        el.innerHTML = `<div class="empty-state"><div class="empty-icon">📍</div><p>${esc(t('js.locations.empty'))}</p></div>`;
        return;
    }
    el.innerHTML = locs.map(l => `
        <div class="data-card ${+l.illegal ? 'illegal-card' : ''}">
            <div class="data-card-header">
                <div class="data-card-title">
                    <span>${esc(l.name)}</span>
                    <span class="badge ${+l.illegal ? 'badge-danger' : 'badge-success'}">${esc(+l.illegal ? t('js.locations.illegal_badge') : t('js.locations.legal_badge'))}</span>
                </div>
                <div class="card-actions">
                    <button class="btn-icon-sm" data-action="open-location-modal" data-id="${l.id}" title="${esc(t('js.btn.edit'))}">✏️</button>
                    <button class="btn-icon-sm btn-danger-sm" data-action="delete-location" data-id="${l.id}" data-name="${esc(l.name)}" title="${esc(t('js.btn.delete'))}">🗑️</button>
                </div>
            </div>
            <div class="data-card-body">
                ${l.zip      ? `<div class="data-row"><span class="data-label">${esc(t('js.label.zip'))}</span><span>${esc(l.zip)}</span></div>` : ''}
                ${l.requires ? `<div class="data-row"><span class="data-label">${esc(t('js.label.requires'))}</span><span>${esc(l.requires)}</span></div>` : ''}
                ${l.description ? `<div class="data-row"><span class="data-label">${esc(t('js.label.description'))}</span><span>${esc(l.description)}</span></div>` : ''}
            </div>
        </div>`
    ).join('');
}

function openLocationModal(id = null) {
    const l = id ? allLocations.find(x => x.id == id) : null;
    openModal(l ? t('js.locations.modal.edit') : t('js.locations.modal.new'), `
        <form id="loc-form" data-submit="save-location" data-id="${id || ''}">
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.label.name_required'))}</label>
                    <input type="text" name="name" required value="${esc(l?.name||'')}" placeholder="${esc(t('js.locations.ph.name'))}">
                </div>
                <div class="form-group">
                    <label>${esc(t('js.label.zip'))}</label>
                    <input type="text" name="zip" value="${esc(l?.zip||'')}" placeholder="${esc(t('js.locations.ph.zip'))}">
                </div>
            </div>
            <div class="form-group">
                <label>${esc(t('js.label.requires'))}</label>
                <input type="text" name="requires" value="${esc(l?.requires||'')}" placeholder="${esc(t('js.locations.ph.requires'))}">
            </div>
            <div class="form-group">
                <label>${esc(t('js.label.description'))}</label>
                <textarea name="description" rows="2" placeholder="${esc(t('js.locations.ph.description'))}">${esc(l?.description||'')}</textarea>
            </div>
            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="illegal" ${+l?.illegal ? 'checked' : ''}>
                    <span class="toggle-text">${esc(t('js.locations.illegal_label'))}</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.cancel'))}</button>
                <button type="submit" class="btn btn-primary">${esc(l ? t('js.btn.save') : t('js.btn.create'))}</button>
            </div>
        </form>
    `);
}

async function saveLocation(e, id) {
    e.preventDefault();
    const f = e.target;
    const data = { name: f.name.value, zip: f.zip.value, requires: f.requires.value,
                   description: f.description.value, illegal: f.illegal.checked };
    try {
        if (id) { await api.put('/api/locations.php', { ...data, id }); toast(t('js.toast.location_saved')); }
        else     { await api.post('/api/locations.php', data); toast(t('js.toast.location_created')); }
        closeModal(); loadLocations();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteLocation(id, name) {
    if (!confirm(t('js.locations.delete_warn', name))) return;
    try { await api.delete('/api/locations.php', { id }); toast(t('js.toast.deleted')); loadLocations(); }
    catch(err) { toast(err.message, 'error'); }
}

document.addEventListener('DOMContentLoaded', () => {
    registerAction('open-location-modal', (e, el, ds) => openLocationModal(ds.id ? parseInt(ds.id, 10) : null));
    registerAction('delete-location',     (e, el, ds) => deleteLocation(parseInt(ds.id, 10), ds.name || ''));
    registerAction('save-location',       (e, el, ds) => saveLocation(e, ds.id ? parseInt(ds.id, 10) : null));
    registerAction('filter-locs',         (e, el, ds) => filterLocs(ds.filter, el));
    registerAction('apply-loc-filters',   () => applyLocFilters());
    loadLocations();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
