<?php
// ============================================================
// security.php — Shared security helpers
//                     (sessions + CSRF + login throttling)
// Included by every page that starts a session or renders a
// form, so the same protections apply everywhere:
//   - session_harden() -> tighter session cookie + strict IDs
//   - URL session-id fallback for cookie-blocked environments
//   - sid_append()     -> keep the session id on redirects
//   - csrf_token()     -> get (or create) the form token
//   - csrf_check()     -> verify a submitted token
//   - e()              -> escape a value for HTML output (XSS)
//   - js_string()      -> escape a value for an inline JS handler (XSS)
//   - contains_html_tag() -> does a value carry markup? (input check)
//
// Login throttling (used by login.php, counted in the database):
//   - login_throttle_wait()           -> seconds this caller must wait
//   - login_throttle_record_failure() -> add one failed attempt
//   - login_throttle_clear()          -> forget a streak after success
//   - login_backoff_seconds()         -> the wait a streak has earned
// The counters live in the login_attempts table (see db.php /
// final_app.sql). They are SERVER-side on purpose: nothing about a
// lockout is ever kept in $_SESSION, which is what the removed
// session-based lockout got wrong (see the note in login.php).
//
// Host-dependent extras (both OFF by default, so WAMP is unaffected):
//   - isla_send_security_headers() -> SEND_SECURITY_HEADERS=1
//   - the database session handler -> SESSION_DRIVER=mysql
// ============================================================

// ------------------------------------------------------------------
// Dependencies, loaded at include time.
//
// env.php gives us env(); session_store.php gives us the opt-in
// database session handler. Both are dependency-free and idempotent
// (every include in the app is require_once), and both must be
// available BEFORE the first session_start() — which is here, not in
// db.php, because login.php starts its session ten lines before it
// includes the database.
// ------------------------------------------------------------------
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/session_store.php';

// ------------------------------------------------------------------
// Opt-in security headers.
//
// Apache sends these from public/.htaccess, so on WAMP they are
// already covered and this is OFF. A host that ignores .htaccess
// (nginx) sends none of them, so a deployment sets
// SEND_SECURITY_HEADERS=1 and the app sends them itself. See README
// ("Deploying") for which host needs which.
//
// This runs at include time because header() only works before any
// output, and every page includes this file first.
// ------------------------------------------------------------------
if (env('SEND_SECURITY_HEADERS', '0') === '1') {
    isla_send_security_headers();
}

// ------------------------------------------------------------------
// Cookie-less session fallback (run at include time, BEFORE any
// session_start()). Some mobile-preview extensions sandbox the site in
// an iframe that cannot store HTTP cookies at all — not even a
// SameSite=None cookie. In that case every request starts a FRESH
// session and the CSRF token stored in the session is "gone", so any
// form POST fails with "Your session expired or the form token is
// invalid." 
// 
// When no session cookie was received, tell PHP to carry the session
// id through the page instead: every local link is rewritten with
// ?PHPSESSID=... and every form gets a hidden PHPSESSID field
// ("fakeentry"). Clients that keep our cookie (normal browsers, the
// SameSite=None iframe case) are NOT affected — the condition below is
// false for them, so no session id ever leaks into their URLs.
// ------------------------------------------------------------------
if (!isset($_COOKIE[session_name()])) {
    ini_set('session.use_only_cookies', '0');
    ini_set('session.use_trans_sid', '1');
    ini_set('url_rewriter.tags', 'a=href,area=href,frame=src,input=src,form=fakeentry,fieldset=');
}

/**
 * sid_append()
 * Appends the session id to a redirect URL whenever the client could
 * not keep our session cookie. PHP's URL rewriter re-writes in-page
 * links and forms automatically, but header('Location: ...') redirects
 * are NOT rewritten — without this, a POST -> redirect cycle drops the
 * session (and the CSRF token) in a cookie-blocked environment.
 *
 * @param string $url Relative URL (optionally with a query string).
 * @return string The URL with the session id appended when needed.
 */
