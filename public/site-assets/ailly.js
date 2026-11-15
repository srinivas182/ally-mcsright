/*
 * AIlly, the website assistant (specs/websites/11). A small chat that answers questions, shows matching homes and
 * hands the visitor to an agent. Everything from the server is inserted as text (never HTML). The chat starts only
 * when the visitor opens it, and continues across pages in this browser tab.
 */
(function () {
    'use strict';
    var root = document.querySelector('[data-ailly]');
    if (!root) return;
    var name = root.getAttribute('data-ailly') || 'Ally';
    var consent = root.getAttribute('data-consent') || '';
    var openBtn = root.querySelector('[data-ailly-open]');
    var panel = root.querySelector('[data-ailly-panel]');
    var log = root.querySelector('[data-ailly-log]');
    var form = root.querySelector('[data-ailly-form]');
    var input = form.querySelector('input[name="message"]');
    var closeBtn = root.querySelector('[data-ailly-close]');
    var avatar = root.querySelector('[data-ailly-avatar]');
    function face() {
        return avatar && avatar.content.firstElementChild
            ? avatar.content.firstElementChild.cloneNode(true)
            : el('span');
    }
    root.querySelectorAll('[data-ailly-face]').forEach(function (slot) {
        slot.appendChild(face());
    });
    var state = { id: null, items: [] };
    try {
        state =
            JSON.parse(sessionStorage.getItem('ra_ailly') || 'null') || state;
    } catch (e) {
        /* storage blocked: the chat lives on this page only */
    }

    function save() {
        try {
            sessionStorage.setItem(
                'ra_ailly',
                JSON.stringify({ id: state.id, items: state.items.slice(-30) }),
            );
        } catch (e) {
            /* ignore */
        }
    }
    function vid() {
        try {
            return JSON.parse(localStorage.getItem('ra_vid') || 'null');
        } catch (e) {
            return null;
        }
    }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }
    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }).then(function (r) {
            return r.json().catch(function () {
                return { ok: false };
            });
        });
    }
    function money(v) {
        return v ? '$' + Number(v).toLocaleString('en-US') : '';
    }

    function render(item) {
        if (item.role === 'contact')
            return log.appendChild(contactForm(item.want, item.listing));
        var b = el('div', 'ailly-msg ailly-' + item.role);
        if (item.role === 'ailly') {
            var row0 = el('div', 'ailly-line');
            var f = el('span', 'ailly-face');
            f.appendChild(face());
            row0.appendChild(f);
            row0.appendChild(el('p', null, item.text));
            b.appendChild(row0);
        } else {
            b.appendChild(el('p', null, item.text));
        }
        (item.homes || []).forEach(function (h) {
            var card = el('div', 'ailly-home');
            var a = el('a');
            a.href = h.url || '/homes/' + encodeURIComponent(h.ulid);
            if (h.photo) {
                var img = el('img');
                img.src = h.photo;
                img.alt = h.label || 'Home for sale';
                img.loading = 'lazy';
                a.appendChild(img);
            }
            a.appendChild(el('strong', null, money(h.price)));
            a.appendChild(
                el(
                    'span',
                    null,
                    [
                        h.beds ? h.beds + ' bd' : '',
                        h.baths ? h.baths + ' ba' : '',
                        h.label || '',
                    ]
                        .filter(Boolean)
                        .join(' · '),
                ),
            );
            card.appendChild(a);
            if (h.attribution)
                card.appendChild(el('small', null, h.attribution));
            var show = el('button', 'ailly-chip', 'Ask to see it');
            show.type = 'button';
            show.addEventListener('click', function () {
                add({ role: 'contact', want: 'showing', listing: h.ulid });
            });
            card.appendChild(show);
            b.appendChild(card);
        });
        var next = item.next;
        if (next && next !== 'none') {
            var row = el('div', 'ailly-next');
            var chip = function (label, fn) {
                var c = el('button', 'ailly-chip', label);
                c.type = 'button';
                c.addEventListener('click', fn);
                row.appendChild(c);
            };
            if (next === 'book_call')
                chip('Book a call with an agent', function () {
                    add({ role: 'contact', want: 'call' });
                });
            if (next === 'see_homes' || next === 'save_search')
                chip(
                    next === 'save_search'
                        ? 'Save a search and get alerts'
                        : 'See all homes',
                    function () {
                        location.href = '/homes';
                    },
                );
            if (next === 'home_value')
                chip('What is my home worth?', function () {
                    location.href = '/home-value';
                });
            if (next !== 'book_call')
                chip('Talk to an agent', function () {
                    add({ role: 'contact', want: 'call' });
                });
            b.appendChild(row);
        }
        log.appendChild(b);
    }
    function add(item) {
        state.items.push(item);
        save();
        render(item);
        log.scrollTop = log.scrollHeight;
    }

    function contactForm(want, listing) {
        var f = el('form', 'ailly-contact');
        f.appendChild(
            el(
                'p',
                null,
                want === 'call'
                    ? 'When is a good time for a call?'
                    : want === 'showing'
                      ? 'Who should the agent contact about this home?'
                      : 'Who should an agent follow up with?',
            ),
        );
        var field = function (label, type, nameAttr, auto, required) {
            var l = el('label', null, label);
            var i = el('input');
            i.type = type;
            i.name = nameAttr;
            if (auto) i.autocomplete = auto;
            if (required) i.required = true;
            l.appendChild(i);
            f.appendChild(l);
        };
        field('Name', 'text', 'name', 'name', true);
        field('Email', 'email', 'email', 'email');
        field('Mobile phone', 'tel', 'phone', 'tel');
        if (want === 'call')
            field('Best time (optional)', 'text', 'when', 'off');
        var c = el('label', 'consent');
        var box = el('input');
        box.type = 'checkbox';
        box.name = 'sms_consent';
        c.appendChild(box);
        c.appendChild(el('span', null, consent));
        f.appendChild(c);
        var hp = el('input');
        hp.type = 'text';
        hp.name = 'website';
        hp.tabIndex = -1;
        hp.autocomplete = 'off';
        hp.className = 'ailly-hp';
        f.appendChild(hp);
        var err = el('p', 'ailly-error');
        err.setAttribute('role', 'alert');
        f.appendChild(err);
        var send = el('button', 'btn btn-primary', 'Send');
        send.type = 'submit';
        f.appendChild(send);
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!f.email.value && !f.phone.value) {
                err.textContent = 'Add an email or a phone number.';
                return;
            }
            send.disabled = true;
            post('/_ailly/contact', {
                conversation: state.id,
                name: f.name.value,
                email: f.email.value || null,
                phone: f.phone.value || null,
                sms_consent: box.checked,
                want: want || null,
                when: f.when ? f.when.value : null,
                listing: listing || null,
                website: hp.value,
                vid: vid(),
            }).then(function (d) {
                if (!d.ok) {
                    send.disabled = false;
                    err.textContent =
                        'Please check your details and try again.';
                    return;
                }
                state.items = state.items.filter(function (i) {
                    return i.role !== 'contact';
                });
                f.remove();
                add({
                    role: 'ailly',
                    text:
                        want === 'call'
                            ? 'Thanks! An agent will call you soon.'
                            : want === 'showing'
                              ? 'Thanks! An agent will be in touch to set up the showing.'
                              : 'Thanks! An agent has your details. What else can I help with?',
                });
            });
        });
        return f;
    }

    function start() {
        if (state.id) return Promise.resolve(true);
        return post('/_ailly/start', {
            vid: vid(),
            page: location.pathname,
            website: '',
        }).then(function (d) {
            if (!d.ok) {
                add({
                    role: 'ailly',
                    text: 'Chat is not available right now. Use the contact page and an agent will reply.',
                });
                form.hidden = true;
                return false;
            }
            state.id = d.conversation;
            add({ role: 'ailly', text: d.greeting });
            if (d.needs_contact) add({ role: 'contact' });
            return true;
        });
    }

    function open() {
        panel.hidden = false;
        openBtn.setAttribute('aria-expanded', 'true');
        if (!log.childElementCount) state.items.forEach(render);
        start().then(function () {
            input.focus();
        });
    }
    function close() {
        panel.hidden = true;
        openBtn.setAttribute('aria-expanded', 'false');
        openBtn.focus();
    }
    openBtn.addEventListener('click', function () {
        panel.hidden ? open() : close();
    });
    closeBtn.addEventListener('click', close);
    panel.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') close();
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var text = input.value.trim();
        if (!text || !state.id) return;
        input.value = '';
        add({ role: 'visitor', text: text });
        var typing = el(
            'div',
            'ailly-msg ailly-ailly ailly-typing',
            name + ' is typing…',
        );
        log.appendChild(typing);
        log.scrollTop = log.scrollHeight;
        post('/_ailly/message', {
            conversation: state.id,
            message: text,
            website: form.website.value,
        }).then(function (d) {
            typing.remove();
            if (!d || !d.reply) {
                add({
                    role: 'ailly',
                    text: 'Sorry, something went wrong. Please try again.',
                });
                return;
            }
            add({
                role: 'ailly',
                text: d.reply,
                homes: d.homes,
                next: d.next_step,
            });
            if (d.needs_contact)
                add({
                    role: 'contact',
                    want: d.next_step === 'book_call' ? 'call' : null,
                });
            if (d.ended) {
                state.id = null;
                save();
            }
        });
    });
})();
