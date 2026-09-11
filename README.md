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
migrations/ 001_schema.sql (baseline, required), 002_seed_demo.sql (optional demo articles),
            further numbered files as the schema evolves — see "Schema migrations" below
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
4. Set the access code and delete code (never hardcoded, never stored in the frontend). Two ways —
   pick whichever you have:
   - **With SSH**: `php bin/setup.php --access-code=1234 --delete-code=9999` (access code and
     delete code must differ, or the script refuses).
   - **Without SSH** (e.g. shared hosting with only a web file manager, no terminal): open the app
     in the browser, go to **Verwaltung → Einstellungen**. On a fresh install with no code set yet,
     Verwaltung is reachable with no PIN prompt — set both codes there once, under "Zugangscode &
     Löschkennwort". As soon as a code exists, that bootstrap bypass closes automatically and every
     further visit to Verwaltung requires the PIN like normal. **Do this immediately after your
     first deploy** — until you set a code, Verwaltung is open to anyone who finds the URL.
   Add `--demo` to the CLI form on first setup if you want the same 15 demo articles as the
   prototype to start from; omit it if you're entering your own articles from scratch.
5. Enforce HTTPS at the webserver level (the access cookie is `HttpOnly`/`SameSite=Strict`, but
   only gets `Secure` when `APP_HTTPS=1`).

Changing the codes later (e.g. before your next event): same CLI command again, or Einstellungen →
"Zugangscode & Löschkennwort" (this time it requires being unlocked first, as normal).

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

## Schema migrations

Every schema change after the baseline (`001_schema.sql`) gets its own private method in
`src/Migrator.php`, dispatched from `ensureUpToDate()` based on a `schema_version` row in
`settings`. You don't need to run these by hand:

- **With SSH**: `php bin/setup.php` (with or without the code flags) re-applies the baseline schema
  and then calls `Migrator::ensureUpToDate()`. Safe to run repeatedly.
- **Without SSH**: no action needed. `public/index.php` calls the same `Migrator::ensureUpToDate()`
  on every API request and applies whatever's missing before handling it — so uploading new files
  through a web file manager and then just loading the page is the entire deployment step,
  migrations included.

**Why each step checks `information_schema` instead of using `ADD COLUMN IF NOT EXISTS`**: that
syntax needs MySQL 8.0.29+ and is a hard syntax error on older MySQL — exactly the kind of thing
shared hosting still runs. A migration failing is also never allowed to take the rest of the site
down: the call site wraps `ensureUpToDate()` in a try/catch that logs and continues, so a DB user
without `ALTER` rights (say) degrades the affected feature instead of 500ing every request. Add a
new migration by bumping `Migrator::LATEST_VERSION`, writing a `migrateToVN()` method that only
uses portable, idempotent checks, and calling it from `ensureUpToDate()`.

## Forgotten access code: email recovery

Once a code is set, the only ways back in without it are `bin/setup.php` (needs SSH) or clearing
`access_code_hash` in `settings` directly (needs phpMyAdmin or similar). For a deployment with
neither, that's a real lockout risk, so there's now a self-service path:

- **Einstellungen → Zugangscode & Löschkennwort** has an optional "Wiederherstellungs-E-Mail"
  field (`settings.recovery_email`, migration v4). Worth filling in the first time you set a code.
- The PIN dialog has a **"Code vergessen?"** link → `POST /api/access/forgot` (public, same rate
  limit as login attempts — 5/15min/IP) emails a one-time link to that address, valid 30 minutes,
  tracked in `access_resets` (migration v4). The response is the same whether or not an email is
  configured, deliberately — it doesn't leak that state to an unauthenticated caller.
- Opening `/?reset=<token>` shows a form to set a new access code (and optionally a new delete
  code); `POST /api/access/reset` validates the token, applies the new code(s), marks the token
  used, and logs the admin straight in — same as the first-time-setup flow.
- **Sent via PHP's `mail()`** — no external service, no API key, works immediately on hosts that
  have a local MTA configured (most shared hosting does), but deliverability isn't guaranteed
  (can land in spam, or silently fail on hosts with no MTA at all — a failed send is logged
  server-side but never surfaced to the caller). If that turns out to be unreliable in practice,
  swapping `Auth::sendResetEmail()` for a transactional email API (Resend, Mailgun, ...) is a
  contained change — everything else in the flow (token, expiry, rate limit) stays the same.
