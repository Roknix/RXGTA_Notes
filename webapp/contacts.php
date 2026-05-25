<?php
try {
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/functions.php';
    requireAuth();
} catch (Throwable $e) {
    http_response_code(500);
    die('<pre style="color:red;padding:2rem">' . htmlspecialchars(__('msg.error_loading', $e->getMessage())) . '</pre>');
}
$page = 'contacts';
require_once __DIR__ . '/includes/header.php';
?>
<main class="main-content">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= h(__('page.contacts.title')) ?></h1>
            <p class="page-subtitle"><?= h(__('page.contacts.subtitle')) ?></p>
        </div>
        <div class="page-actions">
            <div class="search-wrap">
                <input type="search" id="search-input" placeholder="<?= h(__('page.contacts.search_ph')) ?>" data-input="filter-contacts">
            </div>
            <button class="btn btn-primary" data-action="open-contact-modal">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="btn-icon-svg"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?= h(__('page.contacts.add')) ?>
            </button>
        </div>
    </div>

    <div id="contacts-grid" class="contacts-grid">
        <div class="loading-spinner"></div>
    </div>
</main>

<script nonce="<?= h(cspNonce()) ?>">
let allContacts = [];

async function loadContacts() {
    if (!window.CURRENT_CHAR_ID) {
        document.getElementById('contacts-grid').innerHTML = '';
        return;
    }
    try {
        allContacts = await api.get('/api/contacts.php');
        renderContacts(allContacts);
    } catch(e) { showError('contacts-grid', e.message); }
}

function filterContacts(q) {
    const filtered = fuzzyFilter(allContacts, q, ['name','phone','company','grouping','known_via','role_job','notes']);
    renderContacts(filtered);
}

function renderContacts(contacts) {
    const grid = document.getElementById('contacts-grid');
    if (!contacts.length) {
        grid.innerHTML = `<div class="empty-state"><div class="empty-icon">👤</div><p>${esc(t('js.empty.no_contacts'))}</p></div>`;
        return;
    }
    grid.innerHTML = contacts.map(c => `
        <div class="contact-card" id="cc-${c.id}">
            <div class="contact-card-header">
                <div class="contact-avatar" style="background:${avatarBg(c.name)}">${initials(c.name)}</div>
                <div class="contact-main">
                    <h3 class="contact-name">${esc(c.name)}</h3>
                    ${c.role_job ? `<div class="contact-role">${esc(c.role_job)}</div>` : ''}
                </div>
                <div class="card-actions">
                    <button class="btn-icon-sm" data-action="open-contact-modal" data-id="${c.id}" title="${esc(t('js.btn.edit'))}">✏️</button>
                    <button class="btn-icon-sm btn-danger-sm" data-action="delete-contact" data-id="${c.id}" data-name="${esc(c.name)}" title="${esc(t('js.btn.delete'))}">🗑️</button>
                </div>
            </div>
            <div class="contact-details">
                ${c.phone    ? `<div class="contact-detail"><span class="detail-icon">📞</span><span>${esc(c.phone)}</span></div>` : ''}
                ${c.email    ? `<div class="contact-detail"><span class="detail-icon">✉️</span><span>${esc(c.email)}</span></div>` : ''}
                ${c.company  ? `<div class="contact-detail"><span class="detail-icon">🏢</span><span>${esc(c.company)}</span></div>` : ''}
                ${c.grouping ? `<div class="contact-detail"><span class="detail-icon">🤝</span><span>${esc(c.grouping)}</span></div>` : ''}
                ${c.known_via? `<div class="contact-detail"><span class="detail-icon">💬</span><span>${esc(t('js.via_prefix', c.known_via))}</span></div>` : ''}
            </div>
            ${c.notes ? `<div class="contact-notes">${esc(c.notes)}</div>` : ''}
        </div>
    `).join('');
}

function openContactModal(id = null) {
    const c = id ? allContacts.find(x => x.id == id) : null;
    const title = c ? t('js.modal.contact.edit') : t('js.modal.contact.new');
    openModal(title, `
        <form id="contact-form" data-submit="save-contact" data-id="${id || ''}">
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.label.name_required'))}</label>
                    <input type="text" name="name" required value="${esc(c?.name||'')}" placeholder="${esc(t('js.placeholder.contact.name'))}">
                </div>
                <div class="form-group">
                    <label>${esc(t('js.label.phone'))}</label>
                    <input type="text" name="phone" value="${esc(c?.phone||'')}" placeholder="${esc(t('js.placeholder.contact.phone'))}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.label.email'))}</label>
                    <input type="text" name="email" value="${esc(c?.email||'')}" placeholder="${esc(t('js.placeholder.contact.email'))}">
                </div>
                <div class="form-group">
                    <label>${esc(t('js.label.company'))}</label>
                    <input type="text" name="company" value="${esc(c?.company||'')}" placeholder="${esc(t('js.placeholder.contact.company'))}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>${esc(t('js.label.grouping'))}</label>
                    <input type="text" name="grouping" value="${esc(c?.grouping||'')}" placeholder="${esc(t('js.placeholder.contact.grouping'))}">
                </div>
                <div class="form-group">
                    <label>${esc(t('js.label.known_via'))}</label>
                    <input type="text" name="known_via" value="${esc(c?.known_via||'')}" placeholder="${esc(t('js.placeholder.contact.known_via'))}">
                </div>
            </div>
            <div class="form-group">
                <label>${esc(t('js.label.role_job'))}</label>
                <input type="text" name="role_job" value="${esc(c?.role_job||'')}" placeholder="${esc(t('js.placeholder.contact.role_job'))}">
            </div>
            <div class="form-group">
                <label>${esc(t('js.label.notes'))}</label>
                <textarea name="notes" rows="3" placeholder="${esc(t('js.placeholder.contact.notes'))}">${esc(c?.notes||'')}</textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="close-modal">${esc(t('js.btn.cancel'))}</button>
                <button type="submit" class="btn btn-primary">${esc(c ? t('js.btn.save') : t('js.btn.create'))}</button>
            </div>
        </form>
    `);
}

async function saveContact(e, id) {
    e.preventDefault();
    const form = e.target;
    const data = {
        name:      form.name.value,
        phone:     form.phone.value,
        email:     form.email.value,
        company:   form.company.value,
        grouping:  form.grouping.value,
        known_via: form.known_via.value,
        role_job:  form.role_job.value,
        notes:     form.notes.value,
    };
    try {
        if (id) {
            await api.put('/api/contacts.php', { ...data, id });
            toast(t('js.toast.contact_saved'));
        } else {
            await api.post('/api/contacts.php', data);
            toast(t('js.toast.contact_created'));
        }
        closeModal();
        loadContacts();
    } catch(err) { toast(err.message, 'error'); }
}

async function deleteContact(id, name) {
    if (!confirm(t('js.confirm.delete_contact', name))) return;
    try {
        await api.delete('/api/contacts.php', { id });
        toast(t('js.toast.contact_deleted'));
        loadContacts();
    } catch(err) { toast(err.message, 'error'); }
}

document.addEventListener('DOMContentLoaded', () => {
    registerAction('open-contact-modal', (e, el, ds) => openContactModal(ds.id ? parseInt(ds.id, 10) : null));
    registerAction('delete-contact',     (e, el, ds) => deleteContact(parseInt(ds.id, 10), ds.name || ''));
    registerAction('save-contact',       (e, el, ds) => saveContact(e, ds.id ? parseInt(ds.id, 10) : null));
    registerAction('filter-contacts',    (e) => filterContacts(e.target.value));
    loadContacts();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
