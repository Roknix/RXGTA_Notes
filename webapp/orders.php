<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAuth();
$page = 'orders';
require_once __DIR__ . '/includes/header.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= h(__('page.orders.title')) ?></h1>
            <p class="page-subtitle"><?= h(__('page.orders.subtitle')) ?></p>
        </div>
        <div class="page-actions">
            <button class="btn btn-secondary" data-action="open-companies-manager"><?= h(__('page.orders.companies')) ?></button>
            <button class="btn btn-primary" data-action="open-order-modal">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon-svg"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?= h(__('page.orders.add')) ?>
            </button>
        </div>
    </div>
    <div class="filter-row">
        <button class="filter-btn"        data-filter="all"  data-action="filter-o"><?= h(__('page.orders.filter_all')) ?></button>
        <button class="filter-btn active" data-filter="open" data-action="filter-o"><?= h(__('page.orders.filter_open')) ?></button>
        <button class="filter-btn"        data-filter="done" data-action="filter-o"><?= h(__('page.orders.filter_done')) ?> <span id="o-done-count" class="badge badge-muted" style="margin-left:.3rem"></span></button>
        <div class="search-inline">
            <input type="search" id="orders-search" placeholder="<?= h(__('common.search_ph')) ?>" data-input="apply-o-filters">
        </div>
    </div>
    <div class="filter-row">
        <button class="filter-btn active" data-type="all"     data-action="filter-o-type"><?= h(__('page.orders.type_all')) ?></button>
        <button class="filter-btn"        data-type="private" data-action="filter-o-type"><?= h(__('page.orders.type_private')) ?></button>
        <button class="filter-btn"        data-type="company" data-action="filter-o-type"><?= h(__('page.orders.type_company')) ?></button>
        <select id="o-company-filter" class="filter-select" style="display:none" data-change="apply-o-filters">
            <option value=""><?= h(__('page.orders.all_companies')) ?></option>
        </select>
    </div>
    <div id="orders-list" class="cards-list">
        <div class="loading-spinner"></div>
    </div>
</main>
<script nonce="<?= h(cspNonce()) ?>">
let allOrders = [], allContacts = [], allCompanies = [], allOrderItems = [];
let activeOFilter = 'open';
let activeOType = 'all';

function orderPrioText(p) { return t('enum.priority.' + p); }
const ORDER_PRIORITY_CLASS = { 1:'badge-danger', 2:'badge-warning', 3:'badge-success' };
const ORDER_PRIORITY_ICON  = { 1:'🔴', 2:'🟠', 3:'🟢' };

async function loadAll() {
    if (!window.CURRENT_CHAR_ID) { document.getElementById('orders-list').innerHTML = ''; return; }
    try {
        [allOrders, allContacts, allCompanies, allOrderItems] = await Promise.all([
            api.get('/api/orders.php'),
            api.get('/api/contacts.php'),
            api.get('/api/orders.php?action=companies'),
            api.get('/api/items.php'),
        ]);
        updateODoneCount();
        refreshCompanyFilterOptions();
        applyOFilters();
    } catch(e) { showError('orders-list', e.message); }
}

function updateODoneCount() {
    const n = allOrders.filter(x => +x.done).length;
    const el = document.getElementById('o-done-count');
    if (el) el.textContent = n > 0 ? n : '';
}

function refreshCompanyFilterOptions() {
    const sel = document.getElementById('o-company-filter');
    if (!sel) return;
    const current = sel.value;
    sel.innerHTML = `<option value="">${esc(t('page.orders.all_companies'))}</option>` +
        allCompanies.map(co => `<option value="${co.id}">${esc(co.name)}</option>`).join('');
    if ([...sel.options].some(o => o.value === current)) sel.value = current;
}

function filterO(f, btn) {
    activeOFilter = f;
    btn.parentElement.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyOFilters();
}

function filterOType(t, btn) {
    activeOType = t;
    btn.parentElement.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const sel = document.getElementById('o-company-filter');
    if (sel) sel.style.display = (t === 'company') ? '' : 'none';
    if (t !== 'company' && sel) sel.value = '';
    applyOFilters();
}

