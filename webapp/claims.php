<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAuth();
$page = 'claims';
require_once __DIR__ . '/includes/header.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= h(__('page.claims.title')) ?></h1>
            <p class="page-subtitle"><?= h(__('page.claims.subtitle')) ?></p>
        </div>
        <button class="btn btn-primary" data-action="open-claim-modal">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon-svg"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?= h(__('page.claims.add')) ?>
        </button>
    </div>
    <div class="filter-row">
        <button class="filter-btn"        data-filter="all"     data-action="filter-c"><?= h(__('js.filter.all')) ?></button>
        <button class="filter-btn active" data-filter="open"    data-action="filter-c"><?= h(__('js.filter.open')) ?></button>
        <button class="filter-btn"        data-filter="settled" data-action="filter-c"><?= h(__('page.claims.filter_settled')) ?> <span id="c-settled-count" class="badge badge-muted" style="margin-left:.3rem"></span></button>
        <div class="search-inline">
            <input type="search" id="claims-search" placeholder="<?= h(__('common.search_ph')) ?>" data-input="apply-c-filters">
        </div>
    </div>
    <div id="claims-list" class="cards-list">
        <div class="loading-spinner"></div>
    </div>
</main>
<script nonce="<?= h(cspNonce()) ?>">
let allC = [], allContacts = [], activeCFilter = 'open';
function prioLabel(p) { return t('enum.priority.' + p); }
const PRIO_BADGE  = {1:'badge-danger',2:'badge-warning',3:'badge-success'};

async function loadAll() {
    if (!window.CURRENT_CHAR_ID) { document.getElementById('claims-list').innerHTML = ''; return; }
    try {
        [allC, allContacts] = await Promise.all([api.get('/api/claims.php'), api.get('/api/contacts.php')]);
        updateCSettledCount();
        applyCFilters();
    } catch(e) { showError('claims-list', e.message); }
}

function updateCSettledCount() {
    const n = allC.filter(x => +x.settled).length;
    const el = document.getElementById('c-settled-count');
    if (el) el.textContent = n > 0 ? n : '';
}

function filterC(f, btn) {
    activeCFilter = f;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyCFilters();
}

function applyCFilters() {
    const q = (document.getElementById('claims-search')?.value || '').trim();
    let list = activeCFilter === 'all' ? allC
        : activeCFilter === 'settled' ? allC.filter(x => +x.settled)
        : allC.filter(x => !+x.settled);
    if (q) list = fuzzyFilter(list, q, ['name','amount','date']);
    renderC(list);
}

function renderC(items) {
    const el = document.getElementById('claims-list');
    if (!items.length) {
        el.innerHTML = `<div class="empty-state"><div class="empty-icon">📈</div><p>${esc(t('js.claims.empty'))}</p></div>`;
        return;
    }
    el.innerHTML = items.map(r => {
        const nameCell = r.contact_id
            ? `<button class="link-btn" data-action="show-contact" data-contact-id="${r.contact_id}">${esc(r.name)}</button>`
            : `<span>${esc(r.name)}</span>`;
        return `
        <div class="data-card ${+r.settled ? 'settled-card' : ''} priority-border-${r.priority}">
            <div class="data-card-header">
                <div class="data-card-title">
                    ${nameCell}
                    <div class="badge-group">
                        <span class="badge ${PRIO_BADGE[r.priority]}">${esc(prioLabel(r.priority))}</span>
                        <span class="badge ${+r.settled ? 'badge-success' : 'badge-warning'}">${esc(+r.settled ? t('js.claims.paid_badge') : t('js.claims.open_badge'))}</span>
                    </div>
                </div>
                <div class="card-actions">
                    <button class="btn-icon-sm" data-action="open-claim-modal" data-id="${r.id}" title="${esc(t('js.btn.edit'))}">✏️</button>
                    <button class="btn-icon-sm btn-danger-sm" data-action="delete-c" data-id="${r.id}" data-name="${esc(r.name)}" title="${esc(t('js.btn.delete'))}">🗑️</button>
                </div>
            </div>
            <div class="data-card-body">
                ${r.amount ? `<div class="data-row"><span class="data-label">${esc(t('js.liab.label.amount'))}</span><span class="amount-highlight amount-positive">${esc(r.amount)}</span></div>` : ''}
                ${r.date   ? `<div class="data-row"><span class="data-label">${esc(t('js.liab.label.date'))}</span><span>${esc(r.date)}</span></div>` : ''}
            </div>
        </div>`;
    }).join('');
}

