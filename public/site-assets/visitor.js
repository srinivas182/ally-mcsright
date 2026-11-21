/*
 * RightAlly website visitor features (specs/websites/07): saved homes, compare, sign-in by emailed code, saved
 * searches, the sign-up prompt, and the journey beacon. No cookies: a random id and, once signed in, a token are
 * kept in this browser. Browsers that send Global Privacy Control are not tracked.
 */
(function () {
    'use strict';
    var store = {
        get: function (k, d) {
            try {
                var v = localStorage.getItem(k);
                return v === null ? d : JSON.parse(v);
            } catch (e) {
                return d;
            }
        },
        set: function (k, v) {
            try {
                localStorage.setItem(k, JSON.stringify(v));
            } catch (e) {}
        },
        del: function (k) {
            try {
                localStorage.removeItem(k);
            } catch (e) {}
        },
    };
    var $ = function (s, r) {
        return (r || document).querySelector(s);
    };
    var $$ = function (s, r) {
        return Array.prototype.slice.call((r || document).querySelectorAll(s));
    };

    var vid = store.get('ra_vid', null);
    if (!vid) {
        var a = new Uint8Array(16);
        (window.crypto || {}).getRandomValues
            ? crypto.getRandomValues(a)
            : a.forEach(function (_, i) {
                  a[i] = Math.random() * 256;
              });
        vid = Array.prototype.map
            .call(a, function (b) {
                return ('0' + b.toString(16)).slice(-2);
            })
            .join('');
        store.set('ra_vid', vid);
    }
    $$('[data-vid]').forEach(function (i) {
        i.value = vid;
    });
    var me = store.get('ra_me', null); // {token, name, email, favourites}
    var first = store.get('ra_first_touch', null) || {};
    var track = !(
        navigator.globalPrivacyControl === true || navigator.doNotTrack === '1'
    );
    var queue = [];

    // ---- Journey beacon ----
    function send(ev) {
        if (!track) return;
        queue.push(ev);
        if (queue.length >= 10) flush();
    }
    function flush() {
        if (!queue.length) return;
        var body = JSON.stringify({
            vid: vid,
            token: me && me.token,
            ref: first.r || document.referrer,
            landing: first.l || location.href,
            events: queue.splice(0, 20),
        });
        if (
            !(
                navigator.sendBeacon &&
                navigator.sendBeacon(
                    '/_t',
                    new Blob([body], { type: 'text/plain' }),
                )
            )
        ) {
            fetch('/_t', {
                method: 'POST',
                body: body,
                keepalive: true,
                headers: { 'Content-Type': 'text/plain' },
            }).catch(function () {});
        }
    }
    setInterval(flush, 5000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') flush();
    });
    window.addEventListener('pagehide', flush);

    send({ type: 'page', path: location.pathname });
    var tracked = $('[data-track]');
    var kind = tracked && tracked.getAttribute('data-track');
    if (kind === 'listing') {
        var ulid = tracked.getAttribute('data-listing');
        send({ type: 'listing', listing: ulid, path: location.pathname });
        var views = store.get('ra_views', []);
        if (views.indexOf(ulid) < 0) {
            views.push(ulid);
            store.set('ra_views', views.slice(-50));
        }
    }
    if (kind === 'search' && location.search.length > 1)
        send({
            type: 'search',
            query: location.search.slice(1),
            path: '/homes',
        });
    document.addEventListener('ra:results', function (e) {
        if (e.detail.push)
            send({ type: 'search', query: e.detail.query, path: '/homes' });
        paint();
    });
    var mort = $('[data-mortgage]'),
        mortSent = false,
        mt = null;
    if (mort)
        mort.addEventListener('input', function () {
            clearTimeout(mt);
            mt = setTimeout(function () {
                if (mortSent) return;
                mortSent = true;
                send({
                    type: 'mortgage',
                    listing: mort.getAttribute('data-listing'),
                    down_pct: ($('[data-down]', mort) || {}).value,
                    rate: ($('[data-rate]', mort) || {}).value,
                });
                flush();
            }, 1500);
        });

    // ---- Account ----
    var dlg = $('[data-auth]'),
        err = $('[data-auth-error]'),
        pending = null,
        emailForCode = '',
        nameForCode = '',
        phone = '',
        consent = false;
    function api(method, url, data) {
        var h = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        };
        if (me && me.token) h.Authorization = 'Bearer ' + me.token;
        return fetch(url, {
            method: method,
            headers: h,
            body: data ? JSON.stringify(data) : undefined,
        }).then(function (r) {
            return r
                .json()
                .catch(function () {
                    return {};
                })
                .then(function (j) {
                    if (!r.ok) {
                        var m = j.message || 'Something went wrong. Try again.';
                        if (j.errors) m = Object.values(j.errors)[0][0];
                        throw new Error(m);
                    }
                    return j;
                });
        });
    }
    function showError(m) {
        err.textContent = m;
        err.hidden = !m;
    }
    function openAuth(title, lead) {
        if (!dlg) return;
        $('[data-auth-title]').textContent = me
            ? 'Your account'
            : title || 'Save homes and get price-drop alerts';
        $('[data-auth-lead]').textContent = me
            ? ''
            : lead ||
              'Create a free account to save homes, compare them, and hear first when prices change.';
        $('[data-auth-lead]').hidden = !!me;
        $('[data-auth-start]').hidden = !!me;
        $('[data-auth-verify]').hidden = true;
        $('[data-auth-me]').hidden = !me;
        showError('');
        if (me) {
            $('[data-auth-hello]').textContent =
                'Signed in as ' +
                (me.name || me.email) +
                '. ' +
                me.favourites.length +
                ' saved ' +
                (me.favourites.length === 1 ? 'home' : 'homes') +
                '.';
            $('[data-auth-saved]').href =
                '/homes/compare?ids=' + me.favourites.slice(0, 4).join(',');
            $('[data-auth-saved]').hidden = !me.favourites.length;
        }
        if (!dlg.open) dlg.showModal();
    }
    if (dlg) {
        $('[data-auth-start]').addEventListener('submit', function (e) {
            e.preventDefault();
            var f = e.target;
            emailForCode = f.email.value;
            nameForCode = f.name.value;
            phone = f.phone.value;
            consent = f.sms_consent.checked;
            api('POST', '/_auth/start', { email: emailForCode })
                .then(function (j) {
                    f.hidden = true;
                    $('[data-auth-verify]').hidden = false;
                    $('[data-auth-lead]').textContent = j.message;
                    $('[data-auth-verify] input').focus();
                    showError('');
                })
                .catch(function (x) {
                    showError(x.message);
                });
        });
        $('[data-auth-verify]').addEventListener('submit', function (e) {
            e.preventDefault();
            api('POST', '/_auth/verify', {
                email: emailForCode,
                code: e.target.code.value,
                vid: vid,
                name: nameForCode,
                phone: phone,
                sms_consent: consent,
            })
                .then(function (j) {
                    me = j;
                    store.set('ra_me', me);
                    dlg.close();
                    paint();
                    if (pending) {
                        var p = pending;
                        pending = null;
                        p();
                    }
                })
                .catch(function (x) {
                    showError(x.message);
                });
        });
        $('[data-auth-out]').addEventListener('click', function () {
            me = null;
            store.del('ra_me');
            dlg.close();
            paint();
        });
    }
    var acct = $('[data-account]');
    if (acct)
        acct.addEventListener('click', function () {
            openAuth();
        });
    if (me)
        api('GET', '/_me')
            .then(function (j) {
                me.favourites = j.favourites;
                me.name = j.name;
                store.set('ra_me', me);
                paint();
            })
            .catch(function (x) {
                if (/401|sign/i.test(x.message)) {
                    me = null;
                    store.del('ra_me');
                    paint();
                }
            });

    // ---- Saved homes and compare ----
    function cmpList() {
        return store.get('ra_compare', []);
    }
    function paint() {
        var favs = me ? me.favourites : [];
        $$('[data-fav]').forEach(function (b) {
            var on = favs.indexOf(b.getAttribute('data-fav')) >= 0;
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
            if (b.classList.contains('btn'))
                b.textContent = on ? 'Saved' : 'Save';
        });
        var c = cmpList();
        $$('[data-cmp]').forEach(function (b) {
            b.setAttribute(
                'aria-pressed',
                c.indexOf(b.getAttribute('data-cmp')) >= 0 ? 'true' : 'false',
            );
        });
        var tray = $('[data-compare-tray]');
        if (tray) {
            tray.hidden =
                c.length < 1 || location.pathname === '/homes/compare';
            $('[data-compare-count]').textContent = c.length + ' of 4 selected';
            $('[data-compare-go]').href = '/homes/compare?ids=' + c.join(',');
        }
        if (acct)
            acct.textContent = me
                ? (me.name || 'Account').split(' ')[0] +
                  (favs.length ? ' · ' + favs.length + ' saved' : '')
                : 'Sign in';
    }
    function toggleFav(u) {
        if (!me) {
            pending = function () {
                toggleFav(u);
            };
            openAuth(
                'Save this home',
                'Create a free account to keep this home and hear first if the price changes.',
            );
            return;
        }
        var on = me.favourites.indexOf(u) < 0;
        me.favourites = on
            ? [u].concat(me.favourites)
            : me.favourites.filter(function (x) {
                  return x !== u;
              });
        paint();
        api('POST', '/_me/favourites', { listing: u, on: on })
            .then(function (j) {
                me.favourites = j.favourites;
                store.set('ra_me', me);
                paint();
            })
            .catch(function () {});
    }
    document.addEventListener('click', function (e) {
        var f = e.target.closest('[data-fav]');
        if (f) {
            e.preventDefault();
            toggleFav(f.getAttribute('data-fav'));
            return;
        }
        var c = e.target.closest('[data-cmp]');
        if (c) {
            e.preventDefault();
            var u = c.getAttribute('data-cmp'),
                list = cmpList(),
                i = list.indexOf(u);
            if (i >= 0) list.splice(i, 1);
            else {
                if (list.length >= 4) list.shift();
                list.push(u);
            }
            store.set('ra_compare', list);
            paint();
            return;
        }
        if (e.target.closest('[data-compare-clear]')) {
            store.set('ra_compare', []);
            paint();
            return;
        }
        if (e.target.closest('[data-save-search]')) {
            e.preventDefault();
            saveSearch();
        }
    });
    function saveSearch() {
        if (!me) {
            pending = saveSearch;
            openAuth(
                'Save this search',
                'Get an email when new homes match or prices drop.',
            );
            return;
        }
        var b = $('[data-save-search]');
        api('POST', '/_me/searches', { query: location.search.slice(1) })
            .then(function (j) {
                if (b) {
                    b.textContent = 'Saved: daily alerts';
                    b.disabled = true;
                }
                offerTexts(j.name);
            })
            .catch(function (x) {
                if (b) b.textContent = x.message;
            });
    }
    // IDX10: an optional text when a new home matches the saved search (the box must be ticked; nothing is pre-ticked).
    function offerTexts(name) {
        var f = $('[data-text-alerts]');
        if (!f || !name) return;
        f.hidden = false;
        f.onsubmit = function (e) {
            e.preventDefault();
            var out = $('[data-text-status]', f);
            if (!f.agree.checked) {
                out.textContent = 'Tick the box to agree to texts.';
                return;
            }
            api('POST', '/_me/searches/text', {
                name: name,
                phone: f.phone.value,
                agree: true,
            })
                .then(function () {
                    out.textContent =
                        'Done. We will text you when a new home matches. Reply STOP any time.';
                    f.querySelector('button').disabled = true;
                })
                .catch(function (x) {
                    out.textContent = x.message;
                });
        };
    }
    if (location.pathname === '/homes/compare') {
        var ids = new URLSearchParams(location.search).get('ids');
        if (ids && ids.split(',').length >= 2)
            send({
                type: 'compare',
                listings: ids.split(','),
                path: '/homes/compare',
            });
    }

    // ---- Sign-up prompt after a few homes (the brokerage sets how many; 0 = never; dismissed once per visit) ----
    var after = parseInt(
        document.body.getAttribute('data-signup-after') || '0',
        10,
    );
    if (
        !me &&
        after > 0 &&
        kind === 'listing' &&
        store.get('ra_views', []).length >= after
    ) {
        var dismissed = false;
        try {
            dismissed = sessionStorage.getItem('ra_prompted') === '1';
        } catch (e) {}
        if (!dismissed)
            setTimeout(function () {
                try {
                    sessionStorage.setItem('ra_prompted', '1');
                } catch (e) {}
                openAuth(
                    'Keep track of the homes you like',
                    'Save homes, compare them side by side, and get price-drop alerts. Free, and no password to remember.',
                );
            }, 2500);
    }
    paint();
})();