function applyOFilters() {
    const q = (document.getElementById('orders-search')?.value || '').trim();
    let list = activeOFilter === 'all' ? allOrders
        : activeOFilter === 'done' ? allOrders.filter(x => +x.done)
        : allOrders.filter(x => !+x.done);

    if (activeOType === 'private')      list = list.filter(x => !+x.is_company_order);
    else if (activeOType === 'company') list = list.filter(x => +x.is_company_order);

    const coId = document.getElementById('o-company-filter')?.value || '';
    if (activeOType === 'company' && coId) list = list.filter(x => x.company_id == coId);

    if (q) list = fuzzyFilter(list, q, ['name','what','how_much','description','company_name']);
    renderO(list);
}

function renderO(items) {
    const el = document.getElementById('orders-list');
    if (!items.length) {
        el.innerHTML = `<div class="empty-state"><div class="empty-icon">📋</div><p>${esc(t('js.orders.empty'))}</p></div>`;
        return;
    }
    el.innerHTML = items.map(r => {
        const nameCell = r.contact_id
            ? `<button class="link-btn" data-action="show-contact" data-contact-id="${r.contact_id}">${esc(r.name)}</button>`
            : `<span>${esc(r.name)}</span>`;
        const deadline = r.until_when ? new Date(r.until_when) : null;
        const isDone   = +r.done;
        const isLate   = deadline && !isDone && deadline < new Date();
        const prio     = +r.priority || 2;

        const ingTags = (r.ingredients || []).map(i => renderIngTag(i)).join('');
        const recTags = (r.recipes || []).map(rc => renderRecipeTag(rc)).join('');

        const needSection = (recTags || ingTags) ? `
            <div class="data-row notes-row">
                <span class="data-label">${esc(t('js.orders.needs_label'))}</span>
                <span class="order-needs">${recTags}${ingTags}</span>
            </div>` : '';

        const companyBadge = +r.is_company_order
            ? `<span class="badge badge-info">🏢 ${esc(r.company_name || t('js.orders.company_badge_fallback'))}</span>`
            : `<span class="badge badge-muted">${esc(t('js.orders.private_badge'))}</span>`;

        return `
        <div class="data-card ${isDone ? 'settled-card' : ''} ${isLate ? 'overdue-card' : ''}">
            <div class="data-card-header">
                <div class="data-card-title">
                    ${nameCell}
                    <div class="badge-group">
                        <span class="badge ${ORDER_PRIORITY_CLASS[prio]}">${ORDER_PRIORITY_ICON[prio]} ${esc(orderPrioText(prio))}</span>
                        ${companyBadge}
                        ${isLate ? `<span class="badge badge-danger">${esc(t('js.orders.overdue_badge'))}</span>` : ''}
                        <span class="badge ${isDone ? 'badge-success' : 'badge-info'}">${esc(isDone ? t('js.orders.done_badge') : t('js.orders.open_badge'))}</span>
                    </div>
                </div>
                <div class="card-actions">
                    ${!isDone ? `<button class="btn-icon-sm" data-action="quick-done" data-id="${r.id}" title="${esc(t('js.orders.mark_done'))}">✅</button>` : ''}
                    <button class="btn-icon-sm" data-action="open-order-modal" data-id="${r.id}" title="${esc(t('js.btn.edit'))}">✏️</button>
                    <button class="btn-icon-sm btn-danger-sm" data-action="delete-o" data-id="${r.id}" data-name="${esc(r.name)}" title="${esc(t('js.btn.delete'))}">🗑️</button>
                </div>
            </div>
            <div class="data-card-body">
                ${r.what     ? `<div class="data-row"><span class="data-label">${esc(t('js.orders.what_label'))}</span><span>${esc(r.what)}</span></div>` : ''}
                ${r.how_much ? `<div class="data-row"><span class="data-label">${esc(t('js.orders.amount_label'))}</span><span>${esc(r.how_much)}</span></div>` : ''}
                ${r.until_when ? `<div class="data-row"><span class="data-label">${esc(t('js.orders.due_label'))}</span><span class="${isLate?'text-danger':''}">${esc(r.until_when)}</span></div>` : ''}
                ${r.description ? `<div class="data-row notes-row"><span class="data-label">${esc(t('js.orders.desc_label'))}</span><span>${esc(r.description)}</span></div>` : ''}
                ${needSection}
            </div>
        </div>`;
    }).join('');
}

