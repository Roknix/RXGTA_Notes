/* ===== Global App JS ===== */

// ===== i18n Helper =====
// window.I18N + window.LOCALE werden in header.php gesetzt. t(key, ...args) sucht
// den Key, fällt bei Miss auf den Key selbst zurück (sichtbar in der UI als Hinweis).
// Format-Tokens (%s, %1$s) werden positional ersetzt — kein eval, nur String-Replace.
function t(key, ...args) {
    const tbl = window.I18N || {};
    let msg = tbl[key];
    if (msg === undefined) return key;
    if (args.length === 0) return msg;
    // Reihenfolge: erst positionale (%1$s, %2$s), dann sequentielle (%s).
    msg = msg.replace(/%(\d+)\$s/g, (_, n) => {
        const v = args[parseInt(n, 10) - 1];
        return v === undefined ? '' : String(v);
    });
    let i = 0;
    msg = msg.replace(/%s/g, () => {
        const v = args[i++];
        return v === undefined ? '' : String(v);
    });
    return msg;
}

// Datums-Helper, einmal zentral. Nutzt window.LOCALE → de-DE bzw. en-US.
function fmtDateLocalized(ts, opts) {
    if (!ts) return '—';
    const loc = window.LOCALE === 'de' ? 'de-DE' : 'en-US';
    return new Date(ts*1000).toLocaleString(loc, opts || { dateStyle: 'medium', timeStyle: 'short' });
}

// ===== Event-Delegation =====
// Ersetzt inline `onclick="…"` / `onchange="…"` / `onsubmit="…"` etc.
// Pattern in HTML: <button data-action="action-name" data-foo="…">…</button>
// Handler werden via registerAction(name, fn) registriert. Sie bekommen
// (event, target, dataset) und lesen ihre Argumente aus dem dataset.
//
// Convention pro Event-Typ → Attribut-Name:
//   click  → data-action
//   change → data-change
//   submit → data-submit
//   input  → data-input
//   blur   → data-blur
//
// Damit kann CSP 'unsafe-inline' für script-src später ohne Bruch entfernt
// werden — kein Inline-JS in HTML-Attributen mehr.
const __actions = {};
function registerAction(name, fn) { __actions[name] = fn; }

function __makeDispatcher(eventAttr) {
    return function(e) {
        // dataset-Key ist camelCase
        const dsKey = eventAttr.replace(/-(\w)/g, (_, c) => c.toUpperCase());
        const sel   = `[data-${eventAttr}]`;
        const el    = e.target.closest ? e.target.closest(sel) : null;
        if (!el) return;
        const name = el.dataset[dsKey];
        const fn   = __actions[name];
        if (!fn) {
            console.warn('Unbekannte Action:', name, 'auf', el);
            return;
        }
        try { fn(e, el, el.dataset); }
        catch(err) { console.error('Action-Fehler', name, err); }
    };
}

document.addEventListener('click',  __makeDispatcher('action'));
document.addEventListener('change', __makeDispatcher('change'));
document.addEventListener('submit', __makeDispatcher('submit'));
document.addEventListener('input',  __makeDispatcher('input'));
// blur bubbelt nicht → capture-Phase
document.addEventListener('blur',   __makeDispatcher('blur'), true);

// ===== API Helpers =====
const api = {
    async request(url, method = 'GET', data = null) {
        const opts = { method, headers: { 'X-CSRF-Token': window.CSRF_TOKEN } };
        if (data !== null) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(data);
        }
        const res = await fetch(url, opts);
        const json = await res.json();
        if (!res.ok) {
            // Session wurde via Remember-Me-Token im Hintergrund erneuert → CSRF im JS ist stale.
            // Seite einmal neu laden, dann hat das HTML den frischen CSRF-Token.
            if (res.status === 403 && /CSRF/i.test(json.error || '')) {
                window.location.reload();
                // Promise nie auflösen, damit aufrufender Code nicht weiter rumwurschtelt.
                return new Promise(() => {});
            }
            throw new Error(json.error || t('js.error.server_status', res.status));
        }
        return json;
    },
    get:    (url)       => api.request(url),
    post:   (url, data) => api.request(url, 'POST', data),
    put:    (url, data) => api.request(url, 'PUT',  data),
    delete: (url, data) => api.request(url, 'DELETE', data),
};

// ===== Toast =====
function toast(msg, type = 'success') {
    const c  = document.getElementById('toast-container');
    const el = document.createElement('div');
    el.className = `toast toast-${type}`;
    el.textContent = msg;
    c.appendChild(el);
    requestAnimationFrame(() => { requestAnimationFrame(() => el.classList.add('show')); });
    setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 350); }, 3500);
}

