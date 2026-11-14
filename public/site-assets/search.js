/*
 * RightAlly website search (specs/websites/06). The page works without this script (server-rendered results,
 * plain form and links); this adds instant filtering, the Mapbox map with clustered price pins, "search as I move
 * the map" and drawing an area. Listing data from the server is inserted as Blade-escaped HTML or as textContent.
 */
(function () {
    'use strict';
    var root = document.querySelector('[data-search]');
    if (!root) return;
    var form = root.querySelector('[data-filters]');
    var cards = root.querySelector('[data-cards]');
    var heading = root.querySelector('[data-heading]');
    var pager = root.querySelector('[data-pagination]');
    var clearBtn = root.querySelector('[data-clear-area]');
    var params = new URLSearchParams(location.search);
    var map = null,
        drawing = null,
        controller = null,
        lastPins = null;

    function money(v) {
        if (v == null) return '';
        return v >= 1e6
            ? '$' + Math.round(v / 1e5) / 10 + 'M'
            : '$' + Math.round(v / 1e3) + 'k';
    }

    function setParam(k, v) {
        if (v === null || v === undefined || v === '') params.delete(k);
        else params.set(k, v);
    }

    function syncHidden(name) {
        var input = form.querySelector(
            'input[type=hidden][name="' + name + '"]',
        );
        var v = params.get(name);
        if (v && !input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.appendChild(input);
        }
        if (input) {
            if (v) input.value = v;
            else input.remove();
        }
    }

    function load(push, withPins) {
        if (controller) controller.abort();
        controller = new AbortController();
        params.delete('pins');
        var qs = params.toString();
        if (push) history.pushState(null, '', '/homes' + (qs ? '?' + qs : ''));
        ['bounds', 'poly'].forEach(syncHidden);
        if (clearBtn)
            clearBtn.hidden = !(params.get('bounds') || params.get('poly'));
        root.classList.add('is-loading');
        return fetch(
            '/_search?' +
                qs +
                (withPins && map ? (qs ? '&' : '') + 'pins=1' : ''),
            {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        )
            .then(function (r) {
                return r.ok ? r.json() : Promise.reject(r);
            })
            .then(function (data) {
                heading.textContent = data.heading;
                cards.innerHTML = data.cards; // server-rendered, escaped by Blade
                pager.innerHTML = data.pagination;
                var empty = root.querySelector('[data-empty]');
                if (empty) empty.hidden = data.total > 0;
                if (data.pins && map) showPins(data.pins);
            })
            .catch(function (e) {
                if (e && e.name === 'AbortError') return;
                location.href = '/homes' + (qs ? '?' + qs : '');
            })
            .finally(function () {
                root.classList.remove('is-loading');
            });
    }

    // Filters: instant results, every search is a shareable link.
    form.addEventListener('change', function (e) {
        if (!e.target.name || e.target.name === 'q') return;
        setParam(e.target.name, e.target.value);
        params.delete('page');
        load(true, true);
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        new FormData(form).forEach(function (v, k) {
            setParam(k, v);
        });
        params.delete('page');
        if (form.q.value !== '') {
            params.delete('bounds');
            params.delete('poly');
        }
        load(true, true).then(function () {
            if (map && params.get('q')) fitToPins();
        });
    });
    pager.addEventListener('click', function (e) {
        var a = e.target.closest('a');
        if (!a) return;
        e.preventDefault();
        params = new URL(a.href).searchParams;
        load(true, false).then(function () {
            root.querySelector('.idx-list').scrollIntoView({
                behavior: 'smooth',
            });
        });
    });
    window.addEventListener('popstate', function () {
        params = new URLSearchParams(location.search);
        load(false, true);
    });

    // Map.
    var token = root.getAttribute('data-mapbox');
    var mapEl = root.querySelector('[data-map]');
    if (!token || !mapEl || !window.mapboxgl) return;
    var dark = document.documentElement.classList.contains('theme-aurum');
    var brand =
        getComputedStyle(document.documentElement)
            .getPropertyValue('--accent')
            .trim() || '#1d4ed8';
    mapboxgl.accessToken = token;
    map = new mapboxgl.Map({
        container: mapEl,
        style: dark
            ? 'mapbox://styles/mapbox/dark-v11'
            : 'mapbox://styles/mapbox/light-v11',
        center: [-97.74, 30.27],
        zoom: 10,
        cooperativeGestures: true,
    });
    map.addControl(
        new mapboxgl.NavigationControl({ showCompass: false }),
        'top-right',
    );
    var b = (root.getAttribute('data-bounds') || '').split(',').map(Number);
    if (b.length === 4 && b.every(isFinite))
        map.fitBounds(
            [
                [b[1], b[0]],
                [b[3], b[2]],
            ],
            { animate: false },
        );

    function geo(pins) {
        return {
            type: 'FeatureCollection',
            features: pins.map(function (p) {
                return {
                    type: 'Feature',
                    geometry: { type: 'Point', coordinates: [p[2], p[1]] },
                    properties: { u: p[0], label: money(p[3]), beds: p[4] },
                };
            }),
        };
    }
    function showPins(pins) {
        lastPins = pins;
        var s = map.getSource('homes');
        if (s) s.setData(geo(pins));
    }
    function fitToPins() {
        if (!lastPins || !lastPins.length) return;
        var bb = new mapboxgl.LngLatBounds();
        lastPins.forEach(function (p) {
            bb.extend([p[2], p[1]]);
        });
        map.fitBounds(bb, { padding: 48, maxZoom: 14, duration: 0 });
    }

    map.on('load', function () {
        map.addSource('homes', {
            type: 'geojson',
            data: geo([]),
            cluster: true,
            clusterRadius: 48,
            clusterMaxZoom: 14,
        });
        map.addLayer({
            id: 'clusters',
            type: 'circle',
            source: 'homes',
            filter: ['has', 'point_count'],
            paint: {
                'circle-color': brand,
                'circle-opacity': 0.9,
                'circle-radius': [
                    'step',
                    ['get', 'point_count'],
                    16,
                    20,
                    22,
                    100,
                    28,
                ],
                'circle-stroke-width': 2,
                'circle-stroke-color': '#fff',
            },
        });
        map.addLayer({
            id: 'cluster-count',
            type: 'symbol',
            source: 'homes',
            filter: ['has', 'point_count'],
            layout: {
                'text-field': ['get', 'point_count_abbreviated'],
                'text-size': 12,
                'text-font': ['DIN Pro Medium', 'Arial Unicode MS Bold'],
            },
            paint: { 'text-color': '#fff' },
        });
        map.addLayer({
            id: 'pins',
            type: 'symbol',
            source: 'homes',
            filter: ['!', ['has', 'point_count']],
            layout: {
                'text-field': ['get', 'label'],
                'text-size': 12,
                'text-font': ['DIN Pro Bold', 'Arial Unicode MS Bold'],
                'text-allow-overlap': true,
                'icon-allow-overlap': true,
            },
            paint: {
                'text-color': dark ? '#0b0b0d' : '#fff',
                'text-halo-color': brand,
                'text-halo-width': 6,
            },
        });
        map.addLayer({
            id: 'pin-hover',
            type: 'symbol',
            source: 'homes',
            filter: ['==', ['get', 'u'], ''],
            layout: {
                'text-field': ['get', 'label'],
                'text-size': 14,
                'text-font': ['DIN Pro Bold', 'Arial Unicode MS Bold'],
                'text-allow-overlap': true,
            },
            paint: {
                'text-color': '#fff',
                'text-halo-color': '#111',
                'text-halo-width': 7,
            },
        });
        map.addSource('area', {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] },
        });
        map.addLayer({
            id: 'area-fill',
            type: 'fill',
            source: 'area',
            paint: { 'fill-color': brand, 'fill-opacity': 0.12 },
        });
        map.addLayer({
            id: 'area-line',
            type: 'line',
            source: 'area',
            paint: { 'line-color': brand, 'line-width': 2.5 },
        });
        drawArea(params.get('poly'));
        load(false, true).then(function () {
            if (!(b.length === 4 && b.every(isFinite)) && !params.get('poly'))
                fitToPins();
        });
    });

    map.on('click', 'clusters', function (e) {
        var f = e.features[0];
        map.getSource('homes').getClusterExpansionZoom(
            f.properties.cluster_id,
            function (err, zoom) {
                if (!err)
                    map.easeTo({ center: f.geometry.coordinates, zoom: zoom });
            },
        );
    });
    map.on('click', 'pins', function (e) {
        var u = e.features[0].properties.u;
        var card = cards.querySelector('[data-ulid="' + CSS.escape(u) + '"] a');
        if (card) {
            card.closest('.card').scrollIntoView({
                behavior: 'smooth',
                block: 'center',
            });
            card.closest('.card').classList.add('is-hl');
            setTimeout(function () {
                card.closest('.card').classList.remove('is-hl');
            }, 1600);
        } else location.href = '/homes/' + encodeURIComponent(u);
    });
    ['clusters', 'pins'].forEach(function (l) {
        map.on('mouseenter', l, function () {
            map.getCanvas().style.cursor = 'pointer';
        });
        map.on('mouseleave', l, function () {
            map.getCanvas().style.cursor = '';
        });
    });
    cards.addEventListener('mouseover', function (e) {
        var c = e.target.closest('[data-ulid]');
        if (c && map.getLayer('pin-hover'))
            map.setFilter('pin-hover', [
                '==',
                ['get', 'u'],
                c.getAttribute('data-ulid'),
            ]);
    });

    // Search as I move the map.
    var follow = root.querySelector('[data-follow]');
    map.on('moveend', function (e) {
        if (
            !e.originalEvent ||
            drawing ||
            !follow ||
            !follow.checked ||
            params.get('poly')
        )
            return;
        var bb = map.getBounds();
        setParam(
            'bounds',
            [bb.getSouth(), bb.getWest(), bb.getNorth(), bb.getEast()]
                .map(function (n) {
                    return n.toFixed(5);
                })
                .join(','),
        );
        params.delete('page');
        load(true, true);
    });

    // Draw an area: click points, click the first point (or double-click) to finish, Esc to cancel.
    var drawBtn = root.querySelector('[data-draw]');
    function drawArea(poly, open) {
        var src = map.getSource('area');
        if (!src) return;
        var pts =
            typeof poly === 'string' && poly
                ? poly.split(';').map(function (p) {
                      var a = p.split(',').map(Number);
                      return [a[1], a[0]];
                  })
                : poly || [];
        var features = [];
        if (pts.length >= 2)
            features.push({
                type: 'Feature',
                geometry: open
                    ? { type: 'LineString', coordinates: pts }
                    : { type: 'Polygon', coordinates: [pts.concat([pts[0]])] },
            });
        src.setData({ type: 'FeatureCollection', features: features });
    }
    function finishDraw() {
        var pts = drawing;
        drawing = null;
        map.doubleClickZoom.enable();
        map.getCanvas().style.cursor = '';
        drawBtn.textContent = 'Draw an area';
        if (!pts || pts.length < 3) {
            drawArea(params.get('poly'));
            return;
        }
        setParam(
            'poly',
            pts
                .map(function (p) {
                    return p[1].toFixed(5) + ',' + p[0].toFixed(5);
                })
                .join(';'),
        );
        params.delete('bounds');
        params.delete('page');
        drawArea(params.get('poly'));
        load(true, true);
    }
    drawBtn.addEventListener('click', function () {
        if (drawing) {
            finishDraw();
            return;
        }
        drawing = [];
        map.doubleClickZoom.disable();
        map.getCanvas().style.cursor = 'crosshair';
        drawBtn.textContent = 'Finish area';
        root.classList.add('show-map');
    });
    map.on('click', function (e) {
        if (!drawing) return;
        var p = [e.lngLat.lng, e.lngLat.lat];
        if (drawing.length >= 3) {
            var first = map.project(drawing[0]),
                here = map.project(p);
            if (Math.hypot(first.x - here.x, first.y - here.y) < 14) {
                finishDraw();
                return;
            }
        }
        if (drawing.length < 60) drawing.push(p);
        drawArea(drawing, true);
    });
    map.on('dblclick', function () {
        if (drawing) finishDraw();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && drawing) {
            drawing = [];
            finishDraw();
        }
    });
    if (clearBtn)
        clearBtn.addEventListener('click', function () {
            params.delete('poly');
            params.delete('bounds');
            params.delete('page');
            drawArea(null);
            load(true, true);
        });

    // Phones: list or map.
    var toggle = root.querySelector('[data-map-toggle]');
    if (toggle)
        toggle.addEventListener('click', function () {
            var on = root.classList.toggle('show-map');
            toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
            toggle.textContent = on ? 'List' : 'Map';
            if (on)
                setTimeout(function () {
                    map.resize();
                }, 50);
        });
})();