function renderIngTag(i) {
    const qty  = esc(i.quantity || '1');
    const name = esc(i.ingredient_name);
    if (i.ingredient_source) {
        return `<span class="ingredient-tag ingredient-src" data-action="ing-source-o" data-name="${esc(i.ingredient_name)}" data-source="${esc(i.ingredient_source)}">${qty}× ${name}<sup class="ing-asterisk">*</sup></span>`;
    }
    return `<span class="ingredient-tag">${qty}× ${name}</span>`;
}

function renderRecipeTag(rc) {
    const qty  = esc(rc.quantity || '1');
    const name = esc(rc.recipe_name);
    const loc  = rc.location_name ? ` <span class="recipe-tag-loc">@ ${esc(rc.location_name)}</span>` : '';
    return `<span class="ingredient-tag recipe-tag" data-action="show-recipe-details" data-id="${rc.recipe_id}">${qty}× ${name} <span class="recipe-plus">＋</span>${loc}</span>`;
}

const ORDER_DRILLDOWN_DEPTH = 10;

// Drilldown gegen die einheitliche Item-Liste. Funktioniert auch verschachtelt:
// Ein Rezept kann selbst Rezept-Komponenten haben, die wieder geöffnet werden können.
function showRecipeDetails(itemId, depth = 0) {
    if (depth >= ORDER_DRILLDOWN_DEPTH) { toast(t('js.toast.drilldown_depth'), 'error'); return; }
    const item = allOrderItems.find(i => i.id == itemId);
    if (!item) { toast(t('js.empty.no_items'), 'error'); return; }

    const components = (item.components || []).map(c => {
        const qty  = esc(c.quantity || '1');
        const name = esc(c.component_name);
        if (c.component_is_recipe) {
            return `<div class="info-row"><span class="info-label">${qty}× ${name}</span>
                        <button class="btn btn-secondary btn-sm" data-action="show-recipe-details" data-id="${c.component_id}" data-depth="${depth+1}">${esc(t('js.label.components'))} ＋</button>
                    </div>`;
        }
        return `<div class="info-row"><span class="info-label">${qty}× ${name}</span>
                    <span>${c.component_source ? '📍 ' + esc(c.component_source) : '<span class="text-muted">—</span>'}</span>
                </div>`;
    }).join('') || `<p class="text-muted">${esc(t('js.bio.no_components_full'))}</p>`;

    const locLine = item.location_id
        ? `<div class="info-row"><span class="info-label">${esc(t('js.label.location'))}</span><span>${esc(item.location_name||'')}</span></div>`
        : (item.work_table ? `<div class="info-row"><span class="info-label">${esc(t('js.items.table_label').replace(':',''))}</span><span>${esc(item.work_table)}</span></div>` : '');

    openModal(t('js.modal.recipe.title', item.name), `
        <div class="info-grid">
            ${locLine}
            ${item.danger_level && item.danger_level !== 'Keine' ? `<div class="info-row"><span class="info-label">${esc(t('js.label.danger_label'))}</span><span>${esc(t('enum.danger.' + item.danger_level))}</span></div>` : ''}
        </div>
        <h4 style="margin-top:1rem">${esc(t('js.label.components'))}</h4>
        <div class="info-grid">${components}</div>
        <div class="modal-footer"><button class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.close'))}</button></div>
    `);
}

