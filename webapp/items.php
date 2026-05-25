<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAuth();
$page = 'items';
require_once __DIR__ . '/includes/header.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= h(__('page.items.title')) ?></h1>
            <p class="page-subtitle"><?= h(__('page.items.subtitle')) ?></p>
        </div>
        <div class="page-actions">
            <button class="btn btn-secondary" data-action="open-categories-modal" title="<?= h(__('js.modal.categories.title')) ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon-svg"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                <?= h(__('page.items.categories')) ?>
            </button>
            <button class="btn btn-primary" data-action="open-item-modal">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon-svg"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?= h(__('page.items.add')) ?>
            </button>
        </div>
    </div>
    <div class="filter-row">
        <button class="filter-btn active" data-tab="all"         data-action="set-item-tab"><?= h(__('page.items.tab.all')) ?></button>
        <button class="filter-btn"        data-tab="recipes"     data-action="set-item-tab"><?= h(__('page.items.tab.recipes')) ?></button>
        <button class="filter-btn"        data-tab="ingredients" data-action="set-item-tab"><?= h(__('page.items.tab.ingredients')) ?></button>
        <div class="search-inline">
            <input type="search" id="item-search" placeholder="<?= h(__('page.items.search_ph')) ?>" data-input="apply-item-filters">
        </div>
    </div>
    <div id="category-filter-row" class="filter-row category-filter-row"></div>
    <div id="items-list" class="recipes-list">
        <div class="loading-spinner"></div>
    </div>
</main>

<script nonce="<?= h(cspNonce()) ?>">
let allItems       = [];
let allLocations   = [];
let allCategories  = [];
let activeItemTab  = 'all';
let activeCategory = 'all';   // 'all' | 'none' | <category id>
let collapsedCats  = new Set(); // Sektionen, die aktuell eingeklappt sind (Key = category id oder 'none')
const ITEM_DEPTH_LIMIT = 10;

// DB-Werte bleiben deutsch ('Keine','Gering','Mittel','Hoch') — UI-Label wird übersetzt.
const DANGER_COLORS = { Keine: 'badge-muted', Gering: 'badge-success', Mittel: 'badge-warning', Hoch: 'badge-danger' };
const DANGER_ICONS  = { Keine: '🟢', Gering: '🟡', Mittel: '🟠', Hoch: '🔴' };
function dangerLabel(level) { return t('enum.danger.' + level); }

async function loadAll() {
    if (!window.CURRENT_CHAR_ID) { document.getElementById('items-list').innerHTML = ''; return; }
    try {
        [allItems, allLocations, allCategories] = await Promise.all([
            api.get('/api/items.php'),
            api.get('/api/locations.php'),
            api.get('/api/item_categories.php'),
        ]);
        renderCategoryFilters();
        applyItemFilters();
    } catch(e) { showError('items-list', e.message); }
}

function setItemTab(tab, btn) {
    activeItemTab = tab;
    btn.parentElement.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyItemFilters();
}

function setCategoryFilter(key, btn) {
    activeCategory = key;
    btn.parentElement.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyItemFilters();
}

function renderCategoryFilters() {
    const row = document.getElementById('category-filter-row');
    if (!row) return;
    if (!allCategories.length) {
        // Ohne Kategorien lohnt die Filter-Zeile nicht — komplett ausblenden.
        row.innerHTML = '';
        row.style.display = 'none';
        activeCategory = 'all';
        return;
    }
    row.style.display = '';
    const hasUncategorized = allItems.some(i => !i.category_id);
    const chips = [
        `<button class="filter-btn ${activeCategory==='all'?'active':''}" data-action="set-category-filter" data-key="all">${esc(t('page.items.cat.all'))}</button>`,
        ...allCategories.map(c => {
            const count = allItems.filter(i => +i.category_id === +c.id).length;
            return `<button class="filter-btn ${activeCategory==c.id?'active':''}" data-action="set-category-filter" data-key="${c.id}">${esc(c.name)} <span class="cat-count">${count}</span></button>`;
        }),
        hasUncategorized
            ? `<button class="filter-btn ${activeCategory==='none'?'active':''}" data-action="set-category-filter" data-key="none">${esc(t('page.items.cat.none'))}</button>`
            : '',
    ];
    row.innerHTML = chips.join('');
}

