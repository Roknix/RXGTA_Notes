<?php
try {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/functions.php';
    requireAuth();
} catch (Throwable $e) {
    http_response_code(500);
    die('<pre style="color:red;padding:2rem">' . htmlspecialchars(__('msg.error_generic', $e->getMessage())) . '</pre>');
}
$page = 'notes';
require_once __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/vendor/toastui/toastui-editor.min.css">
<link rel="stylesheet" href="/assets/vendor/toastui/toastui-editor-dark.min.css">
<style>
.notes-editor-wrap { flex: 1; min-height: 0; }
.notes-footer .notes-status { min-width: 0; }
.notes-editor-wrap .toastui-editor-defaultUI {
    height: 100% !important;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--card);
}
.notes-editor-wrap .toastui-editor-toolbar { border-radius: var(--radius) var(--radius) 0 0; }
.toastui-editor-dark .toastui-editor-defaultUI { background: var(--card); }
.toastui-editor-dark .toastui-editor-toolbar { background: var(--surface); border-color: var(--border) !important; }
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
.toastui-editor-dark .toastui-editor-contents blockquote {
    border-left-color: var(--accent); color: var(--text-2);
}
</style>

<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= h(__('page.notes.title')) ?></h1>
            <p class="page-subtitle"><?= h(__('page.notes.subtitle')) ?></p>
        </div>
    </div>

    <div class="notes-container">
        <div id="notes-editor" class="notes-editor-wrap"></div>
        <div class="notes-footer">
            <span id="notes-wordcount" class="notes-wordcount"></span>
            <span id="notes-status" class="notes-status"></span>
        </div>
    </div>
</main>

<script src="/assets/vendor/toastui/toastui-editor.min.js"></script>
<script nonce="<?= h(cspNonce()) ?>">
// Sprache wird über die `language`-Option beim Editor-Init durchgereicht (built-in
// de-DE/en-US der Library). setLanguage() würde die kompletten Default-Tooltips
// überschreiben — z.B. fehlte sonst der "Headings"-Key.

let saveTimer   = null;
let isDirty     = false;
let isSaving    = false;
let suppressDirty = false;
let editor      = null;

document.addEventListener('DOMContentLoaded', () => {
    editor = new toastui.Editor({
        el: document.getElementById('notes-editor'),
        height: '100%',
        initialEditType: 'wysiwyg',
        previewStyle: 'vertical',
        theme: 'dark',
        // Toast UI v3.2.2 hat NUR en-US eingebaut. 'de-DE' würde fehlende Keys werfen
        // (siehe Kommentar in biography.php).
        language: 'en-US',
        usageStatistics: false,
        placeholder: t('js.notes.editor_ph'),
        toolbarItems: [
            ['heading', 'bold', 'italic', 'strike'],
            ['hr', 'quote'],
            ['ul', 'ol', 'task'],
            ['table', 'link', 'image'],
            ['code', 'codeblock'],
        ],
        autofocus: false,
    });

    if (!window.CURRENT_CHAR_ID) {
        editor.setMarkdown('');
        document.querySelector('#notes-editor').style.opacity = '.5';
        document.querySelector('#notes-editor').style.pointerEvents = 'none';
        setStatus(t('js.notes.no_character'), 'error');
        return;
    }

    editor.on('change', onNotesChange);
    loadNotes();
});

async function loadNotes() {
    try {
        const data = await api.get('/api/notes.php');
        suppressDirty = true;
        editor.setMarkdown(data.content || '');
        suppressDirty = false;
        isDirty = false;
        updateWordCount();
        if (data.updated_at) {
            setStatus(t('js.notes.last_saved', formatTime(data.updated_at * 1000)), 'saved');
        }
    } catch(e) { setStatus(t('msg.error_loading', e.message), 'error'); }
}

function onNotesChange() {
    if (suppressDirty) return;
    isDirty = true;
    updateWordCount();
    setStatus(t('js.notes.dirty'), 'dirty');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveNotes, 1500);
}

async function saveNotes() {
    if (!window.CURRENT_CHAR_ID || isSaving) return;
    isSaving = true;
    setStatus(t('js.notes.saving'), 'saving');
    try {
        await api.post('/api/notes.php', { content: editor.getMarkdown() });
        isDirty  = false;
        isSaving = false;
        setStatus(t('js.notes.saved', formatTime(Date.now())), 'saved');
    } catch(e) {
        isSaving = false;
        setStatus(t('msg.error_generic', e.message), 'error');
    }
}

function setStatus(msg, type) {
    const el = document.getElementById('notes-status');
    el.textContent = msg;
    el.className   = 'notes-status notes-status-' + type;
}

function updateWordCount() {
    if (!editor) return;
    const val   = editor.getMarkdown().trim();
    const words = val ? val.split(/\s+/).length : 0;
    document.getElementById('notes-wordcount').textContent = val ? t('js.notes.word_count', words) : '';
}

function formatTime(ts) {
    const loc = window.LOCALE === 'de' ? 'de-DE' : 'en-US';
    return new Date(ts).toLocaleTimeString(loc, { hour: '2-digit', minute: '2-digit' });
}

document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        clearTimeout(saveTimer);
        saveNotes();
    }
});

window.addEventListener('beforeunload', e => {
    if (isDirty) { e.preventDefault(); e.returnValue = ''; }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
