'use strict';
const $ = (selector, root = document) => root.querySelector(selector);
const months = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
const shortMonths = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
let state = {}, csrf = '', monthFilter = 0, authMode = 'login', editingRevision = '', toastTimer;
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const when = event => event.days_until === null ? 'Vergangen' : event.days_until === 0 ? 'Heute' : event.days_until === 1 ? 'Morgen' : `In ${event.days_until} Tagen`;
function errorAt(id, message = '') { const node = $(id); node.textContent = message; node.hidden = !message; }
function toast(message) { clearTimeout(toastTimer); $('#toast').textContent = message; $('#toast').hidden = false; toastTimer = setTimeout(() => { $('#toast').hidden = true; }, 5000); }
async function api(action, data = {}) {
    const response = await fetch(action === 'state' ? 'api.php?action=state' : 'api.php', action === 'state' ? { cache: 'no-store' } : {
        method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ ...data, action })
    });
    let result;
    try { result = await response.json(); } catch { throw new Error('Der Server antwortet nicht wie erwartet. Bitte die PHP-Konfiguration prüfen.'); }
    if (result.csrf) csrf = result.csrf;
    if (!response.ok) {
        if (response.status === 401 && state.authenticated) {
            document.querySelectorAll('dialog[open]').forEach(dialog => dialog.close());
            await refresh();
        }
        throw new Error(result.error || 'Das hat leider nicht geklappt. Bitte erneut versuchen.');
    }
    return result;
}
async function busy(button, task, errorId) {
    button.disabled = true;
    try { if (errorId) errorAt(errorId); await task(); }
    catch (error) { if (errorId) errorAt(errorId, error.message); else toast(error.message); }
    finally { button.disabled = false; }
}
async function refresh() {
    state = await api('state');
    $('#loading').hidden = true;
    const ready = state.authenticated && !state.user.must_change_password;
    $('#auth-panel').hidden = state.authenticated;
    $('#welcome-panel').hidden = !state.authenticated || ready;
    $('#calendar-app').hidden = !ready;
    $('#user-nav').hidden = !ready;
    $('#private-label').hidden = ready;
    $('#group-label').textContent = state.group_name;
    document.title = state.group_name + ' · Familienkalender';
    if (state.background_image) { $('#hero-photo').src = state.background_image; $('#hero-photo').hidden = false; }
    if (!state.authenticated) { setAuthMode('login'); return; }
    if (!ready) { $('#welcome-name').textContent = state.user.name; return; }
    $('#profile-open').textContent = state.user.name;
    $('#today-label').textContent = new Intl.DateTimeFormat('de-DE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(state.today + 'T12:00:00'));
    render();
}
function setAuthMode(mode) {
    hidePasswords($('#auth-form'));
    authMode = mode; errorAt('#auth-error'); $('#auth-info').textContent = '';
    const reset = mode === 'reset';
    $('#auth-title').textContent = reset ? 'Ein neuer Anfang.' : 'Willkommen bei uns.';
    $('#auth-description').textContent = reset ? 'Trage den achtstelligen Passwort-Code aus der Reset-Mail ein und wähle ein neues Passwort.' : 'Melde dich mit deinem eigenen Konto an. Die schönen Tage teilen wir uns.';
    $('#code-label').hidden = !reset; $('#auth-form').elements.code.required = reset;
    $('#confirm-label').hidden = !reset; $('#auth-form').elements.confirmation.required = reset;
    $('#remember-label').hidden = reset; $('#setup-code').hidden = reset || state.local;
    $('#password-label').textContent = reset ? 'Neues Passwort (mindestens 10 Zeichen)' : 'Dein Passwort';
    $('#login-password').minLength = reset ? 10 : 1;
    $('#login-password').autocomplete = reset ? 'new-password' : 'current-password';
    $('#login-password').value = ''; $('#auth-form').elements.confirmation.value = '';
    $('#auth-submit').textContent = reset ? 'Passwort speichern & anmelden' : 'Kalender öffnen →';
    $('#recover').hidden = reset; $('#enter-code').hidden = reset; $('#back-login').hidden = !reset;
    hidePasswords($('#auth-form'));
}
function render() {
    const upcoming = state.events.filter(e => e.enabled && e.days_until !== null).sort((a, b) => a.days_until - b.days_until || a.name.localeCompare(b.name, 'de')).slice(0, 3);
    $('#upcoming').innerHTML = upcoming.length ? upcoming.map((e, i) => `<button class="upcoming-card card-${i}" data-edit="${esc(e.id)}"><span class="date-tile"><strong>${String(e.next_date.slice(8))}</strong><span>${shortMonths[Number(e.next_date.slice(5, 7)) - 1].toUpperCase()}</span></span><span class="upcoming-person"><span class="mini-label">${esc(when(e))}</span><strong>${esc(e.name)}</strong><span>${esc(e.occasion)}${e.age === null || e.repeat === 'once' ? '' : ` · ${e.age} Jahre`}</span></span><span class="card-decoration" aria-hidden="true">${e.occasion === 'Geburtstag' ? '✳' : '♡'}</span></button>`).join('') : '<div class="upcoming-none">Dein nächster schöner Anlass wartet auf einen Eintrag.</div>';
    $('#total-count').textContent = state.events.length;
    $('#months').innerHTML = ['Alle Monate', ...shortMonths].map((label, i) => `<button class="month-tab${monthFilter === i ? ' active' : ''}" data-month="${i}" aria-pressed="${monthFilter === i}">${label}</button>`).join('');
    renderList();
    $('#mail-summary').textContent = state.settings.mail_enabled ? 'Eine kleine Erinnerung am Morgen.' : 'Deine Erinnerungsmails pausieren.';
    $('#mail-detail').textContent = state.settings.mail_enabled ? `An ${state.members.length} Mitglieder · ${state.delivery.last_run ? 'Letzter Aufruf: ' + new Intl.DateTimeFormat('de-DE', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(state.delivery.last_run)) : 'Der erste tägliche CRON-Aufruf steht noch aus.'}` : 'Du kannst den Versand jederzeit in den Einstellungen wieder aktivieren.';
    if (['failed', 'partial'].includes(state.delivery.status)) $('#mail-detail').textContent += ' · Der letzte Versand ist fehlgeschlagen.';
}
function renderList() {
    const query = $('#search').value.trim().toLocaleLowerCase('de'); const type = $('#type-filter').value;
    let events = state.events.filter(e => (!monthFilter || e.month === monthFilter) && (!query || `${e.name} ${e.occasion} ${e.notes}`.toLocaleLowerCase('de').includes(query)) && (type === 'all' || (type === 'birthday' && e.occasion === 'Geburtstag') || (type === 'other' && e.occasion !== 'Geburtstag') || (type === 'review' && e.warnings.length) || (type === 'paused' && !e.enabled)));
    const sort = $('#sort').value;
    events.sort((a, b) => (sort === 'name' ? 0 : sort === 'date' ? a.month - b.month || a.day - b.day : (a.days_until ?? Infinity) - (b.days_until ?? Infinity)) || a.name.localeCompare(b.name, 'de'));
    $('#event-list').innerHTML = events.map(e => `<tr class="${e.enabled ? '' : 'paused-row'}"><td><span class="row-date">${String(e.day).padStart(2, '0')}. ${shortMonths[e.month - 1]}.</span><span class="date-meta${e.days_until === 0 ? ' is-today' : ''}">${esc(when(e))}</span></td><td><span class="person-name">${esc(e.name)}${e.warnings.length ? ` <span class="warning-badge" title="${esc(e.warnings.join(' · '))}" aria-label="${esc(e.warnings.join(' · '))}">!</span>` : ''}</span><span class="occasion-label ${e.occasion === 'Geburtstag' ? '' : 'special'}">${esc(e.occasion)}${e.repeat === 'once' ? ' · einmalig' : ''}</span></td><td><span>${e.year ?? '<span class="muted">—</span>'}</span><span class="date-meta">${e.repeat === 'once' ? 'Einmalig' : e.age === null ? (e.year === null ? 'Jahr unbekannt' : 'Vergangen') : `${e.age} Jahre`}</span></td><td class="notes-cell"><span title="${esc(e.notes)}">${e.notes ? esc(e.notes) : '<span class="muted faint">Platz für einen Gedanken …</span>'}</span></td><td><span class="reminder-pill${e.enabled ? '' : ' off'}">${e.enabled ? 'Am Tag' : 'Pausiert'}</span>${e.enabled && e.remind_before ? `<span class="date-meta">+ ${e.remind_before} Tage vorher</span>` : ''}</td><td><button class="edit-button" data-edit="${esc(e.id)}" aria-label="${esc(e.name)} bearbeiten"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m15 5 4 4M4 20l4-1L20 7a2.8 2.8 0 0 0-4-4L4 15z"/></svg></button></td></tr>`).join('');
    $('#empty').hidden = events.length > 0;
    $('#result-count').textContent = `${events.length} von ${state.events.length} Terminen${events.some(e => e.warnings.length) ? ' · ! Einträge zum Prüfen' : ''}`;
}
function openEvent(id = '') {
    const event = state.events.find(e => e.id === id);
    const values = event || { id: '', name: '', day: '', month: Number(state.today.slice(5, 7)), year: '', occasion: '', notes: '', repeat: 'yearly', remind_before: 0, enabled: true };
    const form = $('#event-form'); form.reset(); editingRevision = state.revision;
    if (!$(`#remind-before option[value="${values.remind_before}"]`)) $('#remind-before').add(new Option(`${values.remind_before} Tage vorher`, values.remind_before));
    for (const key of ['id', 'name', 'day', 'month', 'year', 'occasion', 'notes', 'repeat', 'remind_before']) form.elements[key].value = values[key] ?? '';
    form.elements.enabled.checked = values.enabled;
    $('#event-title').textContent = event ? 'Termin bearbeiten' : 'Neuer Termin'; $('#delete-event').hidden = !event;
    $('#event-warning').hidden = !event?.warnings.length; $('#event-warning').textContent = event?.warnings.join(' · ') || '';
    errorAt('#event-error'); $('#event-dialog').showModal();
}
function openSettings() {
    const form = $('#settings-form'); form.reset();
    for (const key of ['timezone', 'leap_day']) {
        if (key === 'timezone' && !Array.from(form.elements.timezone.options).some(o => o.value === state.settings.timezone)) form.elements.timezone.add(new Option(state.settings.timezone, state.settings.timezone));
        form.elements[key].value = state.settings[key];
    }
    form.elements.mail_enabled.checked = state.settings.mail_enabled;
    $('#sender-detail').textContent = 'Absender: ' + state.sender;
    const statusLabels = { sent: 'Mail an den Mailserver übergeben', empty: 'Keine neuen fälligen Erinnerungen', paused: 'Versand pausiert', failed: 'Versand fehlgeschlagen', partial: 'Teilweise verschickt; erneuter Aufruf versucht fehlende Empfänger'  };
    $('#delivery-detail').textContent = state.delivery.last_run ? `Letzter Aufruf: ${new Date(state.delivery.last_run).toLocaleString('de-DE')} · ${statusLabels[state.delivery.status] || 'Unbekannt'}` : 'Noch kein CRON-Aufruf. Die tägliche Uhrzeit legst du am Server fest.';
    $('#test-mail-result').textContent = ''; errorAt('#settings-error'); $('#settings-dialog').showModal();
}
$('#event-month').innerHTML = months.map((m, i) => `<option value="${i + 1}">${m}</option>`).join('');
$('#hero-photo').addEventListener('error', () => { $('#hero-photo').hidden = true; });
$('#auth-form').addEventListener('submit', event => {
    event.preventDefault(); const form = event.currentTarget;
    busy($('#auth-submit'), async () => {
        if (authMode === 'reset' && form.elements.password.value !== form.elements.confirmation.value) throw new Error('Die beiden Passwörter stimmen nicht überein.');
        await api(authMode, { identity: form.elements.identity.value, password: form.elements.password.value, setup_key: form.elements.setup_key.value, code: form.elements.code.value, remember: form.elements.remember.checked });
        form.elements.password.value = ''; form.elements.confirmation.value = ''; form.elements.setup_key.value = '';
        await refresh(); toast(`Schön, dass du da bist, ${state.user.name}!`);
    }, '#auth-error');
});
$('#recover').addEventListener('click', () => busy($('#recover'), async () => {
    const identity = $('#auth-form').elements.identity.value.trim();
    if (!identity) throw new Error('Bitte zuerst deine E-Mail-Adresse oder Kennung eingeben.');
    const result = await api('recover', { identity }); setAuthMode('reset'); $('#auth-info').textContent = result.message;
}, '#auth-error'));
$('#enter-code').addEventListener('click', () => setAuthMode('reset'));
$('#back-login').addEventListener('click', () => setAuthMode('login'));
async function logout() { await api('logout'); state = {}; await refresh(); toast('Du bist abgemeldet.'); }
$('#logout').addEventListener('click', () => busy($('#logout'), logout));
$('#welcome-logout').addEventListener('click', () => busy($('#welcome-logout'), logout));
$('#welcome-form').addEventListener('submit', event => {
    event.preventDefault(); const form = event.currentTarget;
    busy($('[type="submit"]', form), async () => {
        if (form.elements.new_password.value !== form.elements.confirmation.value) throw new Error('Die Passwörter stimmen nicht überein.');
        await api('profile', Object.fromEntries(new FormData(form))); form.reset(); await refresh(); toast('Dein persönlicher Zugang ist bereit.');
    }, '#welcome-error');
});
$('#add-event').addEventListener('click', () => openEvent());
$('#calendar-app').addEventListener('click', event => { const button = event.target.closest('[data-edit]'); if (button) openEvent(button.dataset.edit); });
$('#months').addEventListener('click', event => { const button = event.target.closest('[data-month]'); if (button) { monthFilter = Number(button.dataset.month); render(); } });
$('#search').addEventListener('input', renderList); $('#type-filter').addEventListener('change', renderList); $('#sort').addEventListener('change', renderList);
$('#clear-filters').addEventListener('click', () => { $('#search').value = ''; $('#type-filter').value = 'all'; monthFilter = 0; render(); });
document.querySelectorAll('.close-dialog').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
$('#event-form').addEventListener('submit', event => {
    event.preventDefault(); const form = event.currentTarget;
    busy($('[type="submit"]', form), async () => {
        const values = Object.fromEntries(new FormData(form)); values.enabled = form.elements.enabled.checked;
        await api('save', { id: values.id, revision: editingRevision, event: values });
        $('#event-dialog').close(); await refresh(); toast('Dein Termin ist gespeichert.');
    }, '#event-error');
});
$('#delete-event').addEventListener('click', () => { $('#delete-description').textContent = $('#event-form').elements.name.value; errorAt('#delete-error'); $('#delete-dialog').showModal(); });
$('#confirm-delete').addEventListener('click', () => busy($('#confirm-delete'), async () => {
    await api('delete', { id: $('#event-form').elements.id.value, revision: editingRevision }); $('#delete-dialog').close(); $('#event-dialog').close(); await refresh(); toast('Der Termin wurde gelöscht.');
}, '#delete-error'));
$('#settings-open').addEventListener('click', openSettings); $('#mail-settings').addEventListener('click', openSettings);
$('#settings-form').addEventListener('submit', event => {
    event.preventDefault(); const form = event.currentTarget;
    busy($('[type="submit"]', form), async () => {
        const values = Object.fromEntries(new FormData(form)); values.mail_enabled = form.elements.mail_enabled.checked;
        await api('settings', values); $('#settings-dialog').close(); form.reset(); await refresh(); toast('Deine Einstellungen sind gespeichert.');
    }, '#settings-error');
});
$('#test-mail').addEventListener('click', () => busy($('#test-mail'), async () => { $('#test-mail-result').textContent = 'Die Testmail wird an deine eigene Adresse gesendet …'; const result = await api('test_mail'); $('#test-mail-result').textContent = result.message; }, '#settings-error'));
refresh().catch(error => { $('#loading').textContent = error.message; });

$('#profile-open').addEventListener('click', () => {
    const form = $('#profile-form'); form.reset(); form.elements.name.value = state.user.name; form.elements.email.value = state.user.email;
    errorAt('#profile-error'); $('#profile-dialog').showModal();
});
$('#profile-form').addEventListener('submit', event => {
    event.preventDefault(); const form = event.currentTarget;
    busy($('[type="submit"]', form), async () => {
        if (form.elements.new_password.value !== form.elements.confirmation.value) throw new Error('Die neuen Passwörter stimmen nicht überein.');
        await api('profile', Object.fromEntries(new FormData(form))); form.reset(); $('#profile-dialog').close(); await refresh(); toast('Dein Profil ist gespeichert.');
    }, '#profile-error');
});
function renderMembers() {
    $('#member-list').innerHTML = state.members.map(m => `<div class="member-row"><span class="member-avatar" aria-hidden="true">${esc(m.name.slice(0,1))}</span><div><strong>${esc(m.name)}${m.id === state.user.id ? ' · du' : ''}</strong><span>${esc(m.email)}</span><small>${m.must_change_password ? 'Persönliches Passwort noch ausstehend' : 'Persönlicher Zugang aktiv'}</small></div>${m.must_change_password ? `<button class="text-button" data-resend-invitation="${esc(m.id)}">Einladung senden</button>` : ''}${m.id === state.user.id ? '' : `<button class="text-button danger" data-remove-member="${esc(m.id)}">Entfernen</button>`}</div>`).join('');
}
function memberMailResult() {
    let result = $('#member-mail-result');
    if (!result) {
        result = document.createElement('p'); result.id = 'member-mail-result'; result.className = 'small'; result.setAttribute('role', 'status');
        $('#member-error').before(result);
    }
    return result;
}
$('#members-open').addEventListener('click', () => {
    $('#member-form').reset(); errorAt('#member-error'); memberMailResult().textContent = '';
    $('#member-form .field-help').textContent = 'Neue Mitglieder erhalten eine Einladung mit Startpasswort und privatem Einrichtungscode. Sie müssen danach ein eigenes Passwort wählen.';
    renderMembers(); $('#members-dialog').showModal();
});
$('#member-form').addEventListener('submit', event => {
    event.preventDefault(); const form = event.currentTarget;
    busy($('[type="submit"]', form), async () => {
        const result = await api('add_member', Object.fromEntries(new FormData(form))); form.reset(); await refresh(); renderMembers(); memberMailResult().textContent = result.message;
    }, '#member-error');
});
$('#member-list').addEventListener('click', event => {
    const invitation = event.target.closest('[data-resend-invitation]');
    if (invitation) {
        busy(invitation, async () => {
            memberMailResult().textContent = 'Die Einladung wird an den Maildienst übergeben …';
            const result = await api('resend_invitation', { id: invitation.dataset.resendInvitation }); $('#member-mail-result').textContent = result.message;
        }, '#member-error');
        return;
    }
    const button = event.target.closest('[data-remove-member]'); if (!button) return;
    const member = state.members.find(m => m.id === button.dataset.removeMember);
    $('#remove-member-form').reset(); $('#remove-member-form').elements.id.value = member.id;
    $('#remove-member-description').textContent = `${member.name} (${member.email}) aus eurem Kalender entfernen?`;
    errorAt('#remove-member-error'); $('#remove-member-dialog').showModal();
});
$('#remove-member-form').addEventListener('submit', event => {
    event.preventDefault(); const form = event.currentTarget;
    busy($('[type="submit"]', form), async () => {
        await api('remove_member', Object.fromEntries(new FormData(form))); form.reset(); $('#remove-member-dialog').close(); await refresh(); renderMembers(); toast('Das Mitglied wurde entfernt.');
    }, '#remove-member-error');
});

// Shared visibility controls for login, setup codes and all account dialogs.
function hidePasswords(root = document) {
    root.querySelectorAll('.password-input').forEach(input => {
        input.type = 'password';
        const button = input.parentElement.querySelector('.password-toggle');
        button.textContent = 'Anzeigen';
        button.setAttribute('aria-label', `${input.labels[0].textContent.trim()} anzeigen`);
        button.setAttribute('aria-pressed', 'false');
    });
}
function initializePasswordToggles() {
    document.querySelectorAll('input[type="password"]').forEach((input, index) => {
        const label = input.closest('label');
        if (!label) return;
        if (!input.id) input.id = `password-field-${index + 1}`;
        const field = document.createElement('div');
        field.className = 'password-field';
        // Keep existing IDs/visibility controls on the whole field (e.g. confirmation).
        if (label.id) { field.id = label.id; label.removeAttribute('id'); }
        field.hidden = label.hidden; label.hidden = false;
        label.htmlFor = input.id;
        label.replaceWith(field);
        const control = document.createElement('div');
        control.className = 'password-control';
        input.classList.add('password-input');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'password-toggle';
        button.setAttribute('aria-controls', input.id);
        field.append(label, control);
        control.append(input, button);
        button.addEventListener('click', () => {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.textContent = visible ? 'Verbergen' : 'Anzeigen';
            button.setAttribute('aria-label', `${label.textContent.trim()} ${visible ? 'verbergen' : 'anzeigen'}`);
            button.setAttribute('aria-pressed', String(visible));
        });
    });
    hidePasswords();
    document.addEventListener('reset', event => hidePasswords(event.target), true);
    document.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('close', () => hidePasswords(dialog)));
}
initializePasswordToggles();