// ===== Modal =====
function openModal(title, bodyHTML) {
    document.getElementById('modal-title').textContent = title;
    document.getElementById('modal-body').innerHTML = bodyHTML;
    document.getElementById('modal-overlay').classList.add('open');
    const first = document.querySelector('#modal-body input, #modal-body select, #modal-body textarea');
    if (first) setTimeout(() => first.focus(), 50);
}

function closeModal() {
    document.getElementById('modal-overlay').classList.remove('open');
}

function handleOverlayClick(e) {
    if (e.target === document.getElementById('modal-overlay')) closeModal();
}

// Close modal on Escape
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

// ===== Contact Preview =====
function showContactPreview(c) {
    const el = document.getElementById('contact-preview-content');
    const bg = avatarBg(c.name);
    el.innerHTML = `
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem">
            <div style="width:48px;height:48px;border-radius:50%;background:${bg};display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.9rem;flex-shrink:0">${initials(c.name)}</div>
            <div>
                <div style="font-weight:600;font-size:1.05rem">${esc(c.name)}</div>
                ${c.role_job ? `<div style="font-size:.8rem;color:var(--text-2)">${esc(c.role_job)}</div>` : ''}
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:.4rem;font-size:.85rem">
            ${c.phone    ? `<div><span style="color:var(--text-muted);min-width:80px;display:inline-block">${esc(t('js.contact.preview.phone'))}</span> ${esc(c.phone)}</div>` : ''}
            ${c.email    ? `<div><span style="color:var(--text-muted);min-width:80px;display:inline-block">${esc(t('js.contact.preview.email'))}</span> ${esc(c.email)}</div>` : ''}
            ${c.company  ? `<div><span style="color:var(--text-muted);min-width:80px;display:inline-block">${esc(t('js.contact.preview.company'))}</span> ${esc(c.company)}</div>` : ''}
            ${c.grouping ? `<div><span style="color:var(--text-muted);min-width:80px;display:inline-block">${esc(t('js.contact.preview.group'))}</span> ${esc(c.grouping)}</div>` : ''}
            ${c.known_via? `<div><span style="color:var(--text-muted);min-width:80px;display:inline-block">${esc(t('js.contact.preview.via'))}</span> ${esc(c.known_via)}</div>` : ''}
            ${c.notes    ? `<div style="margin-top:.5rem;padding:.5rem;background:var(--bg);border-radius:6px;color:var(--text-2)">${esc(c.notes)}</div>` : ''}
        </div>
    `;
    document.getElementById('contact-preview').classList.add('open');
}

function closeContactPreview() {
    document.getElementById('contact-preview').classList.remove('open');
}

// ===== Character Switching =====
async function switchCharacter(charId) {
    if (!charId) return;
    const dot = document.getElementById('char-color-dot');
    if (dot) {
        const next = (window.ALL_CHARACTERS || []).find(c => c.id == charId);
        if (next?.avatar_color) dot.style.background = next.avatar_color;
    }
    try {
        await api.post('/api/characters.php', { action: 'switch', character_id: parseInt(charId) });
        window.location.reload();
    } catch(err) { toast(err.message, 'error'); }
}

// ===== Edit Current Character Modal =====
function openEditCharacterModal() {
    const chars  = window.ALL_CHARACTERS || [];
    const charId = window.CURRENT_CHAR_ID;
    const c      = chars.find(x => x.id == charId);
    if (!c) return;

    const colors = ['#7c3aed','#2563eb','#059669','#dc2626','#d97706','#0891b2','#be185d'];
    const swatches = colors.map(col =>
        `<button type="button" class="color-swatch" style="background:${col};width:28px;height:28px;border-radius:50%;border:2px solid ${col === (c.avatar_color||'#7c3aed') ? '#fff' : 'transparent'};transition:border-color .15s" data-color="${col}" data-action="select-color"></button>`
    ).join('');

    const canDelete = chars.length > 1;

    openModal(t('js.modal.char.edit'), `
        <form id="edit-char-form" data-submit="save-character-edit" data-id="${c.id}">
            <div class="form-group">
                <label>${esc(t('js.char.name_simple'))}</label>
                <div style="display:flex;align-items:center;gap:.75rem">
                    <div id="char-avatar-preview" class="char-color-preview" style="background:${c.avatar_color||'#7c3aed'}">${initials(c.name)}</div>
                    <input type="text" name="char_name" required value="${esc(c.name)}"
                           data-input="preview-char-avatar">
                </div>
            </div>
            <div class="form-group">
                <label>${esc(t('js.char.server_label'))} <span class="form-hint-inline">${esc(t('js.char.server_hint'))}</span></label>
                <input type="text" name="char_server" value="${esc(c.server||'')}" placeholder="${esc(t('js.char.server_ph'))}">
            </div>
            <div class="form-group">
                <label>${esc(t('js.char.color'))}</label>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">${swatches}</div>
                <input type="hidden" name="char_color" id="char_color" value="${esc(c.avatar_color||'#7c3aed')}">
            </div>
            <div class="form-group">
                <label>${esc(t('js.char.description'))}</label>
                <textarea name="char_desc" rows="2" placeholder="${esc(t('js.char.description_ph'))}">${esc(c.description||'')}</textarea>
            </div>
            <div class="modal-footer" style="justify-content:space-between">
                ${canDelete
                    ? `<button type="button" class="btn btn-danger btn-sm" data-action="delete-character" data-id="${c.id}" data-name="${esc(c.name)}">${esc(t('js.char.delete_label'))}</button>`
                    : `<span style="font-size:.78rem;color:var(--text-muted)">${esc(t('js.char.only_one'))}</span>`
                }
                <div style="display:flex;gap:.6rem">
                    <button type="button" class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.cancel'))}</button>
                    <button type="submit" class="btn btn-primary">${esc(t('js.btn.save'))}</button>
                </div>
            </div>
        </form>
    `);
}

