/*
 * Movewise load test (specs/movewise/08). Run with k6 against a staging host that has a copy of the MLS data:
 *
 *   k6 run -e HOST=https://staging.movewisehomes.com -e LISTING=<a listing id> tests/load/movewise.js
 *
 * The pass mark for launch: 1,000 visitors browsing at once for 10 minutes with the 95th percentile under 800 ms
 * and under 1% errors, with the CDN bypassed (the origin alone). Pages are cacheable at the edge, so real traffic
 * mostly never reaches the origin; this checks the worst case.
 */
import { check, sleep } from 'k6';
import http from 'k6/http';

const HOST = __ENV.HOST || 'https://staging.movewisehomes.com';
const LISTING = __ENV.LISTING || '';

export const options = {
    scenarios: {
        browse: {
            executor: 'ramping-vus',
            stages: [
                { duration: '2m', target: 1000 },
                { duration: '10m', target: 1000 },
                { duration: '1m', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<800'],
    },
};

const pages = [
    '/',
    '/homes',
    '/homes?q=Houston',
    '/texas',
    '/texas/houston',
    '/texas/77002',
    '/afford?income=120000&down=40000',
    '/es',
    '/es/texas/houston',
    '/sitemaps/tx.xml',
];

export default function () {
    const path = pages[Math.floor(Math.random() * pages.length)];
    // A cache-busting header so the origin, not the CDN, answers.
    const res = http.get(HOST + path, {
        headers: { 'Cache-Control': 'no-cache', 'X-Load-Test': '1' },
    });
    check(res, { 'status is 200': (r) => r.status === 200 });
    if (LISTING !== '') {
        check(http.get(`${HOST}/homes/${LISTING}`), {
            'listing 200': (r) => r.status === 200,
        });
    }
    sleep(1 + Math.random() * 3);
}