async function quickDone(id) {
    const r = allOrders.find(x => x.id == id);
    if (!r) return;
    try {
        await api.put('/api/orders.php', {
            ...r, done: true,
            recipes:     (r.recipes || []).map(rc => ({ recipe_id: rc.recipe_id, quantity: rc.quantity })),
            ingredients: (r.ingredients || []).map(i => ({ ingredient_name: i.ingredient_name, quantity: i.quantity })),
        });
        toast(t('js.orders.done_toast'));
        loadAll();
    } catch(err) { toast(err.message, 'error'); }
}

// Eine einzelne Zeile im Benötigt-Bereich. Akzeptiert sowohl Recipe- als auch Ingredient-
// Strukturen aus dem Order-Response oder ein leeres Objekt für eine neue Zeile.
function buildOrderItemRow(entry = null) {
    const name = entry?.recipe_name || entry?.ingredient_name || '';
    const qty  = entry?.quantity || '1';
    return `
    <div class="ingredient-row order-item-row">
        <input type="text" name="order_item_name[]" list="o-item-datalist"
               value="${esc(name)}" placeholder="${esc(t('js.orders.ph.item'))}"
               class="ing-name-input order-item-name" data-input="update-order-row-marker" required>
        <span class="order-row-marker text-muted" style="font-size:.75rem;min-width:4.5rem;display:inline-flex;align-items:center;justify-content:center"></span>
        <input type="text" name="order_item_qty[]" value="${esc(qty)}" placeholder="${esc(t('js.placeholder.item.qty'))}" class="ing-qty-input" required>
        <button type="button" class="btn-icon-sm btn-danger-sm" data-action="remove-order-item-row" title="${esc(t('js.btn.delete'))}">✕</button>
    </div>`;
}

function updateOrderRowMarker(inp) {
    const v = (inp.value || '').trim().toLowerCase();
    const marker = inp.closest('.order-item-row').querySelector('.order-row-marker');
    if (!v) { marker.textContent = ''; return; }
    const match = allOrderItems.find(i => i.name.toLowerCase() === v);
    if (match && match.is_recipe) {
        marker.innerHTML = `<span class="recipe-plus">＋</span>&nbsp;${esc(t('js.label.recipe'))}`;
        marker.classList.remove('text-muted');
    } else if (match) {
        marker.textContent = t('js.label.ingredient');
        marker.classList.add('text-muted');
    } else {
        marker.textContent = t('js.label.new_label');
        marker.classList.add('text-muted');
    }
}

