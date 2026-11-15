/*
 * AIlly, the website assistant (specs/websites/11). A small chat that answers questions, shows matching homes and
 * hands the visitor to an agent. Everything from the server is inserted as text (never HTML). The chat starts only
 * when the visitor opens it, and continues across pages in this browser tab. Visitors can book a call, showing or
 * listing appointment in an agent's open times (specs/websites/13). The interface follows the browser's language
 * (English or Spanish); the assistant replies in whatever language the visitor writes. When an agent joins the chat
 * (specs/websites/14), the widget checks for their messages every few seconds while it is open.
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
    var es = /^es\b/i.test(navigator.language || '');
    var T = {
        callQ: ['Pick a time for your call', 'Elija una hora para su llamada'],
        showQ: [
            'Pick a time to see this home',
            'Elija una hora para ver esta casa',
        ],
        listQ: [
            'Pick a time for an agent to price your home',
            'Elija una hora para que un agente valore su casa',
        ],
        who: [
            'Who should an agent follow up with?',
            '¿Con quién debe comunicarse un agente?',
        ],
        name: ['Name', 'Nombre'],
        email: ['Email', 'Correo electrónico'],
        phone: ['Mobile phone', 'Teléfono móvil'],
        when: ['Best time (optional)', 'Mejor hora (opcional)'],
        noSlots: [
            'No open times online right now. Tell us a good time and an agent will confirm.',
            'No hay horarios disponibles en línea. Díganos una buena hora y un agente lo confirmará.',
        ],
        loading: ['Finding open times…', 'Buscando horarios…'],
        send: ['Send', 'Enviar'],
        book: ['Book', 'Reservar'],
        need: [
            'Add an email or a phone number.',
            'Agregue un correo o un teléfono.',
        ],
        check: [
            'Please check your details and try again.',
            'Revise sus datos e inténtelo de nuevo.',
        ],
        bookedCall: [
            'Your call is booked for ',
            'Su llamada está reservada para el ',
        ],
        bookedShow: [
            'Your showing is booked for ',
            'Su visita está reservada para el ',
        ],
        bookedList: [
            'Your home value appointment is booked for ',
            'Su cita de valoración está reservada para el ',
        ],
        confirm: [
            '. A confirmation is on its way.',
            '. Le enviamos una confirmación.',
        ],
        thanksCall: [
            'Thanks! An agent will call you soon.',
            '¡Gracias! Un agente le llamará pronto.',
        ],
        thanksShow: [
            'Thanks! An agent will be in touch to set up the showing.',
            '¡Gracias! Un agente se comunicará para coordinar la visita.',
        ],
        thanksList: [
            "Thanks! An agent will be in touch about your home's value.",
            '¡Gracias! Un agente se comunicará sobre el valor de su casa.',
        ],
        thanks: [
            'Thanks! An agent has your details. What else can I help with?',
            '¡Gracias! Un agente tiene sus datos. ¿En qué más puedo ayudarle?',
        ],
        bookCall: [
            'Book a call with an agent',
            'Reservar una llamada con un agente',
        ],
        bookList: [
            'Book a listing appointment',
            'Reservar una cita para vender',
        ],
        seeIt: ['Ask to see it', 'Pedir una visita'],
        allHomes: ['See all homes', 'Ver todas las casas'],
        save: [
            'Save a search and get alerts',
            'Guardar búsqueda y recibir alertas',
        ],
        worth: ['What is my home worth?', '¿Cuánto vale mi casa?'],
        agent: ['Talk to an agent', 'Hablar con un agente'],
        seeNew: ['See them', 'Verlas'],
        range: ['Likely range', 'Rango probable'],
        basis: [
            'From {n} similar homes for sale in {zip}. An agent confirms it with recent sales.',
            'Según {n} casas similares en venta en {zip}. Un agente lo confirma con ventas recientes.',
        ],
        nudge: [
            'Questions about this home? I can book a showing in a minute.',
            '¿Preguntas sobre esta casa? Puedo reservar una visita en un minuto.',
        ],
        typing: [' is typing…', ' está escribiendo…'],
        wrong: [
            'Sorry, something went wrong. Please try again.',
            'Lo siento, algo salió mal. Inténtelo de nuevo.',
        ],
        off: [
            'Chat is not available right now. Use the contact page and an agent will reply.',
            'El chat no está disponible. Use la página de contacto y un agente le responderá.',
        ],
        placeholder: [
            'Ask about homes, the area or selling…',
            'Pregunte sobre casas, la zona o vender…',
        ],
        note: [
            "Answers are AI-generated and may be wrong. Don't share financial details here.",
            'Las respuestas son generadas por IA y pueden tener errores. No comparta datos financieros aquí.',
        ],
    };
    function t(key) {
        return T[key][es ? 1 : 0];
    }
    function face() {
        return avatar && avatar.content.firstElementChild
            ? avatar.content.firstElementChild.cloneNode(true)
            : el('span');
    }
    if (es) {
        input.placeholder = t('placeholder');
        var note = root.querySelector('.ailly-note');
        if (note) note.textContent = t('note');
    }
    root.querySelectorAll('[data-ailly-face]').forEach(function (slot) {
        slot.appendChild(face());
    });
    var state = { id: null, items: [], last: 0 };
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
                JSON.stringify({
                    id: state.id,
                    items: state.items.slice(-30),
                    last: state.last || 0,
                }),
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
    var agentSite =
        (location.pathname.match(/^\/agents\/([a-z0-9-]{1,80})(\/|$)/) ||
            [])[1] || null;
    function post(url, body) {
        if (agentSite) body.agent_site = agentSite;
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
        if (item.role === 'note') {
            b.appendChild(el('p', null, item.text));
            log.appendChild(b);
            return;
        }
        if (item.role === 'agent') {
            b.appendChild(el('strong', 'ailly-by', item.by || 'Agent'));
            b.appendChild(el('p', null, item.text));
            log.appendChild(b);
            return;
        }
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
            var show = el('button', 'ailly-chip', t('seeIt'));
            show.type = 'button';
            show.addEventListener('click', function () {
                add({ role: 'contact', want: 'showing', listing: h.ulid });
            });
            card.appendChild(show);
            b.appendChild(card);
        });
        if (item.value) b.appendChild(valueCard(item.value));
        if (item.link) {
            var go = el('a', 'ailly-chip', t('seeNew'));
            go.href = item.link;
            b.appendChild(go);
        }
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
                chip(t('bookCall'), function () {
                    add({ role: 'contact', want: 'call' });
                });
            if (next === 'book_listing')
                chip(t('bookList'), function () {
                    add({ role: 'contact', want: 'listing' });
                });
            if (next === 'see_homes' || next === 'save_search')
                chip(
                    next === 'save_search' ? t('save') : t('allHomes'),
                    function () {
                        location.href = '/homes';
                    },
                );
            if (next === 'home_value')
                chip(t('worth'), function () {
                    location.href = '/home-value';
                });
            if (next !== 'book_call' && next !== 'book_listing')
                chip(t('agent'), function () {
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

    function valueCard(v) {
        var card = el('div', 'ailly-value');
        card.appendChild(el('small', null, t('range')));
        card.appendChild(
            el('strong', null, money(v.low) + ' – ' + money(v.high)),
        );
        card.appendChild(
            el(
                'p',
                null,
                t('basis').replace('{n}', v.comps).replace('{zip}', v.zip),
            ),
        );
        return card;
    }

    function slotPicker(f, want, chosen) {
        var box = el('fieldset', 'ailly-slots');
        box.appendChild(
            el(
                'legend',
                null,
                t(
                    want === 'showing'
                        ? 'showQ'
                        : want === 'listing'
                          ? 'listQ'
                          : 'callQ',
                ),
            ),
        );
        var list = el('div', 'ailly-slot-list', t('loading'));
        box.appendChild(list);
        function fill(slots) {
            list.textContent = '';
            if (!slots || !slots.length) {
                list.appendChild(el('p', null, t('noSlots')));
                var l = el('label', null, t('when'));
                var i = el('input');
                i.type = 'text';
                i.name = 'when';
                i.autocomplete = 'off';
                l.appendChild(i);
                list.appendChild(l);
                return;
            }
            slots.forEach(function (s, n) {
                var lab = el('label', 'ailly-slot');
                var r = el('input');
                r.type = 'radio';
                r.name = 'slot';
                r.value = s.start;
                r.required = true;
                r.setAttribute('data-agent', s.agent);
                if (n === 0) r.checked = true;
                lab.appendChild(r);
                lab.appendChild(
                    el(
                        'span',
                        null,
                        s.label + (s.agent_name ? ' · ' + s.agent_name : ''),
                    ),
                );
                list.appendChild(lab);
            });
        }
        chosen.refill = fill;
        post('/_ailly/slots', { kind: want }).then(function (d) {
            fill(d && d.ok ? d.slots : []);
        });
        return box;
    }

    function contactForm(want, listing) {
        var f = el('form', 'ailly-contact');
        var picker = {};
        if (want === 'call' || want === 'showing' || want === 'listing')
            f.appendChild(slotPicker(f, want, picker));
        else f.appendChild(el('p', null, t('who')));
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
        field(t('name'), 'text', 'name', 'name', true);
        field(t('email'), 'email', 'email', 'email');
        field(t('phone'), 'tel', 'phone', 'tel');
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
        var send = el(
            'button',
            'btn btn-primary',
            want ? t('book') : t('send'),
        );
        send.type = 'submit';
        f.appendChild(send);
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!f.email.value && !f.phone.value) {
                err.textContent = t('need');
                return;
            }
            var slot = f.querySelector('input[name="slot"]:checked');
            var when = f.querySelector('input[name="when"]');
            send.disabled = true;
            post('/_ailly/contact', {
                conversation: state.id,
                name: f.name.value,
                email: f.email.value || null,
                phone: f.phone.value || null,
                sms_consent: box.checked,
                want: want || null,
                slot: slot ? slot.value : null,
                slot_agent: slot ? slot.getAttribute('data-agent') : null,
                when: when ? when.value : null,
                listing: listing || null,
                website: hp.value,
                vid: vid(),
            }).then(function (d) {
                if (!d.ok) {
                    send.disabled = false;
                    err.textContent = d.error || t('check');
                    if (d.slots && picker.refill) picker.refill(d.slots);
                    return;
                }
                state.items = state.items.filter(function (i) {
                    return i.role !== 'contact';
                });
                f.remove();
                var text = d.booked
                    ? t(
                          want === 'showing'
                              ? 'bookedShow'
                              : want === 'listing'
                                ? 'bookedList'
                                : 'bookedCall',
                      ) +
                      d.booked +
                      t('confirm')
                    : t(
                          want === 'call'
                              ? 'thanksCall'
                              : want === 'showing'
                                ? 'thanksShow'
                                : want === 'listing'
                                  ? 'thanksList'
                                  : 'thanks',
                      );
                add({ role: 'ailly', text: text });
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
                    text: t('off'),
                });
                form.hidden = true;
                return false;
            }
            state.id = d.conversation;
            add({
                role: 'ailly',
                text: d.greeting,
                link: d.search_url || null,
            });
            if (d.needs_contact) add({ role: 'contact' });
            return true;
        });
    }

    // An agent's messages, while the chat is open.
    var agentMode = false;
    var timer = null;
    function poll() {
        if (!state.id || panel.hidden) return;
        post('/_ailly/poll', {
            conversation: state.id,
            after: state.last || 0,
        }).then(function (d) {
            if (d && d.ok) {
                (d.messages || []).forEach(function (m) {
                    state.last = Math.max(state.last || 0, m.id);
                    add({ role: m.role, text: m.body, by: m.by });
                });
                agentMode = !!d.agent;
                save();
            }
            clearTimeout(timer);
            if (!panel.hidden && state.id)
                timer = setTimeout(poll, agentMode ? 3000 : 8000);
        });
    }

    function open() {
        panel.hidden = false;
        openBtn.setAttribute('aria-expanded', 'true');
        if (!log.childElementCount) state.items.forEach(render);
        start().then(function () {
            input.focus();
            poll();
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
            name + t('typing'),
        );
        log.appendChild(typing);
        log.scrollTop = log.scrollHeight;
        post('/_ailly/message', {
            conversation: state.id,
            message: text,
            website: form.website.value,
        }).then(function (d) {
            typing.remove();
            if (d && d.agent) {
                agentMode = true;
                poll();
                return;
            }
            if (!d || !d.reply) {
                add({
                    role: 'ailly',
                    text: t('wrong'),
                });
                return;
            }
            add({
                role: 'ailly',
                text: d.reply,
                homes: d.homes,
                value: d.value || null,
                next: d.next_step,
            });
            if (d.needs_contact)
                add({
                    role: 'contact',
                    want:
                        d.next_step === 'book_call'
                            ? 'call'
                            : d.next_step === 'book_listing'
                              ? 'listing'
                              : null,
                });
            if (d.ended) {
                state.id = null;
                save();
            }
        });
    });

    // A gentle offer on a home's page after 40 seconds, once per tab session, never opening the chat by itself.
    if (
        root.hasAttribute('data-nudge') &&
        /^\/homes\/[0-9A-Za-z]{26}/.test(location.pathname)
    ) {
        var nudged = false;
        try {
            nudged = sessionStorage.getItem('ra_ailly_nudged') === '1';
        } catch (e) {
            nudged = true;
        }
        if (!nudged && !state.id)
            setTimeout(function () {
                if (!panel.hidden || state.id) return;
                var bubble = el('button', 'ailly-nudge', t('nudge'));
                bubble.type = 'button';
                bubble.addEventListener('click', function () {
                    bubble.remove();
                    open();
                });
                root.insertBefore(bubble, openBtn);
                try {
                    sessionStorage.setItem('ra_ailly_nudged', '1');
                } catch (e) {
                    /* ignore */
                }
            }, 40000);
    }
})();
