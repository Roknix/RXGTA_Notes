<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAuth();
$page = 'biography';
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/vendor/toastui/toastui-editor.min.css">
<link rel="stylesheet" href="/assets/vendor/toastui/toastui-editor-dark.min.css">
<style>
.bio-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem 1.25rem; }
@media (max-width: 720px) { .bio-grid { grid-template-columns: 1fr; } }

.bio-field { display: flex; flex-direction: column; gap: .3rem; }
.bio-field-head { display: flex; justify-content: space-between; align-items: center; gap: .5rem; }
.bio-field label { font-size: .82rem; color: var(--text-2); margin: 0; }

.pub-toggle {
    display: inline-flex; align-items: center; gap: .35rem;
    background: var(--surface); border: 1px solid var(--border);
    color: var(--text-muted); border-radius: 999px;
    padding: .15rem .55rem; font-size: .72rem; cursor: pointer;
    transition: background .15s, color .15s, border-color .15s;
    user-select: none; line-height: 1.4;
}
.pub-toggle:hover { border-color: var(--border-2); }
.pub-toggle.is-public {
    background: rgba(124,58,237,.15); border-color: rgba(124,58,237,.4);
    color: var(--accent-l);
}

.bio-section {
    background: var(--card); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 1.25rem; margin-bottom: 1.25rem;
}
.bio-section h2 { margin: 0 0 .25rem 0; font-size: 1.05rem; }
.bio-section .section-hint { color: var(--text-muted); font-size: .82rem; margin: 0 0 1rem 0; }

#bio-freitext-wrap {
    height: 380px;
    display: flex;
    flex-direction: column;
}
#bio-freitext {
    flex: 1;
    min-height: 0;
    overflow: hidden;
}
#bio-freitext-wrap .toastui-editor-defaultUI {
    height: 100% !important;
    border: 1px solid var(--border);
    border-radius: var(--radius);
}
.toastui-editor-dark .toastui-editor-defaultUI { background: var(--card); }
.toastui-editor-dark .toastui-editor-toolbar { background: var(--surface); border-color: var(--border) !important; border-radius: var(--radius) var(--radius) 0 0; }
.toastui-editor-dark .toastui-editor-md-container,
.toastui-editor-dark .toastui-editor-ww-container { background: var(--card); }
.toastui-editor-dark .toastui-editor-contents { color: var(--text); }
.toastui-editor-dark .toastui-editor-contents h1,
.toastui-editor-dark .toastui-editor-contents h2,
.toastui-editor-dark .toastui-editor-contents h3,
.toastui-editor-dark .toastui-editor-contents h4 { color: var(--accent-l); border-color: var(--border); }
.toastui-editor-dark .toastui-editor-contents a { color: var(--accent-l); }
.toastui-editor-dark .toastui-editor-contents code {
    background: var(--bg); color: #e879f9; padding: .1em .35em; border-radius: 3px;
}
.toastui-editor-dark .toastui-editor-contents pre {
    background: var(--bg); border: 1px solid var(--border);
}

.public-link-row {
    display: flex; gap: .5rem; align-items: center; flex-wrap: wrap;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: var(--radius-sm); padding: .55rem .75rem;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem;
}
.public-link-row code { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text); }
.bio-save-bar {
    position: sticky; bottom: 0; background: var(--bg);
    padding: .75rem 0; border-top: 1px solid var(--border);
    display: flex; justify-content: flex-end; align-items: center; gap: .75rem;
    z-index: 50;
}
.bio-save-bar .notes-status { min-width: 0; text-align: right; }
</style>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= h(__('page.biography.title')) ?></h1>
            <p class="page-subtitle"><?= h(__('page.biography.subtitle')) ?></p>
        </div>
    </div>

    <div id="bio-form-wrap">
        <div class="loading-spinner"></div>
    </div>
</main>

<script src="/assets/vendor/toastui/toastui-editor.min.js"></script>
<script nonce="<?= h(cspNonce()) ?>">
// Toast UI Editor: setLanguage() würde die built-in de-DE/en-US-Übersetzungen der
// Library komplett ersetzen (Tooltips wie "Headings" wären weg). Stattdessen
// reichen wir die Sprache beim Editor-Init über die `language`-Option durch.

let bioMde      = null;
let bioState    = null;     // letzte geladene Profil-Daten
let publicSet   = new Set(); // Set der Feld-Slugs, die öffentlich sind

// Slugs sind fix; die Labels/Placeholder kommen aus dem I18N-Bundle und werden
// erst nach DOMContentLoaded (also nach app.js → t() verfügbar) eingebunden.
const BIO_SLUGS = ['birthday', 'birthplace', 'nationality', 'occupation', 'height', 'family'];
function bioStructured() {
    return BIO_SLUGS.map(slug => ({
        slug,
        label:       t('js.bio.field.' + slug),
        placeholder: t('js.bio.ph.'    + slug),
    }));
}