- Set **`APP_URL`** in `.env` (e.g. `https://pos.example.com`) so the emailed link's domain comes
  from your own config rather than the request's `Host` header — a defensive measure against a
  misconfigured server letting someone spoof `Host` and get a phishing link mailed to your own
  recovery address. Optional; falls back to `Host` if unset.

## Artikelgruppen & Lagerbestand pro Artikel

Added after the initial handoff, at the operator's request: article groups became a managed
entity instead of free-text, and stock tracking moved from a single global on/off to a per-article
setting (a festival typically has items like draft beer that are never counted alongside items
like a limited print run of 100 T-shirts that must be).

- **Artikelgruppen tab** manages groups: create/rename/delete, name + article count per group, and
  a per-group default for whether new articles in it track stock. Deleting a group is blocked while
  any article still references it (reassign or delete those first — `CategoryRepo::delete()`). The
  per-group *revenue* analysis (previously mixed into this same tab) now lives in its own
  **Auswertungen** tab — structural management and sales analysis were two different jobs sharing
  one screen, split apart at the operator's request.
- **Article form**: category is now a `<select>` populated from `GET /api/categories`, not free
  text — `POST/PATCH /api/products` reject any category that isn't a real group
  (`Api::resolveCategory()`). A per-article "Lagerbestand für diesen Artikel führen" toggle appears
  next to it (only when the global Einstellungen toggle is on); picking a group prefills it from
  that group's default, then it's freely overridable per article.