async function saveCharacterEdit(e, id) {
    e.preventDefault();
    const f = e.target;
    try {
        await api.put('/api/characters.php', {
            id,
            name:        f.char_name.value,
            server:      f.char_server.value,
            description: f.char_desc.value,
            color:       f.char_color.value,
        });
        toast(t('js.toast.char_saved'));
        closeModal();
        window.location.reload();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteCharacter(id, name) {
    if (!confirm(t('js.char.delete_warn', name))) return;
    try {
        await api.delete('/api/characters.php', { id });
        toast(t('js.toast.char_deleted'));
        window.location.reload();
    } catch(err) { toast(err.message, 'error'); }
}

// ===== New Character Modal =====
function openNewCharacterModal() {
    const colors = ['#7c3aed','#2563eb','#059669','#dc2626','#d97706','#0891b2','#be185d'];
    const swatches = colors.map(c =>
        `<button type="button" class="color-swatch" style="background:${c};width:28px;height:28px;border-radius:50%;border:2px solid transparent;transition:border-color .15s" data-color="${c}" data-action="select-color"></button>`
    ).join('');

    openModal(t('js.modal.char.new'), `
        <form id="new-char-form" data-submit="create-character">
            <div class="form-group">
                <label>${esc(t('js.char.name_required'))}</label>
                <div style="display:flex;align-items:center;gap:.75rem">
                    <div id="char-avatar-preview" class="char-color-preview" style="background:#7c3aed">?</div>
                    <input type="text" name="char_name" required placeholder="${esc(t('js.char.name_ph'))}"
                           data-input="preview-char-avatar">
                </div>
            </div>
            <div class="form-group">
                <label>${esc(t('js.char.server_label'))} <span class="form-hint-inline">${esc(t('js.char.server_hint'))}</span></label>
                <input type="text" name="char_server" placeholder="${esc(t('js.char.server_ph'))}">
            </div>
            <div class="form-group">
                <label>${esc(t('js.char.color'))}</label>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">${swatches}</div>
                <input type="hidden" name="char_color" id="char_color" value="#7c3aed">
            </div>
            <div class="form-group">
                <label>${esc(t('js.char.description'))}</label>
                <textarea name="char_desc" rows="2" placeholder="${esc(t('js.char.description_new_ph'))}"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.cancel'))}</button>
                <button type="submit" class="btn btn-primary">${esc(t('js.char.create'))}</button>
            </div>
        </form>
    `);
    selectColor('#7c3aed');
}

function selectColor(color) {
    document.getElementById('char_color').value = color;
    const preview = document.getElementById('char-avatar-preview');
    if (preview) preview.style.background = color;
    document.querySelectorAll('.color-swatch').forEach(s => {
        s.style.borderColor = s.dataset.color === color ? '#fff' : 'transparent';
    });
}

async function createCharacter(e) {
    e.preventDefault();
    const f = e.target;
    try {
        await api.post('/api/characters.php', {
            name:        f.char_name.value,
            server:      f.char_server?.value || '',
            description: f.char_desc.value,
            color:       f.char_color.value,
        });
        toast(t('js.toast.char_created'));
        closeModal();
        window.location.reload();
    } catch(err) { toast(err.message, 'error'); }
}

// ===== Change Password Modal =====
function openChangePasswordModal() {
    openModal(t('js.modal.char.pw'), `
        <form id="pw-form" data-submit="change-password">
            <div class="form-group">
                <label>${esc(t('js.pw.current'))}</label>
                <input type="password" name="current_password" required placeholder="${esc(t('js.pw.current_ph'))}">
            </div>
            <div class="form-group">
                <label>${esc(t('js.pw.new'))}</label>
                <input type="password" name="new_password" required minlength="8" placeholder="${esc(t('js.pw.new_ph'))}">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.cancel'))}</button>
                <button type="submit" class="btn btn-primary">${esc(t('js.pw.submit'))}</button>
            </div>
        </form>
    `);
}

async function changePassword(e) {
    e.preventDefault();
    const f = e.target;
    try {
        await api.post('/api/profile.php', {
            action: 'change_password',
            current_password: f.current_password.value,
            new_password:     f.new_password.value,
        });
        toast(t('js.toast.pw_changed'));
        closeModal();
    } catch(err) { toast(err.message, 'error'); }
}

// ===== Utility =====
// ===== Fuzzy Search =====

function levenshtein(a, b) {
    if (a.length === 0) return b.length;
    if (b.length === 0) return a.length;
    const dp = [];
    for (let i = 0; i <= b.length; i++) {
        dp[i] = [i];
        for (let j = 1; j <= a.length; j++) {
            dp[i][j] = i === 0 ? j
                : b[i-1] === a[j-1] ? dp[i-1][j-1]
                : 1 + Math.min(dp[i-1][j-1], dp[i][j-1], dp[i-1][j]);
        }
    }
    return dp[b.length][a.length];
}

function fuzzyMatch(text, query) {
    if (!query || !query.trim()) return true;
    if (!text) return false;
    text  = String(text).toLowerCase();
    query = query.toLowerCase().trim();

    // 1. Direkte Teilstring-Übereinstimmung
    if (text.includes(query)) return true;

    // 2. Mehrere Wörter: alle müssen vorkommen
    const words = query.split(/\s+/);
    if (words.length > 1) return words.every(w => fuzzyMatch(text, w));

    // 3. Subsequenz-Match (für Abkürzungen / fehlende Buchstaben)
    if (query.length >= 2) {
        let qi = 0;
        for (let i = 0; i < text.length; i++) {
            if (text[i] === query[qi]) qi++;
            if (qi === query.length) return true;
        }
    }

    // 4. Levenshtein-Toleranz (1 Tippfehler ab 4 Zeichen, 2 ab 6)
    if (query.length >= 4) {
        const maxDist = query.length >= 6 ? 2 : 1;
        const parts   = text.split(/[\s,.\-/]+/);
        return parts.some(p => {
            if (Math.abs(p.length - query.length) > maxDist + 1) return false;
            return levenshtein(p, query) <= maxDist;
        });
    }
    return false;
}

// Filtert ein Array nach query über mehrere Felder
function fuzzyFilter(items, query, fields) {
    if (!query || !query.trim()) return items;
    return items.filter(item => fields.some(f => fuzzyMatch(item[f], query)));
}

function esc(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Sicher zum Einbetten in ein JS-String-Literal innerhalb eines HTML-Attributs
// (z.B. onclick="fn('${jsEsc(x)}')"). esc() reicht dort NICHT: der HTML-Parser
// dekodiert &#039; im Attributwert zurück zu ', wodurch der JS-String ausbricht.
// Diese Funktion kodiert alle gefährlichen Zeichen als \uXXXX, was sowohl HTML-
// (keine Entities) als auch JS-stringkontext-sicher ist.
function jsEsc(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[\\'"<>&\r\n\u2028\u2029]/g,
        c => '\\u' + c.charCodeAt(0).toString(16).padStart(4, '0'));
}

function initials(name) {
    if (!name) return '?';
    const parts = String(name).trim().split(/\s+/);
    return parts.slice(0, 2).map(p => p[0]?.toUpperCase() || '').join('');
}

const AVATAR_COLORS = ['#7c3aed','#2563eb','#059669','#dc2626','#d97706','#0891b2','#be185d','#0f766e'];
function avatarBg(name) {
    if (!name) return AVATAR_COLORS[0];
    let h = 0;
    for (let i = 0; i < name.length; i++) h = (Math.imul(31, h) + name.charCodeAt(i)) | 0;
    return AVATAR_COLORS[Math.abs(h) % AVATAR_COLORS.length];
}

function showError(containerId, msg) {
    const el = document.getElementById(containerId);
    if (el) el.innerHTML = `<div class="error-state">${esc(t('js.error.prefix', msg))}</div>`;
}

// ===== Nav Toggle (Mobile) =====
function toggleNav() {
    document.getElementById('main-nav').classList.toggle('open');
}

document.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => {
    document.getElementById('main-nav')?.classList.remove('open');
}));

// ===== Nav Scroll (Desktop) =====
function navScroll(dir) {
    const nav = document.getElementById('main-nav');
    if (nav) nav.scrollBy({ left: dir * 180, behavior: 'smooth' });
}

function initNavScroll() {
    const nav  = document.getElementById('main-nav');
    const prev = document.getElementById('nav-prev');
    const next = document.getElementById('nav-next');
    if (!nav || !prev || !next) return;

    function update() {
        // Nur auf Desktop (Buttons per CSS auf Mobile ausgeblendet)
        const overflow = nav.scrollWidth > nav.clientWidth + 2;
        prev.classList.toggle('show', overflow && nav.scrollLeft > 2);
        next.classList.toggle('show', overflow && nav.scrollLeft < nav.scrollWidth - nav.clientWidth - 2);
    }

    nav.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);

    // Mausrad → horizontales Scrollen
    nav.addEventListener('wheel', e => {
        if (nav.scrollWidth <= nav.clientWidth) return;
        e.preventDefault();
        nav.scrollBy({ left: e.deltaY * 1.5, behavior: 'auto' });
        update();
    }, { passive: false });

    // Initialer Check (nach Render)
    requestAnimationFrame(() => requestAnimationFrame(update));
}

