/*
 * Movewise Homes, the little bit of JavaScript (specs/movewise/02): saving homes and searches, signing in with an
 * emailed code, and AIlly. Pages work without it; it only adds these. Everything it shows from the server is set as
 * text, never as HTML.
 */
(() => {
    const strings = JSON.parse(
        document.getElementById('mw-i18n')?.textContent || '{}',
    );
    const prefix = strings.prefix || '';
    const signin = document.getElementById('mw-signin');
    const ailly = document.getElementById('mw-ailly');
    let me = { signed_in: false, saved: [] };
    let pending = null;

    const post = async (path, body) => {
        const r = await fetch(prefix + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify(body),
        });
        const data = await r.json().catch(() => ({}));
        if (!r.ok) {
            const first = data.errors ? Object.values(data.errors)[0] : null;
            throw new Error(
                (Array.isArray(first) ? first[0] : first) ||
                    data.message ||
                    strings.error,
            );
        }

        return data;
    };

    // Signed in: do it now. Not yet: sign in first, then do it. A failure shows on the button rather than vanishing.
    const act = (fn) => {
        if (!me.signed_in) {
            pending = fn;
            signin?.showModal();

            return;
        }
        fn().catch((err) => {
            document.activeElement?.setAttribute('title', err.message);
        });
    };

    const paint = () => {
        document.querySelectorAll('[data-save]').forEach((b) => {
            const on = me.saved.includes(b.dataset.save);
            b.hidden = false;
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            b.textContent = on ? strings.saved : strings.save;
        });
        document
            .querySelectorAll('[data-save-search]')
            .forEach((b) => (b.hidden = false));
    };

    const saveHome = async (b) => {
        const on = b.getAttribute('aria-pressed') !== 'true';
        await post('/_me/homes', { listing: b.dataset.save, saved: on });
        me.saved = on
            ? [...me.saved, b.dataset.save]
            : me.saved.filter((u) => u !== b.dataset.save);
        paint();
    };

    const saveSearch = async (b) => {
        await post('/_me/searches', {
            filters: JSON.parse(b.dataset.saveSearch || '{}'),
        });
        b.disabled = true;
        b.textContent = strings.search_saved;
    };

    document.addEventListener('click', (e) => {
        const t = e.target instanceof Element ? e.target : null;
        const save = t?.closest('[data-save]');
        const search = t?.closest('[data-save-search]');
        const open = t?.closest('[data-ailly-open], #mw-ailly-open');
        if (save) {
            act(() => saveHome(save));
        } else if (search) {
            act(() => saveSearch(search));
        } else if (open && ailly) {
            ailly.dataset.listing =
                open.dataset.listing ||
                document.querySelector('[data-ailly-open]')?.dataset.listing ||
                '';
            ailly.showModal();
            ailly.querySelector('input')?.focus();
        } else if (t?.closest('[data-close]')) {
            t.closest('dialog')?.close();
        }
    });

    // Signing in: email → code → signed in (then finish what the person was doing).
    document.querySelectorAll('[data-signin-start]').forEach((form) => {
        const box = form.parentElement;
        const verify = box.querySelector('[data-signin-verify]');
        const error = box.querySelector('[data-signin-error]');
        let email = '';
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            error.textContent = '';
            email = new FormData(form).get('email');
            try {
                await post('/_auth/start', { email });
                form.hidden = true;
                verify.hidden = false;
                verify.querySelector('[data-sent]').textContent = email;
                verify.querySelector('input')?.focus();
            } catch (err) {
                error.textContent = err.message;
            }
        });
        verify.addEventListener('submit', async (e) => {
            e.preventDefault();
            error.textContent = '';
            try {
                await post('/_auth/verify', {
                    email,
                    code: new FormData(verify).get('code'),
                });
                if (box.closest('[data-signin-page]')) {
                    window.location.reload();

                    return;
                }
                me = await (
                    await fetch(prefix + '/_me', { credentials: 'same-origin' })
                ).json();
                signin?.close();
                paint();
                if (pending) {
                    await pending();
                    pending = null;
                }
            } catch (err) {
                error.textContent = err.message;
            }
        });
    });

    // AIlly: one conversation per visit, kept on the page only.
    const form = ailly?.querySelector('[data-ailly]');
    const chat = ailly?.querySelector('.chat');
    let chatId = null;
    const say = (text, mine) => {
        const p = document.createElement('p');
        p.textContent = text;
        if (mine) {
            p.className = 'me';
        }
        chat.append(p);
        chat.scrollTop = chat.scrollHeight;
    };
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const input = form.querySelector('input');
        const message = input.value.trim();
        if (!message) {
            return;
        }
        input.value = '';
        say(message, true);
        try {
            const r = await post('/_ailly', {
                chat: chatId,
                message,
                listing: ailly.dataset.listing || null,
            });
            chatId = r.chat;
            say(r.reply, false);
        } catch (err) {
            say(err.message || strings.error, false);
        }
    });

    document.getElementById('mw-ailly-open')?.removeAttribute('hidden');
    document
        .querySelectorAll('[data-ailly-open]')
        .forEach((b) => (b.hidden = false));
    fetch(prefix + '/_me', { credentials: 'same-origin' })
        .then((r) => r.json())
        .then((data) => {
            me = data;
            paint();
        })
        .catch(paint);
})();
