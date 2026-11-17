/*
 * RightAlly brokerage app load test (PS2, specs/core/14). Run with k6 against STAGING only, after filling a
 * load-test brokerage:
 *
 *   php artisan perf:seed loadtest-big --agents=200 --contacts=50000 --deals=5000 --password='<a long password>'
 *   k6 run -e APP=https://loadtest-big.rightally.app -e SITE=https://loadtest-big.rightally.site \
 *          -e PASSWORD='<the same password>' -e AGENTS=200 tests/load/app.js
 *
 * Three groups run together for 15 minutes:
 *   - agents:  signed-in agents moving between their busiest pages, with think time (500 at once)
 *   - website: visitors browsing the brokerage website (1,000 at once)
 *   - leads:   website enquiries arriving at 5 a second
 *
 * Pass marks (origin only, CDN bypassed): 95th percentile under 800 ms for pages and under 1,500 ms for agent pages
 * that build reports, under 1% errors. The full 100,000-user test runs at launch (Sprint 59) with the same script
 * and more load generators.
 */
import { check, group, sleep } from 'k6';
import http from 'k6/http';

const APP = __ENV.APP || 'https://loadtest-big.rightally.app';
const SITE = __ENV.SITE || 'https://loadtest-big.rightally.site';
const PASSWORD = __ENV.PASSWORD || '';
const AGENTS = parseInt(__ENV.AGENTS || '200', 10);

export const options = {
    scenarios: {
        agents: {
            executor: 'ramping-vus',
            exec: 'agents',
            stages: [
                { duration: '3m', target: 500 },
                { duration: '15m', target: 500 },
                { duration: '1m', target: 0 },
            ],
        },
        website: {
            executor: 'ramping-vus',
            exec: 'website',
            stages: [
                { duration: '3m', target: 1000 },
                { duration: '15m', target: 1000 },
                { duration: '1m', target: 0 },
            ],
        },
        leads: {
            executor: 'constant-arrival-rate',
            exec: 'leads',
            rate: 5,
            timeUnit: '1s',
            duration: '18m',
            preAllocatedVUs: 50,
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{kind:page}': ['p(95)<800'],
        'http_req_duration{kind:report}': ['p(95)<1500'],
    },
};

const AGENT_PAGES = [
    ['/dashboard', 'report'],
    ['/deals', 'page'],
    ['/contacts', 'page'],
    ['/leads', 'page'],
    ['/tasks', 'page'],
    ['/inbox', 'page'],
    ['/listings', 'page'],
    ['/network', 'page'],
];

const SITE_PAGES = [
    '/',
    '/homes',
    '/homes?q=Austin',
    '/home-value',
    '/accessibility',
    '/sitemap.xml',
];

/** Signs in once per virtual user (the login form, with the XSRF-TOKEN cookie sent back as a header). */
function signIn(n) {
    const jar = http.cookieJar();
    http.get(`${APP}/login`, { tags: { kind: 'page' } });
    const xsrf = decodeURIComponent(
        (jar.cookiesForURL(`${APP}/login`)['XSRF-TOKEN'] || [''])[0],
    );
    const res = http.post(
        `${APP}/login`,
        JSON.stringify({
            email: `agent${n}@loadtest.invalid`,
            password: PASSWORD,
        }),
        {
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            tags: { kind: 'page' },
            redirects: 0,
        },
    );
    check(res, { 'signed in': (r) => [200, 204, 302, 303].includes(r.status) });

    return jar;
}

let signedIn = false;

export function agents() {
    if (!signedIn) {
        signIn(((__VU - 1) % AGENTS) + 1);
        signedIn = true;
    }
    group('agent', () => {
        for (const [path, kind] of AGENT_PAGES) {
            const r = http.get(`${APP}${path}`, {
                headers: { 'X-Inertia': 'true', 'X-Inertia-Version': '' },
                tags: { kind },
            });
            check(r, {
                'page ok': (x) => x.status === 200 || x.status === 409,
            });
            sleep(2 + Math.random() * 4);
        }
    });
}

export function website() {
    for (const path of SITE_PAGES) {
        const r = http.get(`${SITE}${path}`, { tags: { kind: 'page' } });
        check(r, { 'site ok': (x) => x.status === 200 });
        sleep(1 + Math.random() * 3);
    }
}

export function leads() {
    const home = http.get(`${SITE}/`, { tags: { kind: 'page' } });
    const t = (home.body.match(/name="t" value="([^"]+)"/) || [])[1] || '';
    sleep(3.5); // a person takes a few seconds to fill the form (faster is treated as a bot)
    const n = Math.floor(Math.random() * 1e9);
    const r = http.post(
        `${SITE}/_lead`,
        {
            name: `Load Test ${n}`,
            email: `lead${n}@loadtest.invalid`,
            phone: '',
            message: 'Load test enquiry',
            t,
            return: '/',
        },
        { tags: { kind: 'page' }, redirects: 0 },
    );
    check(r, { 'lead accepted': (x) => x.status === 303 });
}
