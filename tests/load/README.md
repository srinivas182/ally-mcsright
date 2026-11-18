# Load tests

Run these with [k6](https://k6.io) against **staging**, never production.

| Script        | What it checks                                                                 | Pass mark                                   |
| ------------- | ------------------------------------------------------------------------------ | ------------------------------------------- |
| `app.js`      | 500 signed-in agents, 1,000 website visitors and 5 enquiries a second together | p95 < 800 ms (reports < 1.5 s), errors < 1% |
| `movewise.js` | 1,000 Movewise visitors browsing at once                                       | p95 < 800 ms, errors < 1%                   |

## Before running `app.js`

1. Make a brokerage on staging whose address starts with `loadtest` (for example `loadtest-big`) and publish its
   website.
2. Fill it with made-up data (every email ends in `.invalid`, so nothing can be delivered):

    ```
    php artisan perf:seed loadtest-big --agents=200 --contacts=50000 --deals=5000 --password='<a long password>'
    ```

3. Run k6 from a machine near staging, with the CDN bypassed so the origin is measured:

    ```
    k6 run -e APP=https://loadtest-big.rightally.app -e SITE=https://loadtest-big.rightally.site \
           -e PASSWORD='<the same password>' -e AGENTS=200 tests/load/app.js
    ```

The website enquiry form allows 10 enquiries a minute from one address, so most of the `leads` group is answered
by the limiter (still a 303); spread it over several load generators to push real enquiries through.

## The 100,000-user test

Moved to launch (Sprint 59): the same `app.js`, scaled out over many k6 machines (k6 cloud or a fleet), against a
production-sized staging with several cells. Watch HQ › Cells during the run.