function sid_append(string $url): string
{
    if (isset($_COOKIE[session_name()]) || session_id() === '') {
        return $url;
    }
    $sep = strpos($url, '?') !== false ? '&' : '?';
    return $url . $sep . session_name() . '=' . session_id();
}

/**
 * session_harden()
 * Tightens the session cookie BEFORE session_start() is called:
 *  - HttpOnly:  the cookie is invisible to JavaScript, so an XSS
 *               bug cannot steal the session ID.
 *  - SameSite=None (+ Secure) on localhost/HTTPS so the cookie is
 *               still sent on form POSTs made inside a cross-site
 *               mobile-preview iframe that does allow cookies.
 *  - Strict mode: PHP discards any session ID supplied by the
 *               client that it did not create itself.
 *  - Storage:   opts the session into the database when
 *               SESSION_DRIVER=mysql, so it survives a host that
 *               rebuilds its container on every deploy (see
 *               session_store.php). Otherwise files stay the
 *               default and nothing about local behaviour changes.
 */
function session_harden(): void
{
    // Must be called before session_start().

    // A SameSite=Lax session cookie is NOT sent by the browser when a
    // form is POSTed from inside a cross-site iframe. Mobile-preview
    // extensions ("Mobile View", "Responsively", etc.) host the site in
    // exactly such an iframe, so every POST opened a fresh session and
    // the stored CSRF token was "gone" — producing "Your session expired
    // or the form token is invalid." on login/register. SameSite=None
    // keeps the cookie working in those embedded (and normal) contexts.
    // Modern browsers only store a None cookie when it is also Secure;
    // this is fine over plain HTTP on localhost (a secure context), and
    // over HTTPS everywhere. Plain-HTTP non-localhost hosts fall back to
    // SameSite=Lax.
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalSecure = $host === 'localhost'
        || strpos($host, '.localhost') === strlen($host) - strlen('.localhost');
    $secure = !empty($_SERVER['HTTPS']) || $isLocalSecure;

    session_set_cookie_params([
        'lifetime' => 0,        // Session cookie: expires when the browser closes
        'path'     => '/',      // Valid for the whole app
        'httponly' => true,     // JavaScript cannot read the cookie
        'secure'   => $secure,  // Required for SameSite=None; OK on localhost/HTTPS
        'samesite' => $secure ? 'None' : 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');

    // Where the session is actually STORED. A no-op unless
    // SESSION_DRIVER=mysql, which is what a host with an ephemeral
    // disk needs: file sessions are destroyed on every deploy and are
    // not shared between instances. It must happen here rather than in
    // db.php, because the handler has to be registered before
    // session_start(). See session_store.php.
    isla_session_register();
}

/**
 * isla_send_security_headers()
 * Sends the same hardening headers public/.htaccess sets, for hosts
 * that never read an .htaccess (nginx). Toggle with
 * SEND_SECURITY_HEADERS=1.
 *
 * The values are deliberately IDENTICAL to the Apache ones: two
 * different sets depending on the host is how a page turns out to be
 * "fixed" in one environment only.
 *
 * header(..., true) replaces rather than appends, so calling this
 * twice is harmless — and a duplicated X-Frame-Options is worse than
 * none, because browsers disagree on which copy wins.
 *
 * @return void
 */
function isla_send_security_headers(): void
{
    // Headers cannot be sent once output has started. This is called
    // before output on every page, so getting here means a caller
    // echoed early; log it rather than fail silently.
    if (headers_sent()) {
        error_log('islaFIND: security headers not sent (output already started)');
        return;
    }

    // Stop browsers second-guessing a declared content type (also
    // removes a class of "image that is really HTML" attack).
    header('X-Content-Type-Options: nosniff', true);

    // Never framed by another site. SAMEORIGIN, not DENY: the app may
    // frame its own pages (mobile-preview shells do exactly that).
    header('X-Frame-Options: SAMEORIGIN', true);

    // Do not leak the full URL of one page to the next site.
    header('Referrer-Policy: strict-origin-when-cross-origin', true);

    // No flash/geolocation/camera by default; each page asks the
    // browser for the single capability it needs.
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=()', true);
}

/**
 * e()
 * Escapes a value for output in an HTML text or attribute context.
 * Every value that came from a user (or the database) MUST pass
 * through this before it is echoed into a page, otherwise stored
 * text like <script>alert(1)</script> would run in the visitor's
 * browser instead of being displayed as text.
 *
 * ENT_QUOTES escapes BOTH quote styles, so one call is safe inside
 * single- and double-quoted attributes alike, and ENT_SUBSTITUTE
 * replaces malformed UTF-8 with U+FFFD instead of silently
 * returning an empty string.
 *
 * @param mixed $value Raw value to escape.
 * @return string HTML-safe representation of the value.
 */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * js_string()
 * Encodes a value as a JavaScript string literal that is safe to
 * drop into an inline handler inside an HTML attribute, e.g.:
 *
 *   onsubmit="return confirm(<?php echo js_string($name); ?>);"
 *
 * htmlspecialchars() alone is NOT enough there: the HTML parser
 * decodes entities in an attribute BEFORE JavaScript sees the
 * code, so an escaped apostrophe (&#039;) becomes a real ' and
 * closes the JS string. The JSON_HEX_* flags turn <, >, ', ", and
 * & into \uXXXX escapes that survive entity decoding, and
 * htmlspecialchars() then escapes the literal's own double quotes
 * so it cannot break out of the attribute.
 *
 * @param mixed $value Raw value to encode.
 * @return string Ready-to-echo JS literal (quoted).
 */
function js_string($value): string
{
    return htmlspecialchars(
        json_encode(
            (string) $value,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        ),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * contains_html_tag()
 * TRUE when a value looks like it carries markup: a "<" followed by
 * a letter, a closing slash or a bang (e.g. <script>, </div>, <!--).
 *
 * Used to REJECT such values at input time, so a free-text field can
 * never store markup in the first place. Output escaping (e()) is
 * still what actually makes the app safe — this is a second layer
 * that keeps the stored data plain text.
 *
 * The pattern deliberately does NOT match a "<" followed by a space
 * or a digit, so ordinary text such as "units < 2 tons" or
 * "<500 pesos" keeps working. Real tags always start with a letter,
 * "/" or "!", and a browser cannot parse "< 5" as a tag.
 *
 * @param mixed $value Raw value to inspect.
 * @return bool TRUE when the value contains something tag-like.
 */
function contains_html_tag($value): bool
{
    return (bool) preg_match('/<[a-zA-Z\/!]/', (string) $value);
}

/**
 * csrf_token()
 * Returns the session's CSRF token, generating a fresh random one
 * the first time it is asked for. The SAME token is embedded in
 * every form's hidden field, so each form the user sees is bound
 * to their own session.
 *
 * @return string 64-character hexadecimal token
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * csrf_check()
 * Verifies the token submitted with a POST against the one stored
 * in the session. hash_equals() performs a constant-time compare,
 * so the check does not leak timing information.
 *
 * @return bool TRUE when a valid token was submitted
 */
function csrf_check(): bool
{
    $submitted = $_POST['csrf_token'] ?? '';
    return isset($_SESSION['csrf_token'])
        && is_string($submitted)
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

// ==================================================================
// LOGIN THROTTLING
// ------------------------------------------------------------------
// Brute-forcing a password needs MANY attempts, so the defence is to
// make each further attempt more expensive to make. This used to be
// done with a counter in $_SESSION, which was wrong twice over:
//
//   1. an attacker simply dropped the cookie and started a fresh
//      session, so the counter never reached a limit, and
//   2. a legitimate user sharing a device could be locked out of
//      their OWN account by somebody else's guessing on the same
//      browser profile.
//
// So the counters live in the database instead, keyed by the account
// being tried AND the IP trying it:
//
//   * the ACCOUNT key stops one attacker grinding a single account,
//     from anywhere;
//   * the IP key stops one source spraying many different accounts,
//     which the account key alone would never notice.
//
// The wait grows (5s, 10s, 20s ... up to 15 minutes) and is capped,
// so a locked-out user is always minutes away from trying again —
// never permanently shut out, which is what "increasing backoff
// rather than a hard lock" means here. A streak also DECAYS: after
// LOGIN_ATTEMPT_WINDOW with no failures it is forgotten entirely, so
// the occasional forgotten password never accumulates into a block.
//
// Every helper below is FAIL-OPEN: if the login_attempts table
// cannot be read or written, the error is logged and the login is
// allowed to proceed. A throttle that breaks must never be able to
// lock every visitor out of the app.
// ==================================================================

const LOGIN_FREE_ATTEMPTS    = 4;     // failures that cost nothing (honest typos)
const LOGIN_BACKOFF_STEP     = 5;     // seconds of wait on the first failure past that
const LOGIN_BACKOFF_MAX      = 900;   // ceiling for the wait (15 min): never a hard lock
const LOGIN_ATTEMPT_WINDOW   = 3600;  // seconds of quiet that forget a streak (1 hour)
const LOGIN_ATTEMPT_TTL_DAYS = 30;    // rows untouched for this long are pruned

/**
 * login_backoff_seconds()
 * How long the caller must wait after `$failures` failures in the
 * current streak. The first LOGIN_FREE_ATTEMPTS are free, then each
 * further failure doubles the wait, up to LOGIN_BACKOFF_MAX.
 *
 *   4 failures -> 0s      8 ->   40s
 *   5          -> 5s      9 ->   80s
 *   6          -> 10s    10 ->  160s
 *   7          -> 20s    13 ->  900s (capped, and stays there)
 *
 * @param int $failures Failed attempts in the current streak.
 * @return int Seconds of wait earned by that streak (0 = no wait).
 */
function login_backoff_seconds(int $failures): int
{
    if ($failures <= LOGIN_FREE_ATTEMPTS) {
        return 0;
    }

    // The first PAID failure waits LOGIN_BACKOFF_STEP, the next double
    // it, and so on. Capped before the exponent so it can never
    // overflow an int on the way to a value we are going to clamp.
    $doublings = $failures - LOGIN_FREE_ATTEMPTS - 1;

    if ($doublings > 20) {          // 5 * 2^20 seconds is far past the cap
        return LOGIN_BACKOFF_MAX;
    }

    return (int) min(LOGIN_BACKOFF_MAX, LOGIN_BACKOFF_STEP * (2 ** $doublings));
}

/**
 * login_attempt_client_ip()
 * The address a throttled attempt is counted against.
 *
 * Deliberately reads REMOTE_ADDR (the socket address) and NOT
 * X-Forwarded-For: that header is just text the caller sends, so
 * trusting it would let a bot hand itself a brand-new IP — and with
 * it a brand-new counter — on every single request.
 *
 * The address is re-encoded through inet_pton()/inet_ntop() so every
 * spelling of one address collapses to a single form: IPv6 has many
 * ("2001:db8::1" and "2001:0db8:0000::1"), and without this a bot
 * could rotate text forms to farm counters the same way.
 *
 * @return string Normalised client IP, or '0.0.0.0' when unknown.
 */
function login_attempt_client_ip(): string
{
    $raw    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $packed = @inet_pton($raw);

    return $packed === false ? $raw : inet_ntop($packed);
}

/**
 * login_attempt_subject()
 * Normalises a typed identifier into the value stored as the
 * 'account' counter key.
 *
 * The lower-casing is a SECURITY requirement, not tidiness: the users
 * table is utf8mb4_unicode_ci, so `WHERE email = :identifier` matches
 * case-insensitively — "Alice@X.com" and "alice@x.com" are the same
 * account. If the counter did not fold case the same way, an attacker
 * would get a fresh, free counter for every capitalisation of the
 * address they were already guessing. (For the phone identifier this
 * is a no-op: digits have no case.)
 *
 * @param string $identifier Email or phone exactly as typed.
 * @return string The value used as the account counter key.
 */
function login_attempt_subject(string $identifier): string
{
    return strtolower(trim($identifier));
}

/**
 * login_attempt_keys()
 * The two counters one failed attempt is counted against.
 *
 * @param string      $identifier Email or phone exactly as typed.
 * @param string|null $ip         Client IP; the caller's when omitted.
 * @return array<int, array{0: string, 1: string}> [scope, subject] pairs.
 */
function login_attempt_keys(string $identifier, ?string $ip = null): array
{
    return [
        ['account', login_attempt_subject($identifier)],
        ['ip', $ip ?? login_attempt_client_ip()],
    ];
}

/**
 * login_attempt_streak()
 * The failure count behind ONE counter key, with the decay rule
 * applied: a streak that has been quiet for LOGIN_ATTEMPT_WINDOW is
 * reported as 0 no matter what the row says.
 *
 * @param PDO    $pdo     Live connection (see db.php).
 * @param string $scope   'account' or 'ip'.
 * @param string $subject The normalised key value.
 * @return array{failures: int, age: int} Count, and seconds since it was last added to.
 */
function login_attempt_streak(PDO $pdo, string $scope, string $subject): array
{
    $stmt = $pdo->prepare(
        'SELECT failures, UNIX_TIMESTAMP(last_failed_at) AS last_epoch
         FROM login_attempts
         WHERE scope = :scope AND subject = :subject
         LIMIT 1'
    );
    $stmt->execute([':scope' => $scope, ':subject' => $subject]);
    $row = $stmt->fetch();

    if (!$row) {
        // Nothing has ever failed for this key: age is "forever ago".
        return ['failures' => 0, 'age' => PHP_INT_MAX];
    }

    // UNIX_TIMESTAMP() on a TIMESTAMP column returns the real epoch —
    // it undoes the column's session-timezone conversion — so
    // comparing it with PHP's time() stays correct even when PHP and
    // MySQL are configured with different timezones.
    $age = max(0, time() - (int) $row['last_epoch']);

    if ($age > LOGIN_ATTEMPT_WINDOW) {
        return ['failures' => 0, 'age' => PHP_INT_MAX];   // stale: forgotten
    }

    return ['failures' => (int) $row['failures'], 'age' => $age];
}

/**
 * login_throttle_wait()
 * How many seconds this caller must wait before the identifier may be
 * tried again — the larger of what the account key and the IP key
 * have earned. 0 means the attempt may go ahead.
 *
 * The wait is measured from the LAST failure, so it runs down by
 * itself as the visitor waits: nothing has to be released, and a
 * visitor who comes back later is simply allowed through.
 *
 * @param PDO         $pdo        Live connection (see db.php).
 * @param string      $identifier Email or phone exactly as typed.
 * @param string|null $ip         Client IP; the caller's when omitted.
 *                                Passed explicitly by the smoke test so
 *                                it never touches a real visitor's counter.
 * @return int Seconds to wait (0 = allowed).
 */
function login_throttle_wait(PDO $pdo, string $identifier, ?string $ip = null): int
{
    $wait = 0;

    try {
        foreach (login_attempt_keys($identifier, $ip) as [$scope, $subject]) {
            $streak = login_attempt_streak($pdo, $scope, $subject);
            $wait   = max($wait, login_backoff_seconds($streak['failures']) - $streak['age']);
        }
    } catch (PDOException $e) {
        // FAIL-OPEN, as documented above the constants: if the table
        // cannot be read, log it and let the login proceed. Reporting
        // "too many attempts" here would lock out every visitor on a
        // database hiccup.
        error_log('islaFIND login throttle read failed: ' . $e->getMessage());
        return 0;
    }

    return max(0, $wait);
}

/**
 * login_throttle_record_failure()
 * Adds one failed attempt to BOTH counters (account and IP), so the
 * next attempt from either side waits longer.
 *
 * Call this only when the password was actually wrong. An attempt
 * that was refused by login_throttle_wait() is deliberately NOT
 * counted: otherwise an attacker could keep hammering a throttled
 * account and push the release further away every time, turning a
 * 15-minute backoff into a permanent lockout of somebody else.
 *
 * @param PDO         $pdo        Live connection (see db.php).
 * @param string      $identifier Email or phone exactly as typed.
 * @param string|null $ip         Client IP; the caller's when omitted.
 * @return void
 */
function login_throttle_record_failure(PDO $pdo, string $identifier, ?string $ip = null): void
{
    try {
        $cutoff = time() - LOGIN_ATTEMPT_WINDOW;   // epoch below which a streak is stale

        // One upsert per key: insert the first failure, or add to the
        // streak already there.
        //
        // The ORDER of the SET clauses is load-bearing. MySQL applies
        // them left to right and later clauses see the NEW values, so
        // `last_failed_at = NOW()` must come LAST: both IFs above it
        // ask whether the streak has gone stale, and they have to read
        // the PREVIOUS last_failed_at to answer that.
        $stmt = $pdo->prepare(
            'INSERT INTO login_attempts (scope, subject, failures, first_failed_at, last_failed_at)
             VALUES (:scope, :subject, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                failures        = IF(UNIX_TIMESTAMP(last_failed_at) < :cutoff_a, 1, failures + 1),
                first_failed_at = IF(UNIX_TIMESTAMP(last_failed_at) < :cutoff_b, NOW(), first_failed_at),
                last_failed_at  = NOW()'
        );

        foreach (login_attempt_keys($identifier, $ip) as [$scope, $subject]) {
            $stmt->execute([
                ':scope'    => $scope,
                ':subject'  => $subject,
                ':cutoff_a' => $cutoff,
                ':cutoff_b' => $cutoff,
            ]);
        }

        // Keep the table from growing without bound: a bot that sprays
        // random identifiers adds one row per guess, and nothing else
        // would ever clean them up. Rows are dropped only long after
        // their streak has decayed (LOGIN_ATTEMPT_TTL_DAYS), and only
        // a hundred at a time, so this stays a cheap indexed delete on
        // the failure path. The interval is a compile-time constant,
        // never anything a visitor sent.
        $pdo->exec(
            'DELETE FROM login_attempts
              WHERE last_failed_at < DATE_SUB(NOW(), INTERVAL ' . (int) LOGIN_ATTEMPT_TTL_DAYS . ' DAY)
              LIMIT 100'
        );
    } catch (PDOException $e) {
        // FAIL-OPEN: a counter that cannot be written must not turn a
        // successful sign-in into an error page.
        error_log('islaFIND login throttle write failed: ' . $e->getMessage());
    }
}

/**
 * login_throttle_clear()
 * Forgets the streak for both keys. Called once the password has been
 * proved correct, so a user who mistyped a few times and then got it
 * right starts clean.
 *
 * It runs BEFORE the account is checked for verification/MFA on
 * purpose: those branches still prove the password, so a user who is
 * about to be asked for an emailed code must not also be sitting in a
 * backoff.
 *
 * @param PDO         $pdo        Live connection (see db.php).
 * @param string      $identifier Email or phone exactly as typed.
 * @param string|null $ip         Client IP; the caller's when omitted.
 * @return void
 */
function login_throttle_clear(PDO $pdo, string $identifier, ?string $ip = null): void
{
    try {
        $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE scope = :scope AND subject = :subject');

        foreach (login_attempt_keys($identifier, $ip) as [$scope, $subject]) {
            $stmt->execute([':scope' => $scope, ':subject' => $subject]);
        }
    } catch (PDOException $e) {
        // FAIL-OPEN, same rule as everywhere else here: a correct
        // password is never held up by bookkeeping.
        error_log('islaFIND login throttle clear failed: ' . $e->getMessage());
    }
}