// Liefert true, wenn das Item (oder eines seiner verschachtelten Komponenten) den Suchbegriff matcht.
// Iterativ mit Tiefenlimit, um theoretische Zyklen abzufangen.
function itemMatchesSearch(item, q, depth = 0, seen = new Set()) {
    if (!q) return true;
    if (depth >= ITEM_DEPTH_LIMIT) return false;
    if (seen.has(item.id)) return false;
    seen.add(item.id);
    if (fuzzyMatch(item.name, q)) return true;
    if (item.source && fuzzyMatch(item.source, q)) return true;
    if (item.components && item.components.length) {
        for (const c of item.components) {
            const child = allItems.find(x => x.id == c.component_id);
            if (!child) continue;
            if (itemMatchesSearch(child, q, depth + 1, seen)) return true;
        }
    }
    return false;
}

function applyItemFilters() {
    const q = (document.getElementById('item-search')?.value || '').trim();
    let list = allItems;
    if (activeItemTab === 'recipes')          list = list.filter(i => i.is_recipe);
    else if (activeItemTab === 'ingredients') list = list.filter(i => !i.is_recipe);
    if (activeCategory === 'none')      list = list.filter(i => !i.category_id);
    else if (activeCategory !== 'all')  list = list.filter(i => +i.category_id === +activeCategory);
    if (q) list = list.filter(i => itemMatchesSearch(i, q));
    renderItems(list);
}

function renderItems(items) {
    const el = document.getElementById('items-list');
    if (!items.length) {
        el.innerHTML = `<div class="empty-state"><div class="empty-icon">📦</div><p>${esc(t('js.empty.no_items'))}</p></div>`;
        return;
    }

    // Wenn nach einer konkreten Kategorie gefiltert wird, brauchen wir keine Gruppen-Header.
    // Auch ohne angelegte Kategorien: flache Liste.
    if (activeCategory !== 'all' || !allCategories.length) {
        el.innerHTML = items.map(renderItemCard).join('');
        return;
    }

    // Gruppieren nach Kategorie. Reihenfolge: angelegte Kategorien alphabetisch, "Ohne Kategorie" zuletzt.
    const groups = new Map();
    for (const c of allCategories) groups.set(String(c.id), { name: c.name, key: String(c.id), items: [] });
    groups.set('none', { name: t('page.items.cat.none'), key: 'none', items: [] });
    for (const it of items) {
        const k = it.category_id ? String(it.category_id) : 'none';
        if (groups.has(k)) groups.get(k).items.push(it);
        else groups.get('none').items.push(it); // Fallback bei verwaister category_id
    }

    const sections = [];
    for (const g of groups.values()) {
        if (!g.items.length) continue;
        const collapsed = collapsedCats.has(g.key);
        sections.push(`
            <div class="cat-section ${collapsed?'collapsed':''}" data-cat-key="${g.key}">
                <button type="button" class="cat-section-header" data-action="toggle-cat-section" data-key="${g.key}">
                    <span class="cat-toggle-arrow">${collapsed?'▶':'▼'}</span>
                    <span class="cat-section-title">${esc(g.name)}</span>
                    <span class="cat-section-count">${g.items.length}</span>
                </button>
                <div class="cat-section-body">${g.items.map(renderItemCard).join('')}</div>
            </div>
        `);
    }
    el.innerHTML = sections.join('') || `<div class="empty-state"><div class="empty-icon">📦</div><p>${esc(t('js.empty.no_items'))}</p></div>`;
}

