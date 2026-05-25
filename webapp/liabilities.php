<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAuth();
$page = 'liabilities';
require_once __DIR__ . '/includes/header.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= h(__('page.liabilities.title')) ?></h1>
            <p class="page-subtitle"><?= h(__('page.liabilities.subtitle')) ?></p>
        </div>
        <button class="btn btn-primary" data-action="open-liab-modal">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon-svg"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <?= h(__('page.liabilities.add')) ?>
        </button>
    </div>
    <div class="filter-row">
        <button class="filter-btn"        data-filter="all"     data-action="filter-l"><?= h(__('js.filter.all')) ?></button>
        <button class="filter-btn active" data-filter="open"    data-action="filter-l" id="l-btn-open"><?= h(__('js.filter.open')) ?></button>
        <button class="filter-btn"        data-filter="settled" data-action="filter-l" id="l-btn-settled"><?= h(__('page.liabilities.filter_settled')) ?> <span id="l-settled-count" class="badge badge-muted" style="margin-left:.3rem"></span></button>
        <div class="search-inline">
            <input type="search" id="liab-search" placeholder="<?= h(__('common.search_ph')) ?>" data-input="apply-l-filters">
        </div>
    </div>
    <div id="liabilities-list" class="cards-list">
        <div class="loading-spinner"></div>
    </div>
</main>
<script nonce="<?= h(cspNonce()) ?>">
let allL = [], allContacts = [], activeLFilter = 'open';
function prioLabel(p) { return t('enum.priority.' + p); }
const PRIO_BADGE  = {1:'badge-danger',2:'badge-warning',3:'badge-success'};

async function loadAll() {
    if (!window.CURRENT_CHAR_ID) { document.getElementById('liabilities-list').innerHTML = ''; return; }
    try {
        [allL, allContacts] = await Promise.all([api.get('/api/liabilities.php'), api.get('/api/contacts.php')]);
        updateSettledCount();
        applyLFilters();
    } catch(e) { showError('liabilities-list', e.message); }
}

function updateSettledCount() {
    const n = allL.filter(x => +x.settled).length;
    const el = document.getElementById('l-settled-count');
    if (el) el.textContent = n > 0 ? n : '';
}

function filterL(f, btn) {
    activeLFilter = f;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyLFilters();
}

function applyLFilters() {
    const q = (document.getElementById('liab-search')?.value || '').trim();
    let list = activeLFilter === 'all' ? allL
        : activeLFilter === 'settled' ? allL.filter(x => +x.settled)
        : allL.filter(x => !+x.settled);
    if (q) list = fuzzyFilter(list, q, ['name','amount','date']);
    renderL(list);
}

function renderL(items) {
    const el = document.getElementById('liabilities-list');
    if (!items.length) {
        el.innerHTML = `<div class="empty-state"><div class="empty-icon">📉</div><p>${esc(t('js.liab.empty'))}</p></div>`;
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
                        <span class="badge ${+r.settled ? 'badge-success' : 'badge-danger'}">${esc(+r.settled ? t('js.liab.settled_badge') : t('js.liab.open_badge'))}</span>
                    </div>
                </div>
                <div class="card-actions">
                    <button class="btn-icon-sm" data-action="open-liab-modal" data-id="${r.id}" title="${esc(t('js.btn.edit'))}">✏️</button>
                    <button class="btn-icon-sm btn-danger-sm" data-action="delete-l" data-id="${r.id}" data-name="${esc(r.name)}" title="${esc(t('js.btn.delete'))}">🗑️</button>
                </div>
            </div>
            <div class="data-card-body">
                ${r.amount ? `<div class="data-row"><span class="data-label">${esc(t('js.liab.label.amount'))}</span><span class="amount-highlight">${esc(r.amount)}</span></div>` : ''}
                ${r.date   ? `<div class="data-row"><span class="data-label">${esc(t('js.liab.label.date'))}</span><span>${esc(r.date)}</span></div>` : ''}
            </div>
        </div>`;
    }).join('');
}

function openModal_L(id = null) {
    const r = id ? allL.find(x => x.id == id) : null;
    const contOpts = allContacts.map(c => `<option value="${c.id}" ${r?.contact_id==c.id?'selected':''}>${esc(c.name)}</option>`).join('');
    openModal(r ? t('js.liab.modal.edit') : t('js.liab.modal.new'), `
        <form id="liab-form" data-submit="save-l" data-id="${id || ''}">
            <div class="form-group">
                <label>${esc(t('js.liab.label.name'))}</label>
                <input type="text" name="name" required value="${esc(r?.name||'')}" placeholder="${esc(t('js.liab.ph.name'))}">
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
                        <span class="toggle-text">${esc(t('js.liab.label.settled'))}</span>
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

async function saveL(e, id) {
    e.preventDefault();
    const f = e.target;
    const data = { name: f.name.value, amount: f.amount.value, date: f.date.value,
                   priority: f.priority.value, settled: f.settled.checked,
                   contact_id: f.contact_id.value || null };
    try {
        if (id) { await api.put('/api/liabilities.php', { ...data, id }); toast(t('js.toast.saved')); }
        else     { await api.post('/api/liabilities.php', data); toast(t('js.toast.created')); }
        closeModal(); loadAll();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteL(id, name) {
    if (!confirm(t('js.liab.delete_warn', name))) return;
    try { await api.delete('/api/liabilities.php', { id }); toast(t('js.toast.deleted'));
          allL = allL.filter(x => x.id !== id); updateSettledCount(); applyLFilters(); }
    catch(err) { toast(err.message, 'error'); }
}

function showContactById(id) {
    const c = allContacts.find(x => x.id == id);
    if (c) showContactPreview(c);
}

// Named statt Inline-JS: bei Kontakt-Select Name-Feld vorbefüllen
function contactFillName(e) {
    const sel  = e.target;
    const form = sel.closest('form');
    const opt  = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;
    form.querySelector('[name=name]').value = opt.text;
}

document.addEventListener('DOMContentLoaded', () => {
    registerAction('open-liab-modal',  (e, el, ds) => openModal_L(ds.id ? parseInt(ds.id, 10) : null));
    registerAction('delete-l',         (e, el, ds) => deleteL(parseInt(ds.id, 10), ds.name || ''));
    registerAction('save-l',           (e, el, ds) => saveL(e, ds.id ? parseInt(ds.id, 10) : null));
    registerAction('filter-l',         (e, el, ds) => filterL(ds.filter, el));
    registerAction('apply-l-filters',  () => applyLFilters());
    registerAction('show-contact',     (e, el, ds) => showContactById(parseInt(ds.contactId, 10)));
    registerAction('contact-fill-name', contactFillName);
    loadAll();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