function openModal_C(id = null) {
    const r = id ? allC.find(x => x.id == id) : null;
    const contOpts = allContacts.map(c => `<option value="${c.id}" ${r?.contact_id==c.id?'selected':''}>${esc(c.name)}</option>`).join('');
    openModal(r ? t('js.claims.modal.edit') : t('js.claims.modal.new'), `
        <form id="claim-form" data-submit="save-c" data-id="${id || ''}">
            <div class="form-group">
                <label>${esc(t('js.claims.label.name'))}</label>
                <input type="text" name="name" required value="${esc(r?.name||'')}" placeholder="${esc(t('js.claims.ph.name'))}">
            </div>
            <div class="form-group">
                <label>${esc(t('js.liab.label.contact'))}</label>
                <select name="contact_id" data-change="contact-fill-name">
                    <option value="">${esc(t('js.liab.no_contact'))}</option>${contOpts}
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.liab.label.amount'))}</label>
                    <input type="text" name="amount" value="${esc(r?.amount||'')}" placeholder="${esc(t('js.liab.ph.amount'))}">
                </div>
                <div class="form-group">
                    <label>${esc(t('js.liab.label.date'))}</label>
                    <input type="date" name="date" value="${esc(r?.date||'')}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.liab.label.priority'))}</label>
                    <select name="priority">
                        ${[1,2,3].map(p=>`<option value="${p}" ${r?.priority==p?'selected':''}>${esc(prioLabel(p))}</option>`).join('')}
                    </select>
                </div>
                <div class="form-group">
                    <label class="toggle-label">
                        <input type="checkbox" name="settled" ${+r?.settled?'checked':''}>
                        <span class="toggle-text">${esc(t('js.claims.label.paid'))}</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.cancel'))}</button>
                <button type="submit" class="btn btn-primary">${esc(r ? t('js.btn.save') : t('js.btn.create'))}</button>
            </div>
        </form>
    `);
}

async function saveC(e, id) {
    e.preventDefault();
    const f = e.target;
    const data = { name: f.name.value, amount: f.amount.value, date: f.date.value,
                   priority: f.priority.value, settled: f.settled.checked,
                   contact_id: f.contact_id.value || null };
    try {
        if (id) { await api.put('/api/claims.php', { ...data, id }); toast(t('js.toast.saved')); }
        else     { await api.post('/api/claims.php', data); toast(t('js.toast.created')); }
        closeModal(); loadAll();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteC(id, name) {
    if (!confirm(t('js.claims.delete_warn', name))) return;
    try { await api.delete('/api/claims.php', { id }); toast(t('js.toast.deleted')); loadAll(); }
    catch(err) { toast(err.message, 'error'); }
}

function showContactById(id) {
    const c = allContacts.find(x => x.id == id);
    if (c) showContactPreview(c);
}

// Named statt Inline-JS: bei Kontakt-Select Name-Feld vorbefüllen (außer leerer Default)
function contactFillName(e) {
    const sel  = e.target;
    const form = sel.closest('form');
    const opt  = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return; // "Kein Kontakt" → nichts überschreiben
    form.querySelector('[name=name]').value = opt.text;
}

document.addEventListener('DOMContentLoaded', () => {
    registerAction('open-claim-modal', (e, el, ds) => openModal_C(ds.id ? parseInt(ds.id, 10) : null));
    registerAction('delete-c',         (e, el, ds) => deleteC(parseInt(ds.id, 10), ds.name || ''));
    registerAction('save-c',           (e, el, ds) => saveC(e, ds.id ? parseInt(ds.id, 10) : null));
    registerAction('filter-c',         (e, el, ds) => filterC(ds.filter, el));
    registerAction('apply-c-filters',  () => applyCFilters());
    registerAction('show-contact',     (e, el, ds) => showContactById(parseInt(ds.contactId, 10)));
    registerAction('contact-fill-name', contactFillName); // data-change
    loadAll();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