function toggleCatSection(key) {
    if (collapsedCats.has(key)) collapsedCats.delete(key);
    else collapsedCats.add(key);
    applyItemFilters();
}

function renderItemCard(item) {
    const isRecipe = !!item.is_recipe;
    const danger   = item.danger_level || 'Keine';
    const kindCls   = isRecipe ? 'item-card-recipe' : 'item-card-ingredient';
    const dangerCls = isRecipe ? `danger-${danger.toLowerCase()}` : 'danger-keine';
    const dangerBadge = isRecipe && danger !== 'Keine'
        ? `<span class="badge ${DANGER_COLORS[danger]||'badge-muted'}">${DANGER_ICONS[danger]||''} ${esc(dangerLabel(danger))}</span>`
        : '';
    // Bei Rezepten zeigt der CSS-Pseudo-Inhalt das große REZEPT-Label, also kein zusätzliches Inline-Badge nötig.
    const kindBadge = isRecipe ? '' : `<span class="badge badge-muted">${esc(t('js.label.ingredient'))}</span>`;
    const catBadge  = item.category_name
        ? `<span class="badge badge-cat">${esc(item.category_name)}</span>`
        : '';

    const loc = isRecipe
        ? (item.location_id
            ? `<button class="link-btn" data-action="show-location-info" data-id="${item.location_id}">${esc(item.location_name||'')}</button>`
            : (item.work_table ? `<span>${esc(item.work_table)}</span>` : '<span class="text-muted">—</span>'))
        : '';

    const sourceLine = item.source
        ? `<div class="recipe-meta"><span class="recipe-table-label">${esc(t('js.items.source_label'))}</span> <span>${esc(item.source)}</span></div>`
        : '';

    const components = (item.components || []).map(c => renderComponentTag(c)).join('');
    const componentSection = isRecipe
        ? `<div class="recipe-ingredients">${components || `<span class="text-muted">${esc(t('js.items.no_components_card'))}</span>`}</div>`
        : '';
    const locSection = isRecipe
        ? `<div class="recipe-meta"><span class="recipe-table-label">${esc(t('js.items.table_label'))}</span> ${loc}</div>`
        : '';

    return `
    <div class="recipe-card ${kindCls} ${dangerCls}">
        <div class="recipe-card-header">
            <div class="recipe-title-row">
                <h3 class="recipe-name">${esc(item.name)}</h3>
                ${kindBadge}
                ${dangerBadge}
                ${catBadge}
            </div>
            <div class="card-actions">
                <button class="btn-icon-sm" data-action="open-item-modal" data-id="${item.id}" title="${esc(t('js.btn.edit'))}">✏️</button>
                <button class="btn-icon-sm btn-danger-sm" data-action="delete-item" data-id="${item.id}" data-name="${esc(item.name)}" title="${esc(t('js.btn.delete'))}">🗑️</button>
            </div>
        </div>
        ${sourceLine}
        ${locSection}
        ${componentSection}
    </div>`;
}

// Eine einzelne Komponente als Tag rendern. Klick auf Rezept-Komponenten → Drilldown.
function renderComponentTag(c) {
    const qty  = esc(c.quantity || '1');
    const name = esc(c.component_name);
    if (c.component_is_recipe) {
        return `<span class="ingredient-tag recipe-tag" data-action="item-drilldown" data-id="${c.component_id}">${qty}× ${name} <span class="recipe-plus">＋</span></span>`;
    }
    if (c.component_source) {
        return `<span class="ingredient-tag ingredient-src" data-action="ing-source" data-name="${esc(c.component_name)}" data-source="${esc(c.component_source)}">${qty}× ${name}<sup class="ing-asterisk">*</sup></span>`;
    }
    return `<span class="ingredient-tag">${qty}× ${name}</span>`;
}