function openOrderModal(id = null) {
    const r = id ? allOrders.find(x => x.id == id) : null;
    const contOpts = allContacts.map(c =>
        `<option value="${c.id}" ${r?.contact_id==c.id?'selected':''}>${esc(c.name)}</option>`
    ).join('');
    const compOpts = allCompanies.map(co =>
        `<option value="${co.id}" ${r?.company_id==co.id?'selected':''}>${esc(co.name)}</option>`
    ).join('');
    const itemDatalist = allOrderItems
        .map(i => `<option value="${esc(i.name)}" label="${esc(i.name)}${i.is_recipe ? '  ＋ (' + t('js.label.recipe') + ')' : ''}">`)
        .join('');

    // Bestehende Rezepte und Zutaten werden zu einer einzigen Zeilen-Liste vereint.
    const orderItemRows = [
        ...(r?.recipes     || []),
        ...(r?.ingredients || []),
    ].map(entry => buildOrderItemRow(entry)).join('');

    const isCompany = r ? +r.is_company_order : 0;
    const prio      = r ? (+r.priority || 2) : 2;

    openModal(r ? t('js.orders.modal.edit') : t('js.orders.modal.new'), `
        <datalist id="o-item-datalist">${itemDatalist}</datalist>
        <form id="order-form" data-submit="save-o" data-id="${id || ''}">
            <div class="form-group">
                <label>${esc(t('js.orders.label.client'))}</label>
                <input type="text" name="name" required value="${esc(r?.name||'')}" placeholder="${esc(t('js.orders.ph.client'))}">
            </div>
            <div class="form-group">
                <label>${esc(t('js.orders.label.contact'))}</label>
                <select name="contact_id" data-change="contact-fill-name">
                    <option value="">${esc(t('js.orders.no_contact'))}</option>${contOpts}
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.orders.label.what'))}</label>
                    <input type="text" name="what" value="${esc(r?.what||'')}" placeholder="${esc(t('js.orders.ph.what'))}">
                </div>
                <div class="form-group">
                    <label>${esc(t('js.orders.label.amount'))}</label>
                    <input type="text" name="how_much" value="${esc(r?.how_much||'')}" placeholder="${esc(t('js.orders.ph.amount'))}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.orders.label.due'))}</label>
                    <input type="date" name="until_when" value="${esc(r?.until_when||'')}">
                </div>
                <div class="form-group">
                    <label>${esc(t('js.orders.label.priority'))}</label>
                    <select name="priority">
                        <option value="3" ${prio===3?'selected':''}>🟢 ${esc(orderPrioText(3))}</option>
                        <option value="2" ${prio===2?'selected':''}>🟠 ${esc(orderPrioText(2))}</option>
                        <option value="1" ${prio===1?'selected':''}>🔴 ${esc(orderPrioText(1))}</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="is_company_order" ${isCompany?'checked':''} data-change="toggle-company-field">
                    <span class="toggle-text">${esc(t('js.orders.label.company_order'))}</span>
                </label>
            </div>
            <div class="form-group" id="o-company-row" style="${isCompany?'':'display:none'}">
                <label>${esc(t('js.orders.label.our_company'))}</label>
                <select name="company_id">
                    <option value="">${esc(t('js.orders.choose_company'))}</option>${compOpts}
                </select>
                <div class="form-hint">${esc(t('js.orders.company_hint'))}</div>
            </div>
            <div class="form-group">
                <label>${esc(t('js.orders.label.description'))}</label>
                <textarea name="description" rows="2" placeholder="${esc(t('js.orders.ph.description'))}">${esc(r?.description||'')}</textarea>
            </div>

            <div class="form-group">
                <label>${esc(t('js.orders.label.needs'))}</label>
                <div class="form-hint">${esc(t('js.orders.needs_hint'))}</div>
                <div id="o-item-rows">${orderItemRows}</div>
                <button type="button" class="btn btn-secondary btn-sm mt-2" data-action="add-order-item-row">${esc(t('js.orders.add_item'))}</button>
            </div>

            <div class="form-group">
                <label class="toggle-label">
                    <input type="checkbox" name="done" ${+r?.done?'checked':''}>
                    <span class="toggle-text">${esc(t('js.orders.label.done'))}</span>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.cancel'))}</button>
                <button type="submit" class="btn btn-primary">${esc(r ? t('js.btn.save') : t('js.btn.create'))}</button>
            </div>
        </form>
    `);
    // Marker für vorhandene Zeilen initial setzen
    document.querySelectorAll('.order-item-row .order-item-name').forEach(updateOrderRowMarker);
}

function toggleCompanyField(checked) {
    const row = document.getElementById('o-company-row');
    if (row) row.style.display = checked ? '' : 'none';
    if (!checked) {
        const sel = row?.querySelector('[name=company_id]');
        if (sel) sel.value = '';
    }
}

function addOrderItemRow() {
    const wrap = document.createElement('div');
    wrap.innerHTML = buildOrderItemRow();
    const el = wrap.firstElementChild;
    document.getElementById('o-item-rows').appendChild(el);
    updateOrderRowMarker(el.querySelector('.order-item-name'));
}

