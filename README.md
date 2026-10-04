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
  every uploaded picture: the account avatar, plus each listing's own cover and
  album.
- **Password strength meter** on sign-up, reset and change-password.

### Reporting & superadmin
- **Report a listing** from its detail modal: a whitelisted reason, an optional
  note and an optional JPG/PNG screenshot (3 MB). One open report per member per
  listing, and five reports an hour per member.
- **A moderation queue** — every report with who filed it, what they said, the
  evidence, the listing as members see it, and the reports already filed against
  that listing. Actions: block the listing, mark resolved, or dismiss, each with
  a note.
- **Blocking takes a listing off the island.** A blocked listing leaves the
  feed, the catalogue, search, saved lists, messages, hiring and view-tracking,
  and its deep link stops resolving — to a member it is indistinguishable from
  a listing that never existed. Its **owner** still sees it, with the reason,
  so they can fix it and ask for a review.
- **Blocking an ACCOUNT closes the person, not the page.** The queue lists the
  owner of every reported listing, and both that row and the report page can
  block the owner instead of the listing: the sign-in is refused, any session
  they have open is dropped on its next request, and every listing they own
  leaves the app — from
  one flag on `users`, with no listing row touched, so unblocking restores all
  of them exactly as they were. Reports open against that owner are resolved
  with the reason attached.
- **A separate superadmin identity.** `admins` is not a kind of user: it has
  its own table, its own session key and its own login branch, and no member
  page can reach it.
- **Accounts are created from the server, never by sign-up** —
  `php tools/create_admin.php`, with the password typed in blind. Everything
  about the account is then changeable in the panel: email (proved with a code
  sent to the *new* address), password, and two-factor on/off.
- **Email codes instead of an authenticator app** — no TOTP, no QR, nothing to
  enrol. Two-factor is on by default and costs one email per sign-in.