// ===== Globale Actions (für alle Seiten verfügbar) =====
// In Modal-Templates oft genutzt: close-modal.
registerAction('close-modal', () => closeModal());

// Header / Charakter-Switcher / Navigation:
registerAction('switch-character',         (e)       => switchCharacter(e.target.value)); // data-change
registerAction('open-new-character-modal', ()        => openNewCharacterModal());
registerAction('open-change-password-modal',()       => openChangePasswordModal());
registerAction('nav-scroll',               (e, el, ds) => navScroll(parseInt(ds.dir, 10) || 1));
registerAction('toggle-nav',               ()        => toggleNav());
registerAction('select-color',             (e, el, ds) => selectColor(ds.color));

// Logout: submitted das verstecke logout-form (CSRF-Token ist drin).
registerAction('logout', (e) => {
    e.preventDefault();
    const f = document.getElementById('logout-form');
    if (f) f.submit();
});

// Modal-Overlay-Click: nur schließen, wenn direkt auf den Backdrop geklickt
// wurde (nicht auf den Modal-Inhalt). e.target === el dank Event-Delegation.
registerAction('modal-overlay-click', (e, el) => {
    if (e.target === el) closeModal();
});
registerAction('contact-preview-overlay-click', (e, el) => {
    if (e.target === el) closeContactPreview();
});
registerAction('close-contact-preview', () => closeContactPreview());

