<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAuth();
$page = 'vehicles';
require_once __DIR__ . '/includes/header.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= h(__('page.vehicles.title')) ?></h1>
            <p class="page-subtitle"><?= h(__('page.vehicles.subtitle')) ?></p>
        </div>
        <button class="btn btn-primary" data-action="open-vehicle-modal">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon-svg"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?= h(__('page.vehicles.add')) ?>
        </button>
    </div>

    <div class="filter-row">
        <div class="search-inline">
            <input type="search" id="veh-search" placeholder="<?= h(__('common.search_ph')) ?>" data-input="render-vehicles">
        </div>
    </div>

    <div id="vehicles-list" class="cards-list">
        <div class="loading-spinner"></div>
    </div>
</main>

<script nonce="<?= h(cspNonce()) ?>">
let allVehicles = [];

async function loadVehicles() {
    if (!window.CURRENT_CHAR_ID) { document.getElementById('vehicles-list').innerHTML = ''; return; }
    try { allVehicles = await api.get('/api/vehicles.php'); renderVehicles(); }
    catch(e) { showError('vehicles-list', e.message); }
}

function renderVehicles() {
    const q = (document.getElementById('veh-search')?.value || '').trim();
    let list = allVehicles;
    if (q) list = fuzzyFilter(list, q, ['name','make','model','plate','color','modifications','hideouts','next_service','notes']);
    const el = document.getElementById('vehicles-list');
    if (!list.length) {
        el.innerHTML = `<div class="empty-state"><div class="empty-icon">🚗</div><p>${esc(t('js.veh.empty'))}</p></div>`;
        return;
    }
    const todayIso = new Date().toISOString().slice(0,10);
    el.innerHTML = list.map(v => {
        const svcLate = v.next_service && v.next_service < todayIso;
        return `
        <div class="data-card">
            <div class="data-card-header">
                <div class="data-card-title">
                    <span>${esc(v.name)}</span>
                    ${v.plate ? `<span class="badge badge-muted">${esc(v.plate)}</span>` : ''}
                </div>
                <div class="card-actions">
                    <button class="btn-icon-sm" data-action="open-vehicle-modal" data-id="${v.id}" title="${esc(t('js.btn.edit'))}">✏️</button>
                    <button class="btn-icon-sm btn-danger-sm" data-action="delete-vehicle" data-id="${v.id}" data-name="${esc(v.name)}" title="${esc(t('js.btn.delete'))}">🗑️</button>
                </div>
            </div>
            <div class="data-card-body">
                ${(v.make || v.model)   ? `<div class="data-row"><span class="data-label">${esc(t('js.veh.label.model'))}</span><span>${esc([v.make, v.model].filter(Boolean).join(' '))}</span></div>` : ''}
                ${v.color               ? `<div class="data-row"><span class="data-label">${esc(t('js.veh.label.color').split(' /')[0])}</span><span>${esc(v.color)}</span></div>` : ''}
                ${v.modifications       ? `<div class="data-row"><span class="data-label">${esc(t('js.veh.label.tuning').split(' /')[0])}</span><span>${esc(v.modifications)}</span></div>` : ''}
                ${v.hideouts            ? `<div class="data-row"><span class="data-label">${esc(t('js.veh.label.hideouts'))}</span><span>${esc(v.hideouts)}</span></div>` : ''}
                ${v.insurance           ? `<div class="data-row"><span class="data-label">${esc(t('js.veh.label.insurance'))}</span><span>${esc(v.insurance)}</span></div>` : ''}
                ${v.next_service        ? `<div class="data-row"><span class="data-label">${esc(t('js.veh.label.next_service'))}</span><span class="${svcLate?'text-danger':''}">${esc(v.next_service)}</span></div>` : ''}
                ${v.notes               ? `<div class="data-row"><span class="data-label">${esc(t('js.veh.label.notes'))}</span><span>${esc(v.notes)}</span></div>` : ''}
            </div>
        </div>`;
    }).join('');
}

