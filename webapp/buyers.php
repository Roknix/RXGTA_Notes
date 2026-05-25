<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAuth();
$page = 'buyers';
require_once __DIR__ . '/includes/header.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= h(__('page.buyers.title')) ?></h1>
            <p class="page-subtitle"><?= h(__('page.buyers.subtitle')) ?></p>
        </div>
        <button class="btn btn-primary" data-action="open-buyer-modal">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon-svg"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?= h(__('page.buyers.add')) ?>
        </button>
    </div>

    <div class="filter-row">
        <button class="filter-btn active" data-filter="all" data-action="filter-buyers"><?= h(__('js.filter.all')) ?></button>
        <button class="filter-btn"        data-filter="1"   data-action="filter-buyers"><?= h(__('js.filter.high')) ?></button>
        <button class="filter-btn"        data-filter="2"   data-action="filter-buyers"><?= h(__('js.filter.medium')) ?></button>
        <button class="filter-btn"        data-filter="3"   data-action="filter-buyers"><?= h(__('js.filter.low')) ?></button>
        <div class="search-inline">
            <input type="search" id="buyer-search" placeholder="<?= h(__('common.search_ph')) ?>" data-input="apply-buyer-filters">
        </div>
    </div>

    <div id="buyers-list" class="cards-list">
        <div class="loading-spinner"></div>
    </div>
</main>

<script nonce="<?= h(cspNonce()) ?>">
let allBuyers   = [];
let allContacts = [];
let activePrioFilter = 'all';
// PRIO_LABELS lokalisiert aus enum.priority.*
function prioLabel(p) { return t('enum.priority.' + p); }
const PRIO_BADGE  = { 1: 'badge-danger', 2: 'badge-warning', 3: 'badge-success' };

async function loadAll() {
    if (!window.CURRENT_CHAR_ID) { document.getElementById('buyers-list').innerHTML = ''; return; }
    try {
        [allBuyers, allContacts] = await Promise.all([
            api.get('/api/buyers.php'),
            api.get('/api/contacts.php'),
        ]);
        applyBuyerFilters();
    } catch(e) { showError('buyers-list', e.message); }
}

function filterBuyers(f, btn) {
    activePrioFilter = f;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyBuyerFilters();
}

function applyBuyerFilters() {
    const q  = (document.getElementById('buyer-search')?.value || '').trim();
    let list = activePrioFilter === 'all' ? allBuyers : allBuyers.filter(b => b.priority == activePrioFilter);
    if (q) list = fuzzyFilter(list, q, ['name','company','needs']);
    renderBuyers(list);
}

function renderBuyers(buyers) {
    const el = document.getElementById('buyers-list');
    if (!buyers.length) {
        el.innerHTML = `<div class="empty-state"><div class="empty-icon">🛍️</div><p>${esc(t('js.buyers.empty'))}</p></div>`;
        return;
    }
    el.innerHTML = buyers.map(b => {
        const nameCell = b.contact_id
            ? `<button class="link-btn" data-action="show-contact" data-contact-id="${b.contact_id}">${esc(b.name)}</button>`
            : `<span>${esc(b.name)}</span>`;
        return `
        <div class="data-card priority-border-${b.priority}">
            <div class="data-card-header">
                <div class="data-card-title">
                    ${nameCell}
                    <span class="badge ${PRIO_BADGE[b.priority]}">${esc(prioLabel(b.priority))}</span>
                </div>
                <div class="card-actions">
                    <button class="btn-icon-sm" data-action="open-buyer-modal" data-id="${b.id}" title="${esc(t('js.btn.edit'))}">✏️</button>
                    <button class="btn-icon-sm btn-danger-sm" data-action="delete-buyer" data-id="${b.id}" data-name="${esc(b.name)}" title="${esc(t('js.btn.delete'))}">🗑️</button>
                </div>
            </div>
            <div class="data-card-body">
                ${b.company ? `<div class="data-row"><span class="data-label">${esc(t('js.buyers.label.company'))}</span><span>${esc(b.company)}</span></div>` : ''}
                ${b.needs   ? `<div class="data-row"><span class="data-label">${esc(t('js.buyers.label.needs'))}</span><span>${esc(b.needs)}</span></div>` : ''}
                ${b.contact_phone ? `<div class="data-row"><span class="data-label">📞</span><span>${esc(b.contact_phone)}</span></div>` : ''}
            </div>
        </div>`;
    }).join('');
}