// Charakter-Modal-Funktionen (Templates leben in app.js selbst):
registerAction('save-character-edit',    (e, el, ds) => saveCharacterEdit(e, parseInt(ds.id, 10)));
registerAction('delete-character',       (e, el, ds) => deleteCharacter(parseInt(ds.id, 10), ds.name || ''));
registerAction('create-character',       (e)         => createCharacter(e));
registerAction('change-password',        (e)         => changePassword(e));
registerAction('preview-char-avatar',    (e) => {
    const prev = document.getElementById('char-avatar-preview');
    if (prev) prev.textContent = initials(e.target.value) || '?';
});
registerAction('welcome-create-character', () => { closeModal(); openNewCharacterModal(); });

// ===== Auto-Prompt: Charakter anlegen wenn noch keiner existiert =====
document.addEventListener('DOMContentLoaded', () => {
    initNavScroll();

    if (window.CURRENT_CHAR_ID === null || window.CURRENT_CHAR_ID === undefined) {
        // Kurz warten bis die Seite vollständig gerendert ist
        setTimeout(() => {
            // Zeige ein erklärenden Willkommens-Modal statt dem leeren Formular
            openModal(t('js.modal.welcome.title'), `
                <div style="text-align:center;padding:.5rem 0 1rem">
                    <div style="font-size:3rem;margin-bottom:.75rem">👤</div>
                    <p style="color:var(--text-2);margin-bottom:1.5rem">
                        ${esc(t('js.modal.welcome.body_l1'))}<br>${esc(t('js.modal.welcome.body_l2'))}
                    </p>
                    <button class="btn btn-primary" style="font-size:1rem;padding:.65rem 1.5rem"
                            data-action="welcome-create-character">
                        ${esc(t('js.modal.welcome.btn'))}
                    </button>
                </div>
            `);
        }, 200);
    }
});
