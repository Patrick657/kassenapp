# Festkasse — PHP 8.5 / MySQL Implementation

Production implementation of the Kassen-App design (`design/project/design_handoff_festkasse/`).
Vanilla PHP backend + JSON API, vanilla JS SPA frontend — no build step, no framework.

## Requirements

- PHP 8.1+ (target 8.5; developed/tested here against 8.4 since 8.5 wasn't available in this
  sandbox — nothing in the code uses 8.5-only syntax, so it should run unmodified once you're on
  a real 8.5 host. Re-run the test suite/smoke test below after deploying to confirm.)
- MySQL 8 or MariaDB 10.11+, PDO MySQL extension
- Apache with `mod_rewrite` (or any server that can route unknown paths to `index.php`)

## Directory layout

```
public/     Document root — point your vhost here. Everything else stays outside the webroot.
src/        PHP classes (Db, Auth, Api, Repo/*)
config/     config.php (reads .env)
migrations/ 001_schema.sql (required), 002_seed_demo.sql (optional demo articles)
bin/        setup.php (CLI setup)
```

## First deployment

1. Upload the whole `app/` directory to the server, but point the vhost's document root at
   `app/public` — `src/`, `config/`, `migrations/` must **not** be web-reachable.
2. Copy `.env.example` to `.env` next to `config/config.php` (i.e. `app/.env`) and fill in your
   real DB credentials. Set `APP_HTTPS=1` once you're serving over HTTPS (this makes the access
   cookie `Secure`) — do this before going live, not after.
3. Create the database and run the schema:
   ```
   mysql -u youruser -p yourdb < migrations/001_schema.sql
   ```
4. Set the access code and delete code (never hardcoded, never stored in the frontend):
   ```
   php bin/setup.php --access-code=1234 --delete-code=9999
   ```
   Pick your own codes for production — these are just the prototype's demo values. Access code
   and delete code **must** differ (the script refuses otherwise). Add `--demo` on first setup if
   you want the same 15 demo articles as the prototype to start from; omit it if you're entering
   your own articles from scratch (the Artikel tab handles that once the app is up).
5. Enforce HTTPS at the webserver level (the access cookie is `HttpOnly`/`SameSite=Strict`, but
   only gets `Secure` when `APP_HTTPS=1`).

Changing the codes later (e.g. before your next event) — same command, run again:
```
php bin/setup.php --access-code=<new> --delete-code=<new>
```

## Local development (what this session used)

```
php -S 127.0.0.1:8000 -t public public/router.php
```
The `-t public` is required — without it PHP's built-in server resolves static files against the
*current directory*, not the router script's directory, and every request for `/app.js` or
`/styles.css` 404s even though `is_file()` finds them inside the router. Apache doesn't have this
quirk since the vhost's DocumentRoot already points at `public/`.

For a local MySQL instance during development, `apt install mariadb-server`, then:
```sql
CREATE DATABASE kassen_app CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER 'kassen'@'localhost' IDENTIFIED BY 'yourpassword';
GRANT ALL PRIVILEGES ON kassen_app.* TO 'kassen'@'localhost';
```

## What differs from the Claude Design prototype (and why)

- **Storage**: MySQL instead of `localStorage`; every mutation goes through `/api/*` with prepared
  statements, transactions around checkout/Z-report, and cent-integer money everywhere (no floats).
- **Auth**: the access code is `password_hash()`'d server-side and never shipped to the browser;
  a random 32-byte session token is issued as an `HttpOnly` cookie on success (2 h TTL, fixed
  window, no sliding renewal — matches the design spec). The delete code is verified fresh on
  every destructive call, never cached.
- **Public vs. admin product data**: `/api/bootstrap` (used by the POS, no auth) only returns
  `name/category/priceCents/stock/stockMin` — no cost price or margin. `/api/admin/products`
  (auth required) adds `costCents`/`soldQty` for the Artikel/Bestand tabs. The prototype didn't
  need this split since everything lived in one unauthenticated client-side object; a real
  multi-terminal deployment shouldn't expose margins to whoever's standing at the register.
- **Reports are computed in SQL** (`ReportsRepo`), not recomputed from a full sales array on every
  render, per the handoff README's own recommendation ("produktiv besser in SQL").
- **"Umsatz gesamt" subtitle**: the prototype hardcodes "· 7 Tage" here because its seed data only
  ever spans a week. In this implementation it's real all-time revenue, so that fixed "7 Tage"
  label was dropped — it would be actively misleading after the app has run for a season.
- **Timestamps**: the API returns full ISO-8601 with an explicit UTC offset (Europe/Berlin, DST
  aware — computed fresh per request rather than hardcoded, since a fixed `+01:00` would be wrong
  for roughly half the year). The daily/hourly reports rely on MySQL's session `time_zone` matching
  PHP's `Europe/Berlin`, which `Db.php` sets dynamically for the same DST reason.
- **PIN/delete-code changing**: there's no in-app UI for this (the design never specified one) but
  `bin/setup.php` and `PATCH /api/settings/codes` (auth-protected) both support it — use whichever
  fits your ops workflow before each event.
- Everything else — layout, colors, spacing, copy, the discount/tender/change-breakdown logic, the
  Z-report/close semantics, the two-factor delete flow — mirrors `Kassen-App.dc.html` and
  `design_handoff_festkasse/README.md` as closely as the stack change allows.

## Smoke-testing after deploy

```
curl https://yourdomain/api/bootstrap                     # should return products + settings
curl -X POST https://yourdomain/api/access -d '{"code":"<your code>"}' -H 'Content-Type: application/json' -i
```
The second call should set a `festkasse_token` cookie and return `expiresAt` ~2 hours out. If it
doesn't, check `.env` DB credentials and that `migrations/001_schema.sql` ran.
