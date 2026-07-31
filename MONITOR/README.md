# MONITOR — Executive Monitoring Portal

Read-only dashboards for top management. Same stack and structure as `GOWISER/`
(Laravel 9 API + React 19 + TypeScript + Tailwind), with its own login and its
own database.

## What makes this different from GOWISER

| | GOWISER | MONITOR |
|---|---|---|
| Purpose | Run the business | Watch the business |
| Users | Staff, technicians, agents, customers | Management only |
| Database | One, read/write | Its own (users/roles) + N source DBs, read-only |
| Writes | Everywhere | None, enforced in middleware |

MONITOR never writes to a source database. That is enforced in three places:

1. `EnsureExecutiveAccess` rejects any request method other than GET.
2. `SourceRegistry::connection()` rejects any SQL that is not a read.
3. The source credentials should be a MySQL user with `SELECT` only.

## Layout

```
MONITOR/
├── backend/                    Laravel 9 API
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   ├── AuthController.php            login / logout / me
│   │   │   ├── SettingsColorPaletteController.php
│   │   │   └── Api/MonitorController.php     the dashboard endpoints
│   │   ├── Http/Middleware/
│   │   │   └── EnsureExecutiveAccess.php     auth + read-only guard
│   │   ├── Models/                           User, Role, ColorPalette
│   │   └── Services/
│   │       ├── SourceRegistry.php            source key -> connection
│   │       ├── ExecutiveMetricsService.php   caching + capability gate
│   │       └── Metrics/
│   │           ├── MetricsDriver.php         one interface per schema
│   │           ├── GowiserDriver.php         customers/billing/transactions
│   │           └── NetmanagerDriver.php      subscribers/payments/expenses
│   ├── config/
│   │   ├── database.php        one connection per monitored database
│   │   └── monitor.php         sources, their drivers, cache TTL
│   └── routes/api.php
└── frontend/                   React + TypeScript + Tailwind
    └── src/
        ├── components/common/  MetricCard, Panel, StatusList, PageShell
        ├── hooks/              useTheme, usePalette, useSourcedData
        ├── pages/              Login, Dashboard, Sidebar, Header, Overview,
        │                       Operations, Revenue, Financials, Consolidated
        ├── services/           api, monitorService, themeService
        └── store/              monitorStore (selected source)
```

## Sources and schema drivers

The monitored systems do not share a schema, so each one gets a driver that
knows its tables and declares which sections it can answer. The frontend hides
navigation for anything a source cannot produce, so you never open a page that
then fails.

| Source | Schema | Sections |
|---|---|---|
| GOWISER | `billing_accounts`, `transactions`, `online_status`, `service_orders` | Overview, Operations, Revenue |
| NetManager | `subscribers`, `payments`, `expenses`, `routers`, `plans` | Financials |

GOWISER records no expenses, so it cannot show net profit or margin — hence no
Financials tab. NetManager has no RADIUS session table or service orders, so it
has no Overview or Operations. Neither gap is papered over with zeros.

## Financials

Ported from NetManager's own dashboard so the figures agree with what branch
staff already see:

- **KPIs** — income, expenses, net surplus/deficit, margin %, active subscribers
- **Office vs portal** — over-the-counter against online-portal collections
- **Income · Expenses · Net** — income and expenses as bars, net as a line
- **Breakdowns** — by expense type, payment method, charge type, and plan mix
- **Branch performance** — every branch side by side, ignoring the branch filter

Filters: period (day / week / month / year), branch, and an as-of date for
looking back at a closed month.

### The expense period-type rule

Expenses carry a `period_type` saying which reporting horizon they belong to.
A daily or weekly view counts only `daily` entries; monthly adds `monthly`;
yearly adds `yearly`. Skipping this is not a rounding difference — on
2026-05-01 the daily view is ₱12,963.93, but counting every row on that date
gives ₱3,852,204.77, because a month's fixed costs are booked on the first.

## Setup

### 1. Create MONITOR's own database

```sql
CREATE DATABASE monitor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Create a read-only user on each source database

```sql
CREATE USER 'monitor_ro'@'%' IDENTIFIED BY 'strong-password-here';
GRANT SELECT ON gowiser.* TO 'monitor_ro'@'%';
FLUSH PRIVILEGES;
```

Do not reuse the GOWISER application user. A `SELECT`-only grant is what makes
the read-only guarantee real rather than aspirational.

### 3. Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
# fill in DB_*, DB_GOWISER_*, CORS_ALLOWED_ORIGINS, SEED_ADMIN_PASSWORD
php artisan migrate --seed
php artisan serve --port=8000
```

`db:seed` creates the Executive and Viewer roles, the default palette, and one
admin user from `SEED_ADMIN_USERNAME` / `SEED_ADMIN_PASSWORD`. It skips the user
if no password is set, so nothing ships with a default credential.

### 4. Frontend

```bash
cd frontend
npm install
cp .env.example .env
npm start
```

### 5. Or both at once, from this directory

```bash
npm install
npm run install:all
npm start
```

## Adding another database to monitor

Three edits, no code — if it reuses a schema you already have a driver for:

1. `backend/config/database.php` — copy a connection block, rename it.
2. `backend/.env` — add the matching `DB_<NAME>_*` credentials.
3. `backend/config/monitor.php` — add the entry under `sources`, pointing
   `driver` at an existing driver.

A genuinely new schema needs a fourth step: a class implementing
`App\Services\Metrics\MetricsDriver`, registered under `monitor.drivers`.

The frontend picks it up automatically: the source switcher is built from
`GET /api/monitor/sources`, and the All Companies page rolls it into the group
totals.

## API

| Method | Endpoint | Notes |
|---|---|---|
| POST | `/api/login` | Rate limited, 5 attempts per identifier+IP |
| POST | `/api/logout` | |
| GET | `/api/me` | Confirms the session is still live |
| GET | `/api/monitor/sources` | Sources, their capabilities, and the default |
| GET | `/api/monitor/overview?source=` | Headline KPIs |
| GET | `/api/monitor/operations?source=` | Field and support activity |
| GET | `/api/monitor/revenue?source=&months=12` | Collections trend |
| GET | `/api/monitor/branches?source=` | Selectable branches for a source |
| GET | `/api/monitor/financials?source=&period=&branch=&date=` | Income, expenses, net + breakdowns |
| GET | `/api/monitor/consolidated` | Every source side by side |

Asking a source for a section it does not support returns 422 with a plain
explanation, not a SQL error about a missing table.

## Roles

Access is per dashboard section, driven by the `permissions` JSON column on
`roles`. Valid ids: `overview`, `operations`, `revenue`, `financials`,
`consolidated`.

A section appears only when the role permits it *and* the selected source can
produce it. The seeded Viewer role deliberately omits `financials`, so profit
and loss is not visible to everyone with a login.

An empty permission list shows nothing. Failing closed is deliberate — a new
role should not gain access to the revenue figures by accident.

## Notes on the numbers

- Revenue counts payments with a recorded `payment_date`, excluding cancelled,
  voided and pending rows. It will not match a finance report prepared on a
  different recognition basis.
- The current month is always partial. The Revenue page compares the last two
  *complete* months for its month-on-month figure.
- Rollups are cached server-side (`MONITOR_CACHE_TTL`, default 60s) and briefly
  client-side. The refresh button bypasses both.
