# RightAlly Platform

The all-in-one, affordable SaaS platform for US real estate brokerages: IDX websites,
CRM, marketing, forms and e-sign, transactions, commissions and revenue share, finance,
compliance and AI, on one Platform Core.

> Status: **Sprint 0 — foundation.** Architecture, plan and specifications live in `/docs`.

## Stack

| Layer     | Technology                                                   |
| --------- | ------------------------------------------------------------ |
| Backend   | Laravel 13, PHP 8.5 (8.3+ supported)                         |
| Front end | React 19, TypeScript, Inertia 3, Tailwind CSS 4              |
| Data      | MySQL 8.4 LTS, Redis                                         |
| Quality   | Pest 4, PHPStan level 8, Pint, Vite+ lint/format, TypeScript |
| Local     | Docker Compose (app, nginx, MySQL, Redis)                    |

## Getting started

```bash
# Option A: Docker
docker compose up -d
docker compose exec app composer setup

# Option B: local PHP 8.3+, Composer, Node 22
composer setup
composer dev
```

## Quality checks (run before every push)

```bash
vendor/bin/pint --test                     # PHP style
vendor/bin/phpstan analyse                 # static analysis, level 8
npm run check && npm run types:check       # front-end lint, format, types
php artisan test --parallel                # Pest test suite
```

## Repository layout

```
app/        Laravel application shell
modules/    one folder per RightAlly module (see modules/README.md)
resources/  React pages and shared components
tests/      Unit, Feature and Architecture tests
docs/       architecture, decisions, sprint plan and specifications
```

See `CONTRIBUTING.md` for the branch, pull request and CI rules.
