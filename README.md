# islaFIND

A community services directory for **Bantayan Island** (Cebu, Philippines).
Residents and visitors browse a searchable directory of local providers —
mechanics, electricians, welders, beach resorts, boarding houses, motor
rentals — then message them, hire them, and rate the finished job.

Built as a plain PHP + MySQL application with no framework: every page is a
readable PHP file, every interaction is a normal form POST, and the few
dynamic bits (live search, notification bell, review modal) are small
hand-written JavaScript files.

---

## Contents

- [Features](#features)
- [Tech stack](#tech-stack)
- [Project layout](#project-layout)
- [Getting started (WAMP)](#getting-started-wamp)
- [Configuration](#configuration)
- [Project map](#project-map)
- [Testing](#testing)
- [Deploying](#deploying)
- [Developer utilities](#developer-utilities)
- [Security notes](#security-notes)
- [Data model](#data-model)
- [Design system](#design-system)

---

## Features

### Accounts & security
- **Unified login / sign-up screen** — two sliding panels in one page, plus
  email-code verification, forgot-password + reset, and MFA.
- **Age-gated registration** (18+) with server-side validation on every field.
- **Device logins** — every session is listed with device, IP and last login,
  and can be logged out or revoked remotely (revoking kills the real session).
- **Email verification and one-time codes** through PHPMailer over SMTP, with a
  built-in fallback that shows the code on screen when mail cannot be sent.
- **Delete account** — a separate danger zone that requires the account password
  *and* a typed `DELETE`, then removes the account, its listings, chats and
  uploaded picture.
- **Password strength meter** on sign-up, reset and change-password.

### islaFIND profiles
- **Individual Skills** listings (a person's trade) and **Business** listings
  (a shop, resort, rental), each with its own set of categories.
- **Cascading address picker** — municipality → barangay, from one shared list
  of the official Bantayan barangays (including the offshore islets).
- **Map pin** — individual listings can capture the device GPS with an
  in-app permission dialog first; business listings paste a Google Maps link or
  `lat, lng` pair. A business pin is **required** before the listing can save,
  because the detail modal's route button is built from it.
- **Directions that start from the visitor.** Google's directions deep link only
  falls back to the device location when the browser has already shared one; on
  a desktop that never has, Maps used the destination as the origin (a route
  from the shop to the shop). So islaFIND asks the visitor's own device for a
  fix — lazily, on the first tap of **Get Directions** in a card's detail
  modal, never on page load — and sends
  `origin=<visitor>&destination=<pin>&travelmode=driving`. If the visitor
  refuses or the browser has no fix, the link degrades to destination-only, and
  a blocked popup falls back to the same tab. A small **"Starts from your
  location"** caption sits under both route buttons, so the visitor knows why
  the browser is about to ask for a location before the prompt appears.
- **Owner analytics** on each listing card: views, total interactions, reviews.
- **Pinless listings are flagged** on the owner's dashboard (the islaFIND
  Profile panel and the listing's own card) with a one-tap link to the pin
  step, so older listings get fixed.

### Discovery
- **Home feed** with a cold-start ⭐ Top-Rated rails plus a ✨ Recommended rail
  ranked by category affinity, quality and recency (`track_view.php` records
  the signals in `user_interactions`). Once a GPS fix is available each rail
  re-orders its own cards **nearest-first** and each card's location pill
  shows how far away it is (rails with no pinned listings keep their server
  order and show no distance).
  The position is watched while the page is open, so a real move re-ranks the
  feed live — GPS jitter under ~25 m is ignored so the list never shuffles
  under the visitor's thumb.
- **📖 All Listings** — the **results view** at the bottom of the same Home
  panel. It ships hidden: an empty feed shows the rails, and the search box
  reveals it with every match. A search reads like any other feed section — a
  heading (`⭐ Mechanic · 3 results`) over the cards, with a **sort pill right
  beside the heading** (nearest, highest rated, most reviewed, newest)
  and the browsing furniture (profile-type toggle, category chips, live count)
  collapsed behind a small **Filters** button. Results default to **nearest
  first** from the visitor's GPS. Its cards are in the DOM whether or not it
  is open, which is what lets ONE search box reach *every* listing instead of
  only the handful a rail happens to carry. This is what the old `directory.php` did, moved into
  the app shell so the app has one listing browser instead of two that showed
  the same rows. Each catalogue card carries `id="listing-N"`, the anchor the
  Save toggle returns to.
- **Saved listings** — bookmark any listing with the heart, which sits beside
  the name on every feed card (rails, catalogue) and in the detail modal. On
  the Home feed the heart posts to `save_listing.php` with `fetch` (the
  endpoint answers JSON when the request carries `X-Requested-With: fetch`) and
  the script flips every heart for that listing in place, so a bookmark never
  reloads the page — the feed keeps its scroll position, its nearest-first
  order and the distances it had already measured. Without the script the form
  posts the ordinary way and the redirect does the work; the Settings panel
  keeps that POST, because its list has to be re-rendered.
  Bookmarks are collected in the dashboard's own **Saved Listings** panel
  (Settings → Saved Listings, `dashboard.php?tab=saved`), where each card can
  be opened in the catalogue or removed.
- **Review records** — per-star breakdown and full comments in a modal
  (`provider_reviews.php`).

### Hiring & messaging
- **Messenger** with inbox, threads, soft delete per user *and* per message.
- **Message requests** — a new inquiry is pending until the provider accepts it.
- **Hire flow**: HIRE! → ACCEPT / DECLINE → job accepted ("On the Job") →
  JOB DONE → the client is prompted to rate. Only *individual* listings can be
  hired; businesses are inquired about and chatted with.
- **Reviews are gated**: a review can only exist on a contract that reached
  `completed`, so nothing fake can be rated.
- **Notification bell** derived live from unread messages, pending hire
  requests and active jobs — it clears itself as the user acts.

---

## Tech stack

| Layer      | Choice                                                            |
| ---------- | ----------------------------------------------------------------- |
| Backend    | PHP 8 (procedural, one file per page), PDO with prepared statements |
| Database   | MySQL / MariaDB (`final_app`), utf8mb4                            |
| Frontend   | Server-rendered HTML + hand-written vanilla JavaScript, one `style.css` design system |
| Mail       | PHPMailer 6 (Composer), SMTP with app passwords                  |
| Local host | WAMP (Apache + MySQL + PHP) on Windows                            |

No build step, no bundler, no JS framework: open the folder in WAMP and it runs.

---

## Project layout

The repository is split so that **only `public/` is ever served**:

| Folder | Holds | Web-reachable? |
| ------ | ----- | -------------- |
| `public/` | The document root: every page (`index.php`, `login.php`, `dashboard.php`, `messenger.php`, `create_profile.php`, `404.php`), every POST handler and JSON endpoint, plus `style.css`, the hand-written `.js` files, `img/`, `uploads/`, `manifest.webmanifest`, `robots.txt` and the `.htaccess` that hardens the web root. | Yes — this is the document root |
| `include/` | The shared PHP includes: `db.php`, `env.php`, `security.php`, `categories.php`, `mailer.php`, `head_meta.php`, `notifications_bell.php`. Pages reach them with `require_once __DIR__ . '/../include/x.php'`. | No |
| project root | Configuration and tooling that must never be fetched: `.env`, `certs/`, `composer.json`/`composer.lock`, `vendor/`, `final_app.sql`, the two smoke tests and the CLI-only developer utilities. | No |

That split is what keeps the credentials in `.env` (and the CA
certificate, and the database dump) out of reach of a browser: they are
not merely *denied* by a rule, they are outside the document root
altogether. The project root also carries a small `.htaccess` whose only
job is to forward requests into `public/`, which is what keeps the local
WAMP URL below working.

---

## Getting started (WAMP)

1. **Put the project in the web root** — e.g. `C:\wamp64\www\islaFIND`.
   The root `.htaccess` forwards into `public/`, so nothing else is needed
   for the URL below to work. (An Apache vhost whose `DocumentRoot` points
   straight at `...\islaFIND\public` is the tidier equivalent, and matches
   how a host is configured.)
2. **Create the database.** Open HeidiSQL (or phpMyAdmin) and run
   `final_app.sql` (`File → Run SQL file…`). It creates the `final_app`
   database and every table. The script only ever uses
   `CREATE TABLE IF NOT EXISTS`, so it is safe to re-run.
3. **Create your `.env`.** Copy `.env.example` to `.env` and fill in the
   database and SMTP values.
4. **Install the mailer** (only needed for real email): `composer install`.
5. **Open the app** at <http://localhost/islaFIND/index.php> — the splash screen
   runs its readiness checks and hands off to the login page.
6. **Register an account.** If SMTP is not configured yet, the verification code
   is shown on screen instead of emailed, so you can still finish sign-up.

> The **uploads** folder — now `public/uploads/` — must be writable by
> Apache (WAMP sets this up by default). `public/uploads/.htaccess` blocks
> script execution inside it.

---

## Configuration

All secrets live in `.env` (never committed) and are read through `env.php`:

| Key                  | Purpose                                              |
| -------------------- | ---------------------------------------------------- |
| `DB_HOST` `DB_PORT`  | MySQL server address / port (default `localhost:3306`) |
| `DB_NAME` `DB_USER` `DB_PASS` | Database and credentials (`final_app`, WAMP `root` with a blank password by default) |
| `SMTP_HOST` `SMTP_PORT` | SMTP server (e.g. `smtp.gmail.com:587`)           |
| `SMTP_USER` `SMTP_PASS` | SMTP login (Gmail needs a 16-character app password) |
| `MAIL_FROM` `MAIL_FROM_NAME` | Envelope sender shown on outgoing mail        |
| `SMTP_ENCRYPTION`    | `tls` (STARTTLS) or `ssl`                            |

Values already present in the process environment always win over `.env`, so a
host (Docker, CI, production) can override any key without touching the file.

---

## Project map

**Pages** — all in `public/`

| File | What it is |
| ---- | ---------- |
| `public/index.php` | Splash screen: readiness checks + progress animation → login. |
| `public/login.php` | Login, sign-up, verify-code, forgot-password, reset, MFA panels. |
| `public/dashboard.php` | The signed-in app shell: Home feed (rails + the **All Listings** results view with search, filters and sort), Settings, Profile, islaFIND Profile, Privacy & Security, My Jobs, Saved Listings. |
| `public/messenger.php` | Inbox + chat threads, hire requests, job state, rating prompt. |
| `public/create_profile.php` | Create / edit an islaFIND listing (type, title, address, pin). |
| `public/404.php` | Branded "page not found" screen (wired up in `public/.htaccess`). |
| `public/home_feed.php` · `public/rate_modal.php` | Legacy compatibility wrappers from the original plan: thin redirects into the dashboard's Home tab and the messenger's rating flow. No logic is duplicated. |

**Handlers** (POST endpoints) — all in `public/`

`logout.php` · `save_profile.php` · `delete_profile.php` · `upload_profile.php` ·
`send_message.php` · `hire_action.php` · `rate_service.php` ·
`delete_conversations.php` · `delete_message.php` · `save_listing.php` ·
`track_view.php`

**JSON endpoints** — all in `public/`

`notifications.php` (bell data) · `provider_reviews.php` (review records) ·
`messenger_poll.php` (new-message polling)

**Shared includes** — all in `include/`, never web-reachable`security.php` (sessions, CSRF, escaping, login throttling, opt-in headers) ·
`db.php` (PDO + schema self-check) · `db_settings.php` (the one place that builds
a connection — shared with the session store) · `env.php` (.env loader) ·
`session_store.php` (opt-in MySQL session handler) · `uploads.php` (opt-in storage
seam for profile pictures) · `categories.php` (types, categories, barangays, map
centres) · `mailer.php` · `head_meta.php` (shared `<head>`) ·
`notifications_bell.php`

**Web root, configuration and assets** (in `public/`) — `.htaccess` (branded
404, hardening headers, compression) · `manifest.webmanifest` (installable
app) · `robots.txt` · `style.css` · `busy.js` (loading states) ·
`profile_script.js` · `maps_pinning.js` · `messenger.js` ·
`messenger_live.js` · `notifications.js` · `message_delete.js` ·
`password_strength.js` · `img/` · `uploads/`

**Project root** — `.htaccess` (forwards local requests into `public/`) ·
`.gitignore` · `.gitattributes` · `composer.json` / `composer.lock` ·
`final_app.sql` · `certs/`

---

## Testing

Two dependency-free smoke tests (no PHPUnit) run from the project root:

```bash
php dashboard_smoke_test.php        # links/assets resolve + the pages load over HTTP
php render_smoke_test.php           # every page renders for a SIGNED-IN user, with warnings on

composer test                       # runs the two PHP smoke tests
```

What they cover:

- **`dashboard_smoke_test.php`** — static: every local link/asset on the
  dashboard exists, the shared head is used everywhere, the manifest parses,
  dev scripts are CLI-only, and `login.php` is wired to the throttle helpers
  *before* it verifies a password (with no lockout state left in the session).
  It also holds the catalogue guards (the profile-type toggle, the category
  chips, the sort control, the Save heart beside the name, and the
  `id="listing-N"` anchor being rendered by the catalogue cards only) and the
  responsive layout guards — each one the fix for a bug measured in a real
  browser: the hidden type-picker radio stays pinned, so the create-profile
  form cannot scroll sideways on a phone; the card header may wrap the Save
  heart below the name; the catalogue is a **single-column list**, never a
  two-up grid, because the Home panel's 480px shell is narrower than the
  breakpoint that made two columns sensible; and the side gutter, the shell
  padding and the card header furniture (avatar, row gap, Save pill) all stay
  clamp() ramps, with no flat tablet override to re-create the 768px step.
  Live: starts `php -S` on a spare port and requests the real pages (login,
  dashboard's Home tab and catalogue, 404, manifest) — including the retired
  `directory.php` URL, which must now answer **404**.
- **`render_smoke_test.php`** — mints a real PHP session for an existing user
  (no password needed, and no account is modified), then loads every page and
  dashboard panel with `display_errors=1`, so a notice, warning or broken query
  fails the run. It also exercises the write paths that are safe to repeat:
  saving/un-saving a bookmark, a forged CSRF token, and the delete-account
  rejection. The successful-deletion path is **never** run — the script cannot
  destroy a real account.
  Finally it drives the login throttle end to end: it makes the failed-attempt
  counters grow, checks the wait keeps increasing and then stops growing at the
  cap, proves the IP counter catches one source spraying many accounts, posts the
  login form with a throttled identifier to confirm the page really refuses it,
  and confirms a refused attempt is not counted again. Every key it uses is a
  sentinel — identifiers under `render-smoke@example.test` and the reserved
  `203.0.113.x` addresses — and the rows it creates are deleted again on the way
  out, so a run can never throttle a real account or IP.

Both exit non-zero on failure, so they work as a pre-commit or CI check. They
create a temporary listing if the database has none, and remove it again
afterwards.

---

## Deploying

The deploy target's **document root must be `public/`**. That one setting
is what keeps `.env`, `certs/`, `vendor/` and the SQL dump outside the
web root — no code change is needed for it.

The stack is deliberately plain (no framework), and the host is a PHP
runtime:

| Host setting | Value |
| ------------ | ----- |
| Document root | `public` |
| PHP | 8.2–8.5 (the app requires >= 8.0; 8.3 is the development version) |
| Build command | `composer install --no-dev` |
| Environment variables | See below |

**Environment variables.** `include/env.php` reads the **process
environment first** and only then `.env`, so a host that injects its
variables (the normal way to configure a deployment) needs no code
change — but the names must match the ones the app actually reads:

| Key | Notes |
| --- | ----- |
| `DB_HOST` `DB_PORT` `DB_NAME` `DB_USER` `DB_PASS` | Note `DB_NAME` / `DB_USER` / `DB_PASS` — **not** the `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` spelling some platforms inject by default |
| `DB_SSL_CA` | **Required** for a managed MySQL (TiDB Cloud Serverless, PlanetScale, …), which refuses plaintext. Set it to `certs/lets-encrypt-roots.pem` — that file is committed, so no certificate download is needed. Relative paths resolve against the project root |
| `DB_SSL_VERIFY` | Leave at `1` (the default) |
| `SMTP_HOST` `SMTP_PORT` `SMTP_USER` `SMTP_PASS` `MAIL_FROM` `MAIL_FROM_NAME` `SMTP_ENCRYPTION` | The mail settings |

**On a server that ignores `.htaccess`** (nginx), the rules in
`public/.htaccess` do not apply. Nothing breaks — the app runs without
them — but here is what is lost and where each rule should go instead:

| Lost | Replacement |
| ---- | ----------- |
| Branded 404 / 403 | Use the host's own error-page setting, or accept the plain server page |
| `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` | **Implemented** — set `SEND_SECURITY_HEADERS=1` and `security.php` sends all four. Session cookie flags are **not** affected: `session_harden()` already sets HttpOnly / Secure / SameSite in code |
| Compression + static caching | Better handled by the platform's CDN or edge |
| The deny on `.env`, the dump, `composer.*` | Not needed once the document root is `public/`: those files are outside it. **Never** point the document root at the project root |

**Host switches.** A host with no persistent disk and no `.htaccess` needs
three settings changed. Every one of them defaults to the local WAMP
behaviour, so none of this touches local development:

| Variable | Default | Set to | What it does |
| -------- | ------- | ------ | ------------ |
| `SESSION_DRIVER` | `files` | `mysql` | Keeps sessions in the `isla_sessions` table (created automatically) instead of PHP's files, so logins survive a deploy and are shared between instances. |
| `SEND_SECURITY_HEADERS` | `0` | `1` | Sends the four hardening headers from PHP, for a host that ignores `.htaccess`. Leave `0` under Apache. |
| `UPLOADS_URL_BASE` | empty | bucket/CDN origin | Makes every page render pictures from that origin. Read side only — see below. |

**Sessions — done.** `SESSION_DRIVER=mysql` swaps in the handler in
`include/session_store.php`. It stores PHP's own serialised payload in a
MEDIUMBLOB and, because the app polls `messenger_poll.php` and
`notifications.php` on short timers while the visitor is also loading
pages, it holds a named lock (`GET_LOCK`) for the life of the request so
two overlapping requests cannot clobber each other's session data — the
same serialisation PHP's file handler gives for free. The lock is
fail-open: it is never the reason a visitor cannot get in.

**Headers — done.** `SEND_SECURITY_HEADERS=1` sends exactly the same
four headers `public/.htaccess` sets.

**Uploads — the read side is done, the write side is not.** Every page
builds picture URLs through `isla_upload_url()` (`include/uploads.php`),
so setting `UPLOADS_URL_BASE` moves reads to a bucket or CDN with no code
change. Writing to object storage is the one piece still outstanding: it
needs an S3 Signature Version 4 PUT, and that cannot be written honestly
without a bucket and credentials to test against — its failure mode is a
`403 SignatureDoesNotMatch` at the moment a user saves their picture.
Until it is built, `UPLOADS_DRIVER=remote` fails the upload **loudly**
and logs exactly what is missing, rather than reporting success. On a
host with a persistent disk, uploads work unchanged and none of this
applies.

---

## Developer utilities

Command-line only (`PHP_SAPI !== 'cli'` → the web request gets a 404, because
these files sit in the web root):

| File | Purpose |
| ---- | ------- |
| `setup_own.php` | Creates/resets `own.test@example.com` (password `Testpass1!`) with one listing, for testing owner-only screens. |
| `_smtp_test.php` | Sends one real email and prints the whole SMTP conversation — fastest way to debug mail settings. |

There is also `dashboard_smoke_test.php` / `render_smoke_test.php` above, and
`final_app.sql` for the schema.

---

## Security notes

Implemented everywhere, not per page:

- **Sessions** — `session_harden()` sets HttpOnly, `SameSite` (None+Secure on
  HTTPS/localhost so mobile-preview iframes still work), strict session ids, and
  a cookie-less URL fallback (`sid_append()`) for environments that cannot store
  cookies at all.
- **CSRF** — every write is a POST carrying a token checked with `hash_equals()`.
  Logging out is a POST for the same reason.
- **Login throttling** — failed sign-ins are counted server-side, one counter per
  account and one per IP, in the `login_attempts` table (`security.php`). The
  first four failures are free; after that every failure doubles the wait it has
  to sit out — 5s, 10s, 20s … capped at 15 minutes — so guessing gets slower and
  slower while nobody is ever permanently locked out, and an hour of quiet
  forgets the streak completely. The gate runs *before* `password_verify()`;
  an attempt that is turned away is not itself counted (so nobody can renew
  somebody else's backoff just by hammering the form); and a correct password
  clears both counters. The counters live in the database and never in
  `$_SESSION`.
- **SQL injection** — every query is a PDO prepared statement; user input is
  never concatenated into SQL.
- **XSS** — everything printed goes through `e()`/`htmlspecialchars()`; inline
  JS strings go through `js_string()`; free-text fields reject markup at input
  time as a second layer.
- **Uploads** — real image validation with `getimagesize()`, 5 MB cap, random
  filename, old file removed, and `.htaccess` in `public/uploads/` blocking
  execution.
- **Authorization** — ownership is re-checked server-side on every action
  (edit/delete a listing, revoke a device, accept a hire, delete a message).
- **Verified reviews** — a review requires a `completed` contract owned by the
  submitter, one review per contract.
- **Privacy** — profile phones are never rendered on public cards; contact
  details are shared inside a chat only after the provider accepts the request.
- **Secrets** — credentials live in `.env` only, and that file sits *outside*
  the document root, so it is unreachable over HTTP on any server whether or
  not that server honours `.htaccess`. `public/.htaccess` additionally denies
  `.env`, the SQL dump and the composer files by name, as a second net if one
  is ever copied into the web root.

Deliberately **not** done yet:

- **Content-Security-Policy.** Pages use inline `<script>` blocks, inline
  `onsubmit` handlers and a few inline styles. A strict CSP would break the app,
  so it needs the inline scripts externalised and nonce-based first. The reason
  is documented in `.htaccess` so nobody "fixes" it by pasting a CSP that
  silently kills the UI.
- **HTTPS.** Assumed to be provided by the host; `session_harden()` already
  enables Secure cookies automatically when it is.

---

## Data model

| Table | Holds |
| ----- | ----- |
| `users` | Accounts: names, unique email/phone, DOB (18+ check), password hash, avatar path, verification + MFA flags. |
| `user_devices` | Trusted sessions shown in Device Logins (no FK, so the delete-account flow clears it by hand). |
| `providers` | islaFIND listings: type, title, address, map pin, description or unit count, rating aggregates, view/interaction counts. |
| `conversations` | One thread per provider–client pair, with `pending` / `accepted` / `declined` request state. |
| `messages` | Thread messages with a `kind` of `chat` or `system`, plus per-user and per-message soft-delete flags. |
| `service_contracts` | Jobs: `pending → pending_hire → accepted → completed` (or `declined` / `cancelled`), pinned to the exact listing hired. |
| `reviews` | One earned review per completed contract (unique on the contract). |
| `saved_listings` | Bookmarks, unique per (user, listing). Created at runtime by `db.php` if missing. |
| `user_interactions` | View / inquiry / review history that feeds the recommendation ranking. |
| `login_attempts` | Failed-sign-in counters for throttling: a `scope` of `account` or `ip`, the `subject` being counted (the typed email/phone, lower-cased, or an IP), the `failures` in the current streak and when it started / was last added to. No foreign key — the subject is usually an identifier that matches no account. Created at runtime by `db.php` if missing. |
| `inquiries` | Legacy inquiry rows (superseded by `conversations` + `messages`; kept so the dump stays complete). |

Soft deletes (`deleted_by`, `deleted_by2`, `msg_deleted_by`) keep deletion
independent per user: what one person removes stays visible to the other.

---

## Design system

- **Palette** — Ocean Teal `#0077B6` (actions, links), Coral `#FF6B6B` (HIRE! /
  JOB DONE), off-white `#F8F9FA` background, white surfaces. All defined once as
  custom properties in `:root` inside `style.css`.
- **Layout** — card-based, mobile-first, with one responsive stylesheet; the
  dashboard uses a sliding track so panels switch without a page reload.
- **Responsive** — checked at 320–1440px in a real browser rather than by eye,
  because this is a mobile application first. Two things came out of that: a
  card header may wrap the Save heart onto its own right-aligned line when one
  row cannot hold both it and the name, which is what keeps a long business name
  readable in a 247px card (it used to be squeezed into a 68px, five-line
  column); and the listing cards stay a **single-column list**. The old
  `directory.php` could afford two-up on a tablet because it was the one page on
  a wider shell, but the catalogue now lives in the Home panel next to six other
  panels, so its 480px shell is shared and two columns there would make every
  card *narrower* than it is on a phone. The gutter and the shell's own side
  padding are **clamp() ramps rather than breakpoint steps** (16px→20px and
  16px→28px, the tablet end deliberately low because every pixel of padding
  comes straight out of the cards). Flat values there cost the shell width the
  moment the screen grew past 768px — the cards measured 332px at 768px and
  323px at 769px, i.e. *narrower* on a bigger screen. Both ramps now top out
  exactly where the shell does (2.2vw reaches 20px at 909px, 2.8vw reaches 28px
  at 1000px), so padding can never shrink the cards again. The header furniture
  is ramped the same way — avatar `clamp(48px, 7vw, 64px)`, row gap 10→12px,
  Save pill padding 6/10→7/12px — because flat values there grew the furniture
  *faster* than the card grew, so the name column went 163px at 768px to 140px
  at 769px even though the card itself got wider. Each ramp starts and ends on
  its old tablet/desktop value, so phones are untouched and the header looks the
  same as before from ~920px up. Measured — the header's name column runs
  131–253px with a >=29px-tall Save pill throughout, and there is zero
  horizontal overflow at every width tested.
- **Feedback** — `busy.js` gives every form a spinner and blocks double submits;
  flashes are rendered as banners with `role="alert"` / `role="status"` so
  screen readers announce them.
- **Motion** — short reveal animations only, and a
  `prefers-reduced-motion: reduce` block that switches them off.
- **Focus** — a visible `:focus-visible` ring for keyboard users, without
  changing how mouse and touch interaction looks.

---

*Developer: Dave Vidad · Documentation kept in step with the code — update this
file when a feature lands or a handler is added.*