// Drilldown-Popup: zeigt die Komponenten eines Rezept-Items, mit Tiefenlimit.
function showItemDrilldown(itemId, depth = 0) {
    const item = allItems.find(i => i.id == itemId);
    if (!item) return;
    if (depth >= ITEM_DEPTH_LIMIT) {
        toast(t('js.toast.drilldown_depth'), 'error'); return;
    }

    const locLine = item.location_id
        ? `<div class="info-row"><span class="info-label">${esc(t('js.label.location'))}</span><span>${esc(item.location_name||'')}</span></div>`
        : (item.work_table ? `<div class="info-row"><span class="info-label">${esc(t('js.items.table_label').replace(':',''))}</span><span>${esc(item.work_table)}</span></div>` : '');

    const dangerLine = item.danger_level && item.danger_level !== 'Keine'
        ? `<div class="info-row"><span class="info-label">${esc(t('js.label.danger_label'))}</span><span>${esc(dangerLabel(item.danger_level))}</span></div>`
        : '';

    const components = (item.components || []).map(c => {
        const qty  = esc(c.quantity || '1');
        const name = esc(c.component_name);
        if (c.component_is_recipe) {
            return `<div class="info-row"><span class="info-label">${qty}× ${name}</span>
                        <button class="btn btn-secondary btn-sm" data-action="item-drilldown" data-id="${c.component_id}" data-depth="${depth+1}">${esc(t('js.label.components'))} ＋</button>
                    </div>`;
        }
        return `<div class="info-row"><span class="info-label">${qty}× ${name}</span>
                    <span>${c.component_source ? '📍 ' + esc(c.component_source) : '<span class="text-muted">—</span>'}</span>
                </div>`;
    }).join('') || `<p class="text-muted">${esc(t('js.bio.no_components_full'))}</p>`;

    openModal(t('js.modal.recipe.title', item.name), `
        <div class="info-grid">${locLine}${dangerLine}</div>
        <h4 style="margin-top:1rem">${esc(t('js.label.components'))}</h4>
        <div class="info-grid">${components}</div>
        <div class="modal-footer">
            <button class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.close'))}</button>
            <button class="btn btn-primary" data-action="open-item-modal" data-id="${item.id}">${esc(t('js.btn.edit'))}</button>
        </div>
    `);
}