function renderForm() {
    const wrap = document.getElementById('bio-form-wrap');
    wrap.innerHTML = `
        <section class="bio-section">
            <h2>${esc(t('js.bio.section.stam'))}</h2>
            <p class="section-hint">${esc(t('js.bio.section.stam_hint'))}</p>
            <div class="bio-grid" id="bio-structured"></div>
        </section>

        <section class="bio-section">
            <h2>${esc(t('js.bio.section.appearance'))}</h2>
            <p class="section-hint">${esc(t('js.bio.section.appearance_hint'))}</p>
            <div class="bio-field-head">
                <label for="bio-appearance">${esc(t('js.bio.field.appearance_label'))}</label>
                <button type="button" class="pub-toggle" data-slug="appearance" data-action="toggle-public"></button>
            </div>
            <textarea id="bio-appearance" rows="4" placeholder="${esc(t('js.bio.field.appearance_ph'))}"></textarea>
        </section>

        <section class="bio-section">
            <h2>${esc(t('js.bio.section.story'))}</h2>
            <p class="section-hint">${esc(t('js.bio.section.story_hint'))}</p>
            <div class="bio-field-head" style="margin-bottom:.4rem">
                <label>${esc(t('js.bio.field.freitext'))}</label>
                <button type="button" class="pub-toggle" data-slug="biography" data-action="toggle-public"></button>
            </div>
            <div id="bio-freitext-wrap"><div id="bio-freitext"></div></div>
        </section>

        <section class="bio-section">
            <h2>${esc(t('js.bio.section.publish'))}</h2>
            <p class="section-hint">${esc(t('js.bio.section.publish_hint'))}</p>
            <div id="bio-public-panel">
                <div class="loading-spinner"></div>
            </div>
        </section>

        <div class="bio-save-bar">
            <span id="bio-status" class="notes-status"></span>
            <button type="button" class="btn btn-primary" data-action="save-bio">${esc(t('js.btn.save'))}</button>
        </div>
    `;

    // Strukturierte Felder rendern
    const grid = document.getElementById('bio-structured');
    grid.innerHTML = bioStructured().map(f => `
        <div class="bio-field">
            <div class="bio-field-head">
                <label for="bio-${f.slug}">${esc(f.label)}</label>
                <button type="button" class="pub-toggle" data-slug="${f.slug}" data-action="toggle-public"></button>
            </div>
            <input type="text" id="bio-${f.slug}" placeholder="${esc(f.placeholder)}">
        </div>
    `).join('');

    // Toast UI Editor init (WYSIWYG mit Markdown-Umschalter). Sprache je nach window.LOCALE.
    bioMde = new toastui.Editor({
        el: document.getElementById('bio-freitext'),
        height: '100%',
        initialEditType: 'wysiwyg',
        previewStyle: 'vertical',
        theme: 'dark',
        // Toast UI v3.2.2 hat NUR en-US eingebaut. Wenn man 'de-DE' setzt, ohne diese
        // Sprache vorher mit setLanguage() komplett zu registrieren, knallt jeder
        // fehlende Key (z.B. "Headings"). Editor-UI bleibt daher englisch — der
        // Editor-INHALT (Markdown) ist sprachunabhängig.
        language: 'en-US',
        usageStatistics: false,
        placeholder: t('js.bio.field.editor_ph'),
        toolbarItems: [
            ['heading', 'bold', 'italic', 'strike'],
            ['hr', 'quote'],
            ['ul', 'ol'],
            ['link', 'code'],
        ],
    });
}

function fillForm(p) {
    BIO_SLUGS.forEach(slug => {
        const el = document.getElementById('bio-' + slug);
        if (el) el.value = p[slug] || '';
    });
    document.getElementById('bio-appearance').value = p.appearance || '';
    bioMde.setMarkdown(p.biography || '');
    publicSet = new Set(p.public_fields || []);
    refreshToggles();
    renderPublicPanel();
}

function refreshToggles() {
    document.querySelectorAll('.pub-toggle').forEach(btn => {
        const slug = btn.dataset.slug;
        const isPublic = publicSet.has(slug);
        btn.classList.toggle('is-public', isPublic);
        btn.innerHTML = isPublic ? esc(t('js.bio.toggle.public')) : esc(t('js.bio.toggle.private'));
        btn.title = isPublic ? t('js.bio.toggle.title_public') : t('js.bio.toggle.title_private');
    });
}

function togglePublic(slug) {
    if (publicSet.has(slug)) publicSet.delete(slug);
    else publicSet.add(slug);
    refreshToggles();
}