function openBuyerModal(id = null) {
    const b = id ? allBuyers.find(x => x.id == id) : null;
    const contOptions = allContacts.map(c =>
        `<option value="${c.id}" ${b?.contact_id == c.id ? 'selected':''}>${esc(c.name)}</option>`
    ).join('');
    openModal(b ? t('js.buyers.modal.edit') : t('js.buyers.modal.new'), `
        <form id="buyer-form" data-submit="save-buyer" data-id="${id || ''}">
            <div class="form-group">
                <label>${esc(t('js.buyers.label.name'))}</label>
                <input type="text" name="name" required value="${esc(b?.name||'')}" placeholder="${esc(t('js.buyers.ph.name'))}">
            </div>
            <div class="form-group">
                <label>${esc(t('js.buyers.label.link_contact'))} <span class="form-hint-inline">${esc(t('js.buyers.label.link_optional'))}</span></label>
                <select name="contact_id" data-change="sync-contact-name">
                    <option value="">${esc(t('js.buyers.no_contact'))}</option>
                    ${contOptions}
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.buyers.label.company'))}</label>
                    <input type="text" name="company" value="${esc(b?.company||'')}" placeholder="${esc(t('js.buyers.ph.company'))}">
                </div>
                <div class="form-group">
                    <label>${esc(t('js.buyers.label.priority'))}</label>
                    <select name="priority">
                        ${[1,2,3].map(p => `<option value="${p}" ${b?.priority==p?'selected':''}>${esc(prioLabel(p))}</option>`).join('')}
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>${esc(t('js.buyers.label.needs'))}</label>
                <input type="text" name="needs" value="${esc(b?.needs||'')}" placeholder="${esc(t('js.buyers.ph.needs'))}">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.cancel'))}</button>
                <button type="submit" class="btn btn-primary">${esc(b ? t('js.btn.save') : t('js.btn.create'))}</button>
            </div>
        </form>
    `);
}

function syncContactName(sel) {
    const form = sel.closest('form');
    const opt  = sel.options[sel.selectedIndex];
    if (!opt.value) return;
    if (form.name) form.querySelector('[name="name"]').value = opt.text;
    const c = allContacts.find(x => x.id == opt.value);
    if (c) {
        const parts = [c.company, c.grouping].filter(v => v && v.trim());
        form.querySelector('[name="company"]').value = parts.join(', ');
    }
}

async function saveBuyer(e, id) {
    e.preventDefault();
    const f = e.target;
    const data = { name: f.name.value, company: f.company.value, needs: f.needs.value,
                   priority: f.priority.value, contact_id: f.contact_id.value || null };
    try {
        if (id) { await api.put('/api/buyers.php', { ...data, id }); toast(t('js.toast.buyer_saved')); }
        else     { await api.post('/api/buyers.php', data); toast(t('js.toast.buyer_created')); }
        closeModal(); loadAll();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteBuyer(id, name) {
    if (!confirm(t('js.buyers.delete_warn', name))) return;
    try { await api.delete('/api/buyers.php', { id }); toast(t('js.toast.deleted')); loadAll(); }
    catch(err) { toast(err.message, 'error'); }
}

function showContact(id) {
    const c = allContacts.find(x => x.id == id);
    if (!c) return;
    showContactPreview(c);
}

document.addEventListener('DOMContentLoaded', () => {
    registerAction('open-buyer-modal',    (e, el, ds) => openBuyerModal(ds.id ? parseInt(ds.id, 10) : null));
    registerAction('delete-buyer',        (e, el, ds) => deleteBuyer(parseInt(ds.id, 10), ds.name || ''));
    registerAction('save-buyer',          (e, el, ds) => saveBuyer(e, ds.id ? parseInt(ds.id, 10) : null));
    registerAction('filter-buyers',       (e, el, ds) => filterBuyers(ds.filter, el));
    registerAction('apply-buyer-filters', () => applyBuyerFilters());
    registerAction('show-contact',        (e, el, ds) => showContact(parseInt(ds.contactId, 10)));
    registerAction('sync-contact-name',   (e) => syncContactName(e.target)); // data-change
    loadAll();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