async function saveO(e, id) {
    e.preventDefault();
    const f = e.target;

    // Eine vereinheitlichte Zeile pro Item; serverseitig erwartet die API noch
    // die Trennung in recipes[]/ingredients[] — die machen wir hier client-seitig
    // anhand der is_recipe-Klassifikation aus allOrderItems.
    const names = [...f.querySelectorAll('[name="order_item_name[]"]')].map(x => x.value.trim());
    const qtys  = [...f.querySelectorAll('[name="order_item_qty[]"]')].map(x => (x.value || '').trim() || '1');
    const recipes = [];
    const ingredients = [];
    for (let i = 0; i < names.length; i++) {
        const name = names[i];
        if (!name) continue;
        const qty   = qtys[i] || '1';
        const match = allOrderItems.find(it => it.name.toLowerCase() === name.toLowerCase());
        if (match && match.is_recipe) {
            recipes.push({ recipe_id: match.id, quantity: qty });
        } else {
            // Existing ingredient OR brand-new name — Backend legt fehlende als Zutat an.
            ingredients.push({ ingredient_name: name, quantity: qty });
        }
    }

    const isCompany = f.is_company_order.checked;
    const companyId = isCompany ? (f.company_id.value || null) : null;
    if (isCompany && !companyId) {
        toast(t('js.orders.toast.choose_company'), 'error');
        return;
    }

    const data = {
        name:             f.name.value,
        what:             f.what.value,
        how_much:         f.how_much.value,
        until_when:       f.until_when.value,
        description:      f.description.value,
        done:             f.done.checked,
        priority:         parseInt(f.priority.value, 10) || 2,
        is_company_order: isCompany,
        company_id:       companyId,
        contact_id:       f.contact_id.value || null,
        recipes,
        ingredients,
    };
    try {
        if (id) { await api.put('/api/orders.php', { ...data, id }); toast(t('js.toast.order_saved')); }
        else     { await api.post('/api/orders.php', data); toast(t('js.toast.order_created')); }
        closeModal(); loadAll();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteO(id, name) {
    if (!confirm(t('js.orders.delete_warn', name))) return;
    try { await api.delete('/api/orders.php', { id }); toast(t('js.toast.deleted')); loadAll(); }
    catch(err) { toast(err.message, 'error'); }
}

function showContactById(id) {
    const c = allContacts.find(x => x.id == id);
    if (c) showContactPreview(c);
}

function showIngSource(anchorEl, name, source) {
    document.querySelectorAll('.ing-src-popup').forEach(p => p.remove());
    const popup = document.createElement('div');
    popup.className = 'ing-src-popup';
    popup.innerHTML = `<strong>${esc(name)}</strong><br>📍 ${esc(source)}`;
    const rect = anchorEl.getBoundingClientRect();
    popup.style.position = 'fixed';
    popup.style.top  = (rect.bottom + 6) + 'px';
    popup.style.left = rect.left + 'px';
    document.body.appendChild(popup);
    setTimeout(() => document.addEventListener('click', () => popup.remove(), { once: true }), 10);
}

// ===== Firmen-Manager =====
function openCompaniesManager() {
    const rows = allCompanies.map(co =>
        `<div class="ing-catalog-row">
            <div style="flex:1">
                <strong>${esc(co.name)}</strong>
                ${co.notes ? `<span class="ing-source"> · ${esc(co.notes)}</span>` : ''}
            </div>
            <button class="btn btn-sm btn-secondary" data-action="edit-company" data-id="${co.id}">✏️</button>
            <button class="btn-icon-sm btn-danger-sm" data-action="delete-company" data-id="${co.id}" data-name="${esc(co.name)}">🗑️</button>
        </div>`
    ).join('') || `<p class="text-muted">${esc(t('js.companies.empty'))}</p>`;

    openModal(t('js.companies.title'), `
        <div style="margin-bottom:1rem">${rows}</div>
        <div style="border-top:1px solid var(--border);padding-top:.75rem;display:flex;flex-direction:column;gap:.5rem">
            <div class="form-row">
                <input type="text" id="new-co-name" placeholder="${esc(t('js.companies.ph.name'))}">
                <input type="text" id="new-co-notes" placeholder="${esc(t('js.companies.ph.notes'))}">
            </div>
            <button class="btn btn-primary btn-sm" data-action="add-company">${esc(t('js.companies.add'))}</button>
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.close'))}</button></div>
    `);
}

function editCompany(id) {
    const co = allCompanies.find(c => c.id == id);
    if (!co) return;
    openModal(t('js.companies.edit_title', co.name), `
        <form data-submit="save-company-edit" data-id="${id}">
            <div class="form-group">
                <label>${esc(t('js.label.name_required'))}</label>
                <input type="text" name="name" required value="${esc(co.name)}">
            </div>
            <div class="form-group">
                <label>${esc(t('js.label.notes'))}</label>
                <input type="text" name="notes" value="${esc(co.notes||'')}" placeholder="${esc(t('js.companies.notes_ph'))}">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="open-companies-manager">${esc(t('js.companies.back'))}</button>
                <button type="submit" class="btn btn-primary">${esc(t('js.btn.save'))}</button>
            </div>
        </form>
    `);
}

async function saveCompanyEdit(e, id) {
    e.preventDefault();
    const f = e.target;
    try {
        await api.post('/api/orders.php', { action: 'update_company', id, name: f.name.value, notes: f.notes.value });
        toast(t('js.toast.company_saved'));
        await reloadCompanies();
        openCompaniesManager();
    } catch(err) { toast(err.message, 'error'); }
}

async function addCompany() {
    const name  = document.getElementById('new-co-name').value.trim();
    const notes = document.getElementById('new-co-notes').value.trim();
    if (!name) { toast(t('js.companies.name_req'), 'error'); return; }
    try {
        await api.post('/api/orders.php', { action: 'add_company', name, notes });
        toast(t('js.toast.company_added'));
        await reloadCompanies();
        openCompaniesManager();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteCompany(id, name) {
    if (!confirm(t('js.companies.delete_warn', name))) return;
    try {
        await api.post('/api/orders.php', { action: 'delete_company', id });
        toast(t('js.toast.company_deleted'));
        await reloadCompanies();
        await reloadOrders();
        openCompaniesManager();
    } catch(err) { toast(err.message, 'error'); }
}

async function reloadCompanies() {
    allCompanies = await api.get('/api/orders.php?action=companies');
    refreshCompanyFilterOptions();
}
async function reloadOrders() {
    allOrders = await api.get('/api/orders.php');
    updateODoneCount();
    applyOFilters();
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
    registerAction('open-order-modal',        (e, el, ds) => openOrderModal(ds.id ? parseInt(ds.id, 10) : null));
    registerAction('delete-o',                (e, el, ds) => deleteO(parseInt(ds.id, 10), ds.name || ''));
    registerAction('save-o',                  (e, el, ds) => saveO(e, ds.id ? parseInt(ds.id, 10) : null));
    registerAction('quick-done',              (e, el, ds) => quickDone(parseInt(ds.id, 10)));
    registerAction('filter-o',                (e, el, ds) => filterO(ds.filter, el));
    registerAction('filter-o-type',           (e, el, ds) => filterOType(ds.type, el));
    registerAction('apply-o-filters',         () => applyOFilters());
    registerAction('show-contact',            (e, el, ds) => showContactById(parseInt(ds.contactId, 10)));
    registerAction('show-recipe-details',     (e, el, ds) => showRecipeDetails(parseInt(ds.id, 10), parseInt(ds.depth || 0, 10)));
    registerAction('ing-source-o',            (e, el, ds) => showIngSource(el, ds.name, ds.source));
    registerAction('add-order-item-row',      () => addOrderItemRow());
    registerAction('remove-order-item-row',   (e, el) => el.closest('.order-item-row').remove());
    registerAction('update-order-row-marker', (e) => updateOrderRowMarker(e.target));
    registerAction('contact-fill-name',       contactFillName);
    registerAction('toggle-company-field',    (e) => toggleCompanyField(e.target.checked));
    registerAction('open-companies-manager',  () => openCompaniesManager());
    registerAction('edit-company',            (e, el, ds) => editCompany(parseInt(ds.id, 10)));
    registerAction('delete-company',          (e, el, ds) => deleteCompany(parseInt(ds.id, 10), ds.name || ''));
    registerAction('add-company',             () => addCompany());
    registerAction('save-company-edit',       (e, el, ds) => saveCompanyEdit(e, parseInt(ds.id, 10)));
    loadAll();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