function showLocationInfo(locId) {
    const loc = allLocations.find(l => l.id == locId);
    if (!loc) return;
    openModal(t('js.modal.location.title', loc.name), `
        <div class="info-grid">
            <div class="info-row"><span class="info-label">${esc(t('js.char.name_simple').replace(' *',''))}</span><span>${esc(loc.name)}</span></div>
            ${loc.zip ? `<div class="info-row"><span class="info-label">${esc(t('js.label.zip'))}</span><span>${esc(loc.zip)}</span></div>` : ''}
            <div class="info-row"><span class="info-label">${esc(t('js.label.illegal'))}</span><span class="badge ${+loc.illegal ? 'badge-danger' : 'badge-muted'}">${+loc.illegal ? esc(t('js.label.illegal_yes')) : esc(t('js.label.illegal_no'))}</span></div>
            ${loc.requires ? `<div class="info-row"><span class="info-label">${esc(t('js.label.requires'))}</span><span>${esc(loc.requires)}</span></div>` : ''}
            ${loc.description ? `<div class="info-row"><span class="info-label">${esc(t('js.label.description'))}</span><span>${esc(loc.description)}</span></div>` : ''}
        </div>
        <div class="modal-footer"><button class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.close'))}</button></div>
    `);
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

// === Item-Modal (anlegen oder bearbeiten) ===
function openItemModal(id = null) {
    const item = id ? allItems.find(x => x.id == id) : null;
    const locOptions = allLocations.map(l =>
        `<option value="${l.id}" ${item?.location_id == l.id ? 'selected' : ''}>${esc(l.name)}</option>`
    ).join('');
    const catOptions = allCategories.map(c =>
        `<option value="${c.id}" ${item?.category_id == c.id ? 'selected' : ''}>${esc(c.name)}</option>`
    ).join('');
    const datalistOpts = allItems
        .filter(i => !item || i.id != item.id) // sich selbst nicht als Komponente vorschlagen
        .map(i => `<option value="${esc(i.name)}" label="${esc(i.name)}${i.is_recipe ? '  ＋ (' + t('js.label.recipe') + ')' : ''}">`)
        .join('');
    const compRows = (item?.components || []).map(c => buildComponentRow(c)).join('');

    openModal(item ? t('js.modal.item.edit', item.name) : t('js.modal.item.new'), `
        <datalist id="item-component-dl">${datalistOpts}</datalist>
        <form id="item-form" data-submit="save-item" data-id="${id || ''}">
            <div class="form-group">
                <label>${esc(t('js.label.name_required'))}</label>
                <input type="text" name="name" required value="${esc(item?.name||'')}" placeholder="${esc(t('js.placeholder.item.name'))}">
            </div>
            <div class="form-group">
                <label>${esc(t('js.label.category'))}</label>
                <div class="cat-select-row">
                    <select name="category_id" data-change="on-cat-select-change">
                        <option value="">${esc(t('js.select.no_category'))}</option>
                        ${catOptions}
                        <option value="__new__">${esc(t('js.select.new_category'))}</option>
                    </select>
                    <input type="text" name="category_new" placeholder="${esc(t('js.placeholder.item.category_new'))}" style="display:none" maxlength="60">
                </div>
                <div class="form-hint">${esc(t('js.items.cat.hint'))}</div>
            </div>
            <div class="form-group">
                <label>${esc(t('js.label.source'))}</label>
                <input type="text" name="source" value="${esc(item?.source||'')}" placeholder="${esc(t('js.placeholder.item.source'))}">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.label.work_table'))}</label>
                    <select name="location_id">
                        <option value="">${esc(t('js.select.no_location'))}</option>
                        ${locOptions}
                    </select>
                    <div class="form-hint">${esc(t('js.label.work_table_free'))}</div>
                    <input type="text" name="work_table" value="${esc(item?.work_table||'')}" placeholder="${esc(t('js.placeholder.item.work_table'))}">
                </div>
                <div class="form-group">
                    <label>${esc(t('js.label.danger_level'))}</label>
                    <select name="danger_level">
                        ${['Keine','Gering','Mittel','Hoch'].map(d =>
                            `<option value="${d}" ${item?.danger_level===d?'selected':''}>${esc(dangerLabel(d))}</option>`
                        ).join('')}
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>${esc(t('js.label.components'))}</label>
                <div class="form-hint">${esc(t('js.items.comp.hint'))}</div>
                <div id="item-comp-rows">${compRows}</div>
                <button type="button" class="btn btn-secondary btn-sm mt-2" data-action="add-item-comp-row">${esc(t('js.btn.add_component'))}</button>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.cancel'))}</button>
                <button type="submit" class="btn btn-primary">${esc(item ? t('js.btn.save') : t('js.btn.create'))}</button>
            </div>
        </form>
    `);
    // Marker für vorhandene Zeilen einmal initial setzen
    document.querySelectorAll('.item-comp-row .comp-name-input').forEach(updateCompRowMarker);
}

function onCatSelectChange(e) {
    const sel  = e.target;
    const form = sel.form;
    const txt  = form.querySelector('input[name="category_new"]');
    if (!txt) return;
    if (sel.value === '__new__') {
        txt.style.display = '';
        txt.required = true;
        txt.focus();
    } else {
        txt.style.display = 'none';
        txt.required = false;
        txt.value = '';
    }
}

function buildComponentRow(c = null) {
    return `
    <div class="ingredient-row item-comp-row">
        <input type="text" name="component_name[]" list="item-component-dl"
               value="${esc(c?.component_name||'')}" placeholder="${esc(t('js.placeholder.item.component'))}"
               class="ing-name-input comp-name-input" data-input="update-comp-row-marker" required>
        <span class="comp-row-marker text-muted" style="font-size:.75rem;min-width:4.5rem;display:inline-flex;align-items:center;justify-content:center"></span>
        <input type="text" name="component_qty[]" value="${esc(c?.quantity||'1')}" placeholder="${esc(t('js.placeholder.item.qty'))}" class="ing-qty-input" required>
        <button type="button" class="btn-icon-sm btn-danger-sm" data-action="remove-comp-row" title="${esc(t('js.btn.remove') || 'Remove')}">✕</button>
    </div>`;
}

function addItemCompRow() {
    const row = document.createElement('div');
    row.innerHTML = buildComponentRow();
    const el = row.firstElementChild;
    document.getElementById('item-comp-rows').appendChild(el);
    updateCompRowMarker(el.querySelector('.comp-name-input'));
}

function updateCompRowMarker(inp) {
    const v = (inp.value || '').trim().toLowerCase();
    const marker = inp.closest('.item-comp-row').querySelector('.comp-row-marker');
    if (!v) { marker.textContent = ''; return; }
    const match = allItems.find(i => i.name.toLowerCase() === v);
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

async function saveItem(e, id) {
    e.preventDefault();
    const f = e.target;
    const names = [...f.querySelectorAll('[name="component_name[]"]')].map(x => x.value.trim());
    const qtys  = [...f.querySelectorAll('[name="component_qty[]"]')].map(x => x.value.trim());
    const components = names.map((n, i) => ({ name: n, quantity: qtys[i] || '1' })).filter(c => c.name);

    // Kategorie: entweder vorhandene ID oder neuer Name. Beides Strings → Server normalisiert.
    let category_id = null, category_name = null;
    if (f.category_id.value === '__new__') {
        category_name = (f.category_new?.value || '').trim() || null;
    } else if (f.category_id.value) {
        category_id = parseInt(f.category_id.value, 10);
    }

    const data = {
        name:          f.name.value,
        source:        f.source.value,
        location_id:   f.location_id.value || null,
        work_table:    f.work_table.value,
        danger_level:  f.danger_level.value,
        category_id,
        category_name,
        components,
    };
    try {
        if (id) { await api.put('/api/items.php', { ...data, id }); toast(t('js.toast.item_saved')); }
        else    { await api.post('/api/items.php', data);            toast(t('js.toast.item_created')); }
        closeModal();
        loadAll();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteItem(id, name) {
    const item = allItems.find(i => i.id == id);
    let warn = t('js.items.delete_warn', name);
    if (item?.is_recipe) warn += t('js.items.delete_warn_recipe');
    if (!confirm(warn)) return;
    try { await api.delete('/api/items.php', { id }); toast(t('js.toast.deleted')); loadAll(); }
    catch(err) { toast(err.message, 'error'); }
}

// === Kategorien-Verwaltung ===
function openCategoriesModal() {
    const rows = allCategories.length
        ? allCategories.map(c => `
            <div class="info-row cat-manage-row" data-id="${c.id}">
                <input type="text" class="cat-rename-input" value="${esc(c.name)}" maxlength="60">
                <span class="text-muted cat-manage-count">${esc(t('js.items.product_count', c.item_count||0))}</span>
                <button class="btn btn-secondary btn-sm" data-action="rename-category" data-id="${c.id}">${esc(t('js.btn.save'))}</button>
                <button class="btn-icon-sm btn-danger-sm" data-action="delete-category" data-id="${c.id}" data-name="${esc(c.name)}" title="${esc(t('js.btn.delete'))}">🗑️</button>
            </div>
        `).join('')
        : `<p class="text-muted">${esc(t('js.empty.no_categories'))}</p>`;

    openModal(t('js.modal.categories.title'), `
        <form data-submit="create-category" class="cat-create-form">
            <div class="form-group">
                <label>${esc(t('js.items.cat.new'))}</label>
                <div class="cat-select-row">
                    <input type="text" name="name" required placeholder="${esc(t('js.placeholder.cat.new'))}" maxlength="60">
                    <button type="submit" class="btn btn-primary btn-sm">${esc(t('js.btn.create_short'))}</button>
                </div>
            </div>
        </form>
        <h4 style="margin-top:1rem">${esc(t('js.items.cat.exists'))}</h4>
        <div class="info-grid">${rows}</div>
        <div class="modal-footer">
            <button class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.close'))}</button>
        </div>
    `);
}

async function createCategory(e) {
    e.preventDefault();
    const f = e.target;
    const name = (f.name.value || '').trim();
    if (!name) return;
    try {
        await api.post('/api/item_categories.php', { name });
        toast(t('js.toast.category_created'));
        await loadAll();
        openCategoriesModal();
    } catch(err) { toast(err.message, 'error'); }
}

async function renameCategory(id) {
    const row = document.querySelector(`.cat-manage-row[data-id="${id}"]`);
    if (!row) return;
    const name = (row.querySelector('.cat-rename-input').value || '').trim();
    if (!name) { toast(t('js.toast.name_required'), 'error'); return; }
    try {
        await api.put('/api/item_categories.php', { id, name });
        toast(t('js.toast.category_renamed'));
        await loadAll();
        openCategoriesModal();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteCategory(id, name) {
    const cat = allCategories.find(c => c.id == id);
    const cnt = cat?.item_count || 0;
    let warn = t('js.items.cat.delete_warn', name);
    if (cnt > 0) warn += t('js.items.cat.delete_count', cnt);
    if (!confirm(warn)) return;
    try {
        await api.delete('/api/item_categories.php', { id });
        toast(t('js.toast.category_deleted'));
        // Filter zurücksetzen, falls aktuell auf der gelöschten Kategorie.
        if (String(activeCategory) === String(id)) activeCategory = 'all';
        await loadAll();
        openCategoriesModal();
    } catch(err) { toast(err.message, 'error'); }
}

document.addEventListener('DOMContentLoaded', () => {
    registerAction('open-item-modal',         (e, el, ds) => openItemModal(ds.id ? parseInt(ds.id, 10) : null));
    registerAction('delete-item',             (e, el, ds) => deleteItem(parseInt(ds.id, 10), ds.name || ''));
    registerAction('save-item',               (e, el, ds) => saveItem(e, ds.id ? parseInt(ds.id, 10) : null));
    registerAction('set-item-tab',            (e, el, ds) => setItemTab(ds.tab, el));
    registerAction('set-category-filter',     (e, el, ds) => setCategoryFilter(ds.key, el));
    registerAction('toggle-cat-section',      (e, el, ds) => toggleCatSection(ds.key));
    registerAction('apply-item-filters',      () => applyItemFilters());
    registerAction('show-location-info',      (e, el, ds) => showLocationInfo(parseInt(ds.id, 10)));
    registerAction('item-drilldown',          (e, el, ds) => showItemDrilldown(parseInt(ds.id, 10), parseInt(ds.depth || 0, 10)));
    registerAction('ing-source',              (e, el, ds) => showIngSource(el, ds.name, ds.source));
    registerAction('add-item-comp-row',       () => addItemCompRow());
    registerAction('remove-comp-row',         (e, el) => el.closest('.item-comp-row').remove());
    registerAction('update-comp-row-marker',  (e) => updateCompRowMarker(e.target)); // data-input
    registerAction('on-cat-select-change',    (e) => onCatSelectChange(e));          // data-change
    registerAction('open-categories-modal',   () => openCategoriesModal());
    registerAction('create-category',         (e) => createCategory(e));
    registerAction('rename-category',         (e, el, ds) => renameCategory(parseInt(ds.id, 10)));
    registerAction('delete-category',         (e, el, ds) => deleteCategory(parseInt(ds.id, 10), ds.name || ''));
    loadAll();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