function openVehicleModal(id = null) {
    const v = id ? allVehicles.find(x => x.id == id) : null;
    openModal(v ? t('js.veh.modal.edit') : t('js.veh.modal.new'), `
        <form id="veh-form" data-submit="save-vehicle" data-id="${id || ''}">
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.veh.label.name'))}</label>
                    <input type="text" name="name" required value="${esc(v?.name||'')}" placeholder="${esc(t('js.veh.ph.name'))}">
                </div>
                <div class="form-group">
                    <label>${esc(t('js.veh.label.plate'))}</label>
                    <input type="text" name="plate" value="${esc(v?.plate||'')}" placeholder="${esc(t('js.veh.ph.plate'))}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.veh.label.make'))}</label>
                    <input type="text" name="make" value="${esc(v?.make||'')}" placeholder="${esc(t('js.veh.ph.make'))}">
                </div>
                <div class="form-group">
                    <label>${esc(t('js.veh.label.model'))}</label>
                    <input type="text" name="model" value="${esc(v?.model||'')}" placeholder="${esc(t('js.veh.ph.model'))}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.veh.label.color'))}</label>
                    <input type="text" name="color" value="${esc(v?.color||'')}" placeholder="${esc(t('js.veh.ph.color'))}">
                </div>
                <div class="form-group">
                    <label>${esc(t('js.veh.label.insurance'))}</label>
                    <input type="text" name="insurance" value="${esc(v?.insurance||'')}" placeholder="${esc(t('js.veh.ph.insurance'))}">
                </div>
            </div>
            <div class="form-group">
                <label>${esc(t('js.veh.label.next_service'))}</label>
                <input type="date" name="next_service" value="${esc(v?.next_service||'')}">
            </div>
            <div class="form-group">
                <label>${esc(t('js.veh.label.tuning'))}</label>
                <textarea name="modifications" rows="2" placeholder="${esc(t('js.veh.ph.tuning'))}">${esc(v?.modifications||'')}</textarea>
            </div>
            <div class="form-group">
                <label>${esc(t('js.veh.label.hideouts'))}</label>
                <textarea name="hideouts" rows="2" placeholder="${esc(t('js.veh.ph.hideouts'))}">${esc(v?.hideouts||'')}</textarea>
            </div>
            <div class="form-group">
                <label>${esc(t('js.veh.label.notes'))}</label>
                <textarea name="notes" rows="2" placeholder="${esc(t('js.veh.ph.notes'))}">${esc(v?.notes||'')}</textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.cancel'))}</button>
                <button type="submit" class="btn btn-primary">${esc(v ? t('js.btn.save') : t('js.btn.create'))}</button>
            </div>
        </form>
    `);
}

async function saveVehicle(e, id) {
    e.preventDefault();
    const f = e.target;
    const data = {
        name: f.name.value, make: f.make.value, model: f.model.value, plate: f.plate.value,
        color: f.color.value, modifications: f.modifications.value, hideouts: f.hideouts.value,
        insurance: f.insurance.value, next_service: f.next_service.value, notes: f.notes.value,
    };
    try {
        if (id) { await api.put('/api/vehicles.php', { ...data, id }); toast(t('js.toast.vehicle_saved')); }
        else    { await api.post('/api/vehicles.php', data); toast(t('js.toast.vehicle_created')); }
        closeModal(); loadVehicles();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteVehicle(id, name) {
    if (!confirm(t('js.veh.delete_warn', name))) return;
    try { await api.delete('/api/vehicles.php', { id }); toast(t('js.toast.deleted')); loadVehicles(); }
    catch(err) { toast(err.message, 'error'); }
}

document.addEventListener('DOMContentLoaded', () => {
    registerAction('open-vehicle-modal', (e, el, ds) => openVehicleModal(ds.id ? parseInt(ds.id, 10) : null));
    registerAction('delete-vehicle',     (e, el, ds) => deleteVehicle(parseInt(ds.id, 10), ds.name || ''));
    registerAction('save-vehicle',       (e, el, ds) => saveVehicle(e, ds.id ? parseInt(ds.id, 10) : null));
    registerAction('render-vehicles',    () => renderVehicles());
    loadVehicles();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