### islaFIND profiles
- **Individual Skills** listings (a person's trade) and **Business** listings
  (a shop, resort, rental), each with its own set of categories.
- **A picture of its own, per listing** — every listing carries its own photo
  (`providers.profile_picture`), so one shop with three outlets, or one mechanic
  with a workshop and a roadside stall, is not stuck with a single shared image.
  A listing with no picture of its own keeps showing the account avatar, so
  every listing created before this feature looks exactly as it did. It is
  managed from the **islaFIND Profile** panel (`dashboard.php?tab=isla`), and
  deleting the listing or the account takes the file with it.
- **Business photo album** — a *business* listing can hold up to **5** extra
  photos (`provider_album_images`, kept in order), because a resort, a rental or
  a shop is chosen on its photos. The visitor sees the cover on the card and the
  whole album as a strip in the listing's detail modal. Individual listings are
  refused an album — the account avatar is already that person — and the cap is
  a hard one: the sixth photo is **refused**, not silently dropped, so an owner
  is never told a photo was saved when it was not. A listing also can never be
  left with no picture at all, so the last remaining one (cover or album) cannot
  be removed while nothing else would still cover for it.
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
- **The feed is watched live** (`feed_watch.php`): every 15 s — and on every
  return to the tab — the page sends the ids of the cards it is showing, as one
  ascending comma-separated list, and the server answers with the same list
  built from `isla_listing_live_where()`. Equal strings cost ~25 bytes; anything
  else comes back as `removed` / `added` plus finished card markup from the one
  shared renderer (`include/feed_card.php`). So a listing that is **deleted from
  another device**, or blocked by an admin, **leaves the open feed without a
  refresh** — from the rails, the catalogue, the owner's panel and Saved
  Listings at once, closing its detail modal if that is what is open — and a
  newly published listing arrives in place. The poll is a comparison of the two
  lists as plain strings, so a stale page cannot talk its way past it; the same
  rule that hides a deleted listing from a fresh page load is the rule that
  removes it from an open one. Curated rails are never re-ranked by a live
  arrival (only the catalogue grows), and if the island empties out entirely
  the page reloads into the server's own empty state.
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
  hired; businesses are inquired about and chatted with. The gate is
  **per-listing, not per-person**: an owner who has both a business *and* a
  skill (a mechanic who also runs a sari-sari store) is not hireable through
  the business inquiry — the HIRE! button reads the thread's own subject
  listing, and a crafted hire request is refused server-side.
- **Trackable inquiries**: every inquiry writes a `service_contracts` row, so
  it shows up under **My Jobs** instead of living only as a chat bubble. An
  individual inquiry is the hire job above; a business inquiry is a **booking**
  the owner can track and close out — it never enters the hire or rating chain.
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
| `include/` | The shared PHP includes: `db.php`, `env.php`, `security.php`, `categories.php`, `mailer.php`, `assets.php`, `head_meta.php`, `notifications_bell.php`. Pages reach them with `require_once __DIR__ . '/../include/x.php'`. | No |
| project root | Configuration and tooling that must never be fetched: `.env`, `certs/`, `composer.json`/`composer.lock`, `vendor/`, `final_app.sql`, the three smoke tests, the `tools/` CLI and the CLI-only developer utilities. | No |

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
| `public/report_listing.php` | Files a listing report: a whitelisted reason, an optional note and an optional JPG/PNG screenshot. |
| `public/admin/index.php` | The superadmin panel: overview, the report queue, one report in full, and an owner profile. One shell, four views, with a header Back button on every view but the overview. |
| `public/admin/views/` | The four panel bodies, split out of `index.php` so the shell (guard, data, sidebar) is not repeated: `overview.php` (counts + newest reports), `reports.php` (the filterable queue, narrowable to one listing via `?provider=`), `report.php` (one report, the listing, the actions), `owner.php` (every listing under one account, with the account-level block). |
| `public/admin.css` | The panel's own stylesheet, loaded after `style.css`. Every rule is namespaced `.adm-*`, and the block below re-scopes the shared `.btn` / `.field-*` controls for a desktop panel. Below 860px the sidebar collapses into the member app's menu: a round header button opening a full-screen card of section rows that slides open on the member app's own 0.45s easing, with the page content fading out beneath it. Both admin pages link it through `admin_asset_url()`, which appends the file's mtime (`?v=…`) so a phone that cached an older panel cannot keep rendering a layout that has since been replaced. |
| `public/admin_nav.js` | The collapsed admin menu's memory and manners: remembers an opened hamburger across reloads, and folds it again on a section link, on sign-out, on a tap outside the sidebar or on Escape. Purely an enhancement — the checkbox in the markup toggles the menu without it. Loaded through `admin_asset_url()` like `admin.css`, so the two admin assets share one cache-busting rule. |
| `public/admin/settings.php` | The admin's own credentials. A HUB rather than one stacked page: `settings.php` lists the options (email, password, two-factor, account tooling) and each opens on its own screen at `?section=email\|password\|mfa\|account`. Every change still goes through the same password/emailed-code gates. |
| `public/admin/action.php` | Every moderation decision (block, unblock, resolve, dismiss) — POST + CSRF. |
| `public/admin/logout.php` | Ends an admin session (POST, like every other state change). |

**Handlers** (POST endpoints) — all in `public/`

`logout.php` · `save_profile.php` · `delete_profile.php` · `upload_profile.php` ·
`upload_listing_photos.php` (a listing's own cover + its business album: one
handler, three actions) ·
`send_message.php` · `hire_action.php` · `rate_service.php` ·
`delete_conversations.php` · `delete_message.php` · `save_listing.php` ·
`track_view.php`

**JSON endpoints** — all in `public/`

`notifications.php` (bell data) · `provider_reviews.php` (review records) ·
`messenger_poll.php` (new-message polling) · `feed_watch.php` (the live feed
watcher: which listings left, which arrived, and the markup for the newcomers)

**Shared includes** — all in `include/`, never web-reachable`security.php` (sessions, CSRF, escaping, login throttling, opt-in headers) ·
`db.php` (PDO + schema self-check) · `db_settings.php` (the one place that builds
a connection — shared with the session store) · `env.php` (.env loader) ·
`session_store.php` (opt-in MySQL session handler) · `uploads.php` (opt-in storage
seam for the account avatar and listing pictures) · `categories.php` (types,
categories, barangays, map centres) · `mailer.php` · `admin_auth.php` (superadmin
identity, email-code challenges and guards — separate from member identity on
purpose) · `reporting.php` (report reasons, IslaProfile IDs) · `assets.php` (the
`?v=<mtime>` cache-busting stamp shared by the member app's `asset_url()` and the
panel's `admin_asset_url()`) · `head_meta.php` (shared
`<head>`) · `notifications_bell.php` · `member_guard.php` (a blocked account
loses its session on the next request — called from `db.php`, the one include
with a connection) · `listing_visibility.php` (the one place that says which
listings members may discover: the listing's own status *and* its owner's) ·
`feed_card.php` (the Home feed's ONE card renderer, shared by the page and by
the live feed watcher, plus `isla_feed_id_list()` — the canonical id list both
sides of the watch compare as plain text)

**Web root, configuration and assets** (in `public/`) — `.htaccess` (branded
404, hardening headers, compression) · `manifest.webmanifest` (installable
app) · `robots.txt` · `style.css` · `busy.js` (loading states) ·
`profile_script.js` · `maps_pinning.js` · `messenger.js` ·
`messenger_live.js` · `notifications.js` · `message_delete.js` ·
`password_strength.js` · `img/` · `uploads/` — the stylesheet and scripts are
linked through `asset_url()` (see `include/assets.php`), so each carries a
`?v=<mtime>` stamp that changes only when the file does.

**Project root** — `.htaccess` (forwards local requests into `public/`) ·
`.gitignore` · `.gitattributes` · `composer.json` / `composer.lock` ·
`final_app.sql` · `certs/` · `tools/` (CLI-only: `create_admin.php`) ·
the three smoke tests

---

## Testing

Three dependency-free smoke tests (no PHPUnit) run from the project root:

```bash
php dashboard_smoke_test.php        # links/assets resolve + the pages load over HTTP
php render_smoke_test.php           # every page renders for a SIGNED-IN user, with warnings on
php admin_flow_test.php             # reporting + moderation, end to end (NEEDS the database)

composer test                       # runs all three
```

The first two need no database (the pages redirect to login before touching it),
so they pass on a machine with MySQL stopped. **`admin_flow_test.php` does need
one** — it creates a throwaway admin, two members and a listing, then drives a
real report through the real handlers into the real moderation queue.

What they cover:

- **`dashboard_smoke_test.php`** — static: every local link/asset on the
  dashboard exists, the shared head is used everywhere, the manifest parses,
  dev scripts are CLI-only, and `login.php` is wired to the throttle helpers
  *before* it verifies a password (with no lockout state left in the session).
  It also holds the report-UI guards, because that form is the one thing a
  member uses to reach a human and it fails silently when it breaks: the report
  button exists and starts hidden, the modal carries a reason, a note and a
  screenshot field, the reasons are rendered *from the same whitelist the
  handler validates against* (so the two lists cannot drift apart), the form
  posts with a CSRF token, and **the form is reset before the listing id is
  filled in** — `form.reset()` restores every control to its markup default,
  and the hidden listing-id input's default is empty, so the other order
  submits a perfectly valid-looking report with no listing attached and nothing
  else in the suite would notice.
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
   It then drives the listing-photo endpoint with real multipart uploads against
   a throwaway business listing: a cover is stored and replacing it removes the
   old file, a batch of 5 is accepted and the 6th is refused, one album photo can
   be removed, and every refusal is proved to change nothing — a photo id
   belonging to another listing, somebody else's listing, an individual listing
   asking for an album, a forged token, a renamed text file. The one-picture
   floor is checked both ways: the last cover, and the last album photo, both
   survive while nothing else would still carry the listing.
   Everything it creates — the listings, the album rows, the files, and the
   account avatar it borrows for the fallback — is undone by a shutdown handler
   rather than by a tidy-up at the end, so a fatal error halfway through cannot
   leave a "Render Photo Test Shop" listing in the real database. Each of those
   checks builds the state it needs instead of looking for it in the database: a
   check that quietly skips itself when the data happens to be missing is worse
   than no check, because the suite still reports green.
- **`admin_flow_test.php`** — the report → moderation → block path, driven over
   HTTP against the real database. It signs a member and an admin in through the
   real login form (so the guard, the CSRF token and the session cookies are all
   exercised rather than stubbed), then asserts:
   - **Guards** — a logged-out visitor and a *member* session are both kept out
     of `/admin/`.
   - **Settings** — the admin can change their own password; a missing CSRF
     token, a wrong current password, a mismatched confirmation and a too-weak
     password each change nothing; and an email change cannot move the address
     before a code sent to the new inbox has come back.
   - **Reporting** — a report is stored pending and shows up in the queue and on
     its detail page; a duplicate open report is refused; an owner cannot report
     their own listing.
   - **Moderation** — blocking stores the reason, resolves the open report and
     records that the listing was blocked.
   - **Trackable inquiries** — an inquiry writes a `service_contracts` row and
     stamps the conversation with the listing it is about; the thread header,
     the inbox row and the poll JSON all carry that subject. The hire gate is
     proved per-listing: an owner with both a business and an individual listing
     gets HIRE! on the individual thread and **not** on the business one, a
     crafted hire against the business listing is refused, an individual hire is
     still pinned to the individual listing, and a business booking can be
     completed but never moved to `accepted`.
   - **Admin queue search** — the one search box finds a report by the listing's
     IslaProfile ID, the reported owner's email or member id, and the reporting
     member's email or member id; a term that matches nothing returns an empty
     queue, and the term is carried into the tab links and echoed on screen.
   - **A blocked listing is gone** — the feed/catalogue query stops returning it,
     no card is rendered for it, and it cannot be reported, saved, messaged
     about or view-tracked; meanwhile its owner still sees it, is told it is
     invisible and is shown the reason. Unblocking puts it straight back.
   - **Two-factor** — with MFA on, a correct password still stops at the code
     step and the half-finished session cannot reach the panel.

   Everything it creates is tagged with a per-run id and removed by a shutdown
   handler, so an interrupted run cannot leave fixtures behind. Each delete is
   attempted independently: an earlier version wrapped them in one `try`, and a
   single failure (a table this schema does not have) stranded every fixture in
   the live database, where the next run tripped over its own leftovers. If
   cleanup ever does fail it now says so loudly and prints the SQL to fix it by
   hand.

All three exit non-zero on failure, so they work as a pre-commit or CI check.
The first two create a temporary listing if the database has none, and remove it
again afterwards.

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
| `_smtp_test.php` | Sends one real email and prints the whole SMTP conversation —" fastest way to debug mail settings. |
| `tools/create_admin.php` | Creates or resets a **superadmin** account. CLI-only. |

There is also `dashboard_smoke_test.php` / `render_smoke_test.php` /
`admin_flow_test.php` above, and `final_app.sql` for the schema.

### Creating the superadmin account

There is no admin sign-up page, and there is not meant to be one: the only way
in is from a shell on the server.

```bash
php tools/create_admin.php                              # prompts for both
php tools/create_admin.php --email=you@example.com      # prompts for the password only
ISLA_ADMIN_EMAIL=you@example.com ISLA_ADMIN_PASSWORD='...' php tools/create_admin.php
php tools/create_admin.php --email=you@example.com --reset    # new password, same account
```

- The password is typed **blind** (terminal echo off) and never passed on the
  command line unless you choose the env-var form, so it does not land in your
  shell history. It is stored as a bcrypt hash.
- The same password rules as a member account apply (8–64 characters, an
  uppercase letter, a lowercase letter and one of `! @ -`), enforced by the
  shared `isla_password_problem()` so the two can never drift apart.
- Running it again for an existing address **resets** that account's password
  and re-activates it — that is the recovery path if the admin loses the
  password.
- Two-factor email codes are **on** by default. After that, the admin can
  change their email, password and two-factor setting from **Settings** in the
  panel; nothing about the account is frozen after creation.

`tools/` sits outside `public/`, so `create_admin.php` cannot be reached over
the web even if the document root is misconfigured.

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
- **Uploads** — real image validation with `getimagesize()` (content, not the
  filename or the browser's declared type), 5 MB cap, random filename, old file
  removed, and `.htaccess` in `public/uploads/` blocking execution. The account
  avatar and a listing's cover + business album all go through the same
  validation, and the album is capped per listing.
- **Authorization** — ownership is re-checked server-side on every action
  (edit/delete a listing, revoke a device, accept a hire, delete a message).
- **Verified reviews** — a review requires a `completed` contract owned by the
  submitter, one review per contract.
- **Privacy** — profile phones are never rendered on public cards; contact
  details are shared inside a chat only after the provider accepts the request.
- **Superadmin identity is not member identity.** `admins` is a separate table
  with its own session key (`admin_id`), its own login branch, and its own
  guard. A member session cannot reach `/admin/`, and an admin session cannot
  be expressed as a `user_id` — so no member page can be talked into treating
  an admin as a member.
- **Admin pages are `noindex, nofollow`** and every moderation action is a POST
  with a CSRF token, so a moderation queue never reaches a search index and a
  decision cannot be triggered by a link or an `<img>`.
- **Credential changes are proved, not assumed.** Changing the admin email
  requires the current password *and* a code sent to the new address; toggling
  two-factor requires a code sent to the current one; changing the password
  requires the current password. Email-change and sign-in codes live in
  separate session channels, so a code that arrived to sign in can never be
  replayed to rewrite the account.
- **A blocked account is closed at the door, not just hidden.** The sign-in is
  refused *after* the password is proved, so the form never tells a stranger
  which addresses exist, and it says why in the words the admin recorded. A
  session already open is cut on its next request (the check lives in
  `db.php`, which every authenticated page reaches, rather than in the twenty
  hand-written session guards it would otherwise have to be repeated in) — and
  because nothing is written to the account except its own status flag, a
  restore brings every listing and its history back untouched.
- **A blocked listing is unreachable, not just unlisted.** It is filtered out of
  every public read *and* every public write, so it cannot be saved, messaged
  about, hired or view-tracked — while its owner keeps full control of it and
  is told why.
- **Reporting is rate-limited and one-at-a-time**: one open report per member
  per listing, five an hour per member, a whitelisted reason list, and evidence
  that is validated by type and size server-side.
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
| `users` | Accounts: names, unique email/phone, DOB (18+ check), password hash, avatar path, verification + MFA flags. Also `status` (`active` / `blocked`) with `blocked_at` + `blocked_reason`: the account-level block an admin can set from the report queue. A blocked account cannot sign in, loses any open session on its next request, and its listings stop being discoverable — the listings themselves are never modified, so unblocking restores all of them. |
| `user_devices` | Trusted sessions shown in Device Logins (no FK, so the delete-account flow clears it by hand). |
| `providers` | islaFIND listings: type, title, address, map pin, description or unit count, the listing's own picture (`profile_picture`, nullable — `NULL` means "use the owner's account avatar"), rating aggregates, view/interaction counts. Also `profile_code` (the `ISLA-000123` reference an admin uses to talk about a listing, unique, assigned on save) and `status` (`active` / `blocked`) with `blocked_at` + `blocked_reason`. Every public query filters `status = 'active'` **and the owner's `status`** (see `include/listing_visibility.php`); the owner's own panels deliberately do not. |
| `profile_reports` | One row per listing report: which listing, who filed it, the `reason_code`, their note, an optional `evidence_image`, the state (`pending` / `resolved` / `dismissed`), the admin's note, whether the listing was blocked as a result, and who decided. One open report per member per listing is enforced by a partial unique index. |
| `admins` | Superadmin accounts — deliberately NOT a kind of member: their own table, so no member query or member session can reach them. Unique email, bcrypt hash, display name, MFA flag, active flag, last login (time + IP). Rows are created from the server with `tools/create_admin.php`, never by a web form. |
| `provider_album_images` | A business listing's extra photos: `provider_id`, the stored filename, its `sort_order`, and when it was added. Up to `ISLA_ALBUM_MAX_PHOTOS` (5) per listing, deleted with the listing by its foreign key. Created at runtime by `db.php` if missing. |
| `conversations` | One thread per provider–client pair, with `pending` / `accepted` / `declined` request state. |
| `messages` | Thread messages with a `kind` of `chat` or `system`, plus per-user and per-message soft-delete flags. |
| `service_contracts` | Trackable inquiries, pinned to the exact listing asked about. An **individual** inquiry is a job: `pending → pending_hire → accepted → completed` (or `declined` / `cancelled`), and only it can be rated. A **business** inquiry is a booking that stops at `pending` / `completed` and is never hired or rated. |
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