function renderPublicPanel() {
    const el = document.getElementById('bio-public-panel');
    const enabled = !!bioState.public_enabled;
    const token   = bioState.public_token || '';
    const url     = token ? (window.location.origin + '/p/' + token) : '';
    const hasPublicFields = (bioState.public_fields || []).length > 0;

    el.innerHTML = `
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem">
            <label class="toggle-label" style="margin:0">
                <input type="checkbox" id="bio-public-enabled" ${enabled ? 'checked' : ''} data-change="toggle-public-enabled">
                <span class="toggle-text">${esc(t('js.bio.public.enable'))}</span>
            </label>
            ${enabled && !hasPublicFields ? `<span class="badge badge-warning">${esc(t('js.bio.public.no_fields'))}</span>` : ''}
        </div>
        ${enabled ? `
            <div class="public-link-row">
                <code id="bio-public-url">${esc(url)}</code>
                <button type="button" class="btn btn-sm btn-secondary" data-action="copy-public-url">${esc(t('js.bio.public.copy'))}</button>
                <a class="btn btn-sm btn-secondary" href="${esc(url)}" target="_blank" rel="noopener">${esc(t('js.bio.public.open'))}</a>
                <button type="button" class="btn btn-sm btn-danger-soft" data-action="rotate-public-token" title="${esc(t('js.bio.public.rotate_title'))}">${esc(t('js.bio.public.rotate'))}</button>
            </div>
            <p class="section-hint" style="margin-top:.5rem">
                ${esc(t('js.bio.public.note'))}
            </p>
        ` : `
            <p class="section-hint" style="margin:0">${esc(t('js.bio.public.disabled'))}</p>
        `}
    `;
}

async function loadBio() {
    if (!window.CURRENT_CHAR_ID) {
        document.getElementById('bio-form-wrap').innerHTML = `<div class="empty-state"><p>${esc(t('msg.no_character_generic'))}</p></div>`;
        return;
    }
    try {
        bioState = await api.get('/api/biography.php');
        renderForm();
        fillForm(bioState);
    } catch(e) {
        document.getElementById('bio-form-wrap').innerHTML = `<div class="empty-state"><p style="color:var(--danger)">${esc(t('msg.error_generic', e.message))}</p></div>`;
    }
}

async function saveBio() {
    if (!bioState) return;
    const payload = {
        public_fields: Array.from(publicSet),
        appearance: document.getElementById('bio-appearance').value,
        biography:  bioMde.getMarkdown(),
    };
    BIO_SLUGS.forEach(slug => { payload[slug] = document.getElementById('bio-' + slug).value; });
    setBioStatus(t('js.bio.status.saving'), 'saving');
    try {
        bioState = await api.put('/api/biography.php', payload);
        publicSet = new Set(bioState.public_fields || []);
        refreshToggles();
        renderPublicPanel();
        setBioStatus(t('js.bio.status.saved'), 'saved');
    } catch(e) {
        setBioStatus(t('msg.error_generic', e.message), 'error');
    }
}

async function togglePublicEnabled(checked) {
    try {
        bioState = await api.post('/api/biography.php', { action: checked ? 'enable_public' : 'disable_public' });
        publicSet = new Set(bioState.public_fields || []);
        renderPublicPanel();
        toast(checked ? t('js.toast.bio_published') : t('js.toast.bio_unpublished'));
    } catch(e) { toast(e.message, 'error'); renderPublicPanel(); }
}

async function rotatePublicToken() {
    if (!confirm(t('js.confirm.rotate_token'))) {
        renderPublicPanel();
        return;
    }
    try {
        bioState = await api.post('/api/biography.php', { action: 'rotate_token' });
        renderPublicPanel();
        toast(t('js.toast.bio_new_link'));
    } catch(e) { toast(e.message, 'error'); }
}

async function copyPublicUrl() {
    const url = document.getElementById('bio-public-url')?.textContent || '';
    try {
        await navigator.clipboard.writeText(url);
        toast(t('js.toast.bio_link_copied'));
    } catch(e) {
        toast(t('js.toast.bio_copy_failed'), 'error');
    }
}

function setBioStatus(msg, type) {
    const el = document.getElementById('bio-status');
    el.textContent = msg;
    el.className   = 'notes-status notes-status-' + type;
}

document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        if (!bioState) return;
        e.preventDefault();
        saveBio();
    }
});

document.addEventListener('DOMContentLoaded', () => {
    registerAction('toggle-public',         (e, el, ds) => togglePublic(ds.slug));
    registerAction('save-bio',              () => saveBio());
    registerAction('toggle-public-enabled', (e) => togglePublicEnabled(e.target.checked));
    registerAction('copy-public-url',       () => copyPublicUrl());
    registerAction('rotate-public-token',   () => rotatePublicToken());
    loadBio();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