- **Everywhere stock-related** (POS badges/blocking, Bestand tab, Artikel tab's Bestand column,
  checkout's stock deduction, the KPI/low-stock/Lagerwert aggregates) now checks both the global
  `settings.track_stock` flag *and* the individual `products.track_stock` flag — both must be on
  for an article to be treated as tracked. An untracked article behaves as it always could be sold
  without limit, no badge, excluded from Bestand and stock-value figures, same as when the global
  toggle used to be the only switch.

## Mehrere Benutzer / Kassenrollen

Added at the operator's request: a festival typically runs more than one register from one shared
article catalog (e.g. a merch stand and a food/drinks stand), and each station should only see the
articles relevant to it. This is a **separate, independent layer** from the Zugangscode/
Löschkennwort pair that guards Verwaltung — that system is unchanged; this one only controls what a
register's POS screen shows and may sell.

- **`users` table** (migration v5): `name`, a 4-digit `pin_hash`, `role` (`admin` or `cashier`),
  `active`. **`user_categories`** maps a `cashier`-role user to the article groups they may sell —
  an `admin`-role user always sees and books everything, ignoring any group assignment. **`pos_sessions`**
  holds the long-lived per-device login (see below).
- **Bootstrap-open, same pattern as the access code**: as long as zero users exist, the POS behaves
  exactly as before — open, unfiltered, no login screen — so this feature is entirely opt-in and
  doesn't affect anyone who hasn't set up any users. It closes automatically the moment the first
  user is created in Verwaltung → **Benutzer**.
- **"Wer arbeitet an dieser Kasse?"**: once at least one user exists, a device without an active POS
  login sees a name-tile picker instead of the article grid; picking a name prompts a 4-digit PIN
  (same pad as the admin unlock). On success the device stays logged in as that user **until
  someone taps "Wechseln"** — deliberately no timeout, since the intent is "this iPad is the merch
  stand for the whole day," not a per-transaction login.
- **Enforced server-side, not just hidden in the UI**: `GET /api/bootstrap` filters the `products`
  list by the current device's allowed groups, and `POST /api/sales` independently re-checks every
  cart line's article group against the same list before booking the sale — a cashier device can't
  be tricked into selling an out-of-scope article via a direct API call, only via the UI staying in
  sync with what it's shown.
- **Verwaltung → Benutzer** manages accounts: create/edit name, role, PIN, and (for `cashier`) which
  article groups they see; deactivating or deleting a user only affects future POS logins, not
  historical sales (sales aren't attributed to a user — this controls visibility, not accounting).
  Deleting a user requires the Löschkennwort, same as deleting an article.
- Rate-limited through the same shared bucket as the access/delete codes and the reset-link request
  (`Auth::checkRateLimit()`/`recordAttempt()`) — a 4-digit PIN is guessable, and it's one more
  PIN-guessing surface on the same IP-based throttle.

## Artikelnummer (SKU) und CSV Export/Import

Optional `sku` field per article (migration v3, `products.sku VARCHAR(60) NULL`, no uniqueness
constraint) — shown as a small subtitle under the article name in the Artikel tab, and as an
optional field in the article form.

- **`GET /api/products/export`** (protected) streams a semicolon-delimited CSV of all active
  articles — UTF-8 BOM so Excel picks the encoding up correctly, German decimal commas for prices,
  `ja`/`nein` for stock tracking. Columns: `Artikelnummer;Name;Gruppe;Verkaufspreis;Einkaufspreis;
  Lagerbestand_fuehren;Bestand;Meldebestand`.
- **`POST /api/products/import`** (protected) takes that same CSV back as the raw request body
  (not multipart — the frontend reads the file client-side and POSTs its text). Matches existing
  articles by Artikelnummer first, then by exact name, otherwise creates a new one; unknown groups
  are created on the fly using the row's own Lagerbestand_fuehren value as that new group's
  default. Bad rows (missing name/price/group) are skipped and reported individually rather than
  failing the whole import — returns `{created, updated, errors: [{row, message}]}`.
- Chose plain CSV over a real `.xlsx` library deliberately: this deploys via FTP with no build
  step and sometimes no SSH at all, and PHP's `fputcsv`/`str_getcsv` need zero dependencies. Excel
  opens/edits/saves the file natively either way.

## Responsive layout (tablet portrait / iPad)

The two-column POS view (tile grid + 430px cart) only works down to ~900px wide. Below that
(`.pos { flex-direction: column }`) the cart is capped at `62dvh` instead of being a free `flex: 1`
— without a cap, a tall tile grid could squeeze the cart's own totals/payment/checkout footer
below the visible area with no way to scroll to them, which is exactly what "kann unten nicht
abschließen" on an iPad turned out to be. The cart's own line-item list still scrolls internally
within that capped height; the footer stays pinned below it. Also switched `100vh`→`100dvh`
(`#app`, modal `max-height`s) throughout, since plain `vh` is fixed to the tallest possible
viewport and doesn't account for mobile Safari's collapsing address bar.

## Installable as a homescreen app (PWA)

No App Store, no Xcode, no Apple Developer account — this is a standard installable web app:

- **On the iPad**: open the site in Safari → Share → **Zum Home-Bildschirm**. It gets a real app
  icon and launches full-screen (no address bar), same as any App Store app, straight from the
  existing deployment.
- `public/manifest.webmanifest` declares the app name, icon set, and `display: standalone`.
  `public/icons/` holds the generated icon (dark background, accent-green €, matching the app's
  own palette) at the sizes iOS/Android actually request — regenerate by editing
  `Kassen-App.dc.html`'s brand colors and re-rendering, or just replace the PNGs directly.
- `public/sw.js` is a small service worker caching the app shell (`/`, `/app.js`, `/styles.css`)
  network-first with a cache fallback — instant reloads, and it still opens if the festival wifi
  drops for a moment. `/api/*` is explicitly never intercepted: sales, stock, and reports always
  need a live network round-trip, caching those would be actively wrong.
- `env(safe-area-inset-*)` padding on `#app` keeps the checkout footer clear of the home-indicator
  gesture strip current iPads reserve at the bottom when running installed, without affecting
  normal in-Safari use (insets are `0` there).

If a real App Store app ever becomes worth the $99/year Apple Developer account + Xcode/macOS
build step it requires, wrapping this same frontend in a thin WKWebView shell (Capacitor or a
handful of lines of Swift) is a much smaller lift than rewriting it natively, and the PHP API
underneath doesn't change either way.

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
- **Setting/changing the PIN and delete code**: the original design never specified a UI for this,
  but real deployments needed one (not everyone has SSH). Added a "Zugangscode & Löschkennwort"
  card to Einstellungen, backed by `PATCH /api/settings/codes`. Its one deliberate deviation from
  the rest of the auth model: on a fresh install (`access_code_hash` empty), `Auth::requireAccess()`
  bypasses the lock entirely — otherwise nobody could ever reach Verwaltung to set the first code.
  The bypass closes the moment a code is saved.
- Everything else — layout, colors, spacing, copy, the discount/tender/change-breakdown logic, the
  Z-report/close semantics, the two-factor delete flow — mirrors `Kassen-App.dc.html` and
  `design/project/design_handoff_festkasse/README.md` as closely as the stack change allows.

## Smoke-testing after deploy

```
curl https://yourdomain/api/bootstrap                     # should return products + settings
curl -X POST https://yourdomain/api/access -d '{"code":"<your code>"}' -H 'Content-Type: application/json' -i
```
The second call should set a `festkasse_token` cookie and return `expiresAt` ~2 hours out. If it
doesn't, check `.env` DB credentials and that `migrations/001_schema.sql` ran.
