<?php
// ============================================================
// assets.php — cache-busting for the app's stylesheets and scripts
//
// A URL like `style.css` is cached by the browser, and a phone that
// keeps the app open — or restores it from a back/forward cache — can
// paint the copy it saved before a change nobody sees until they
// reload. Appending the file's modification time (`style.css?v=...`)
// gives the browser something concrete to compare, so the new file is
// fetched the moment it changes and the old one stays cached while it
// does not.
//
// WHY THIS EXISTS even though public/.htaccess already sends
// "Expires: 0 seconds" for CSS and JS: a zero expiry tells the browser
// to revalidate, but it is a hint, not a guarantee, and the caches
// that skip it are exactly the ones a phone relies on. The stamp is
// the belt to that pair of braces.
//
// One primitive, two entry points: isla_asset_stamp() does the work
// and the thin wrappers below name the directory each set of pages
// links from — public/ for the member app, public/admin/ for the
// panel (see include/admin_auth.php). Both resolve from a directory
// rather than from the calling file, so the href can stay exactly as
// it is written in the page.
// ============================================================

/**
 * isla_asset_stamp(string $href, string $baseDir): string
 * Append `?v=<filemtime>` to an href resolved under $baseDir. A file
 * that cannot be read is returned untouched, so a stale or missing
 * asset never takes a page down over its version stamp.
 *
 * @param string $href    URL as written in the page, e.g. 'style.css'.
 * @param string $baseDir Absolute directory the href is relative to.
 * @return string The href, stamped when the file could be read.
 */
function isla_asset_stamp(string $href, string $baseDir): string
{
    $file = rtrim($baseDir, '/\\') . '/' . $href;

    if (!is_file($file)) {
        return $href;
    }

    // ?v=<mtime> rather than a random or build-hash value: the URL only
    // changes when the file does, so an unchanged asset stays cached.
    return $href . '?v=' . filemtime($file);
}

/**
 * asset_url(string $href): string
 * The member app's version: hrefs are written relative to public/,
 * which is where every member page is served from.
 *
 * @param string $href URL as written in the page, e.g. 'style.css'.
 * @return string The href with ?v=<mtime> appended.
 */
function asset_url(string $href): string
{
    return isla_asset_stamp($href, __DIR__ . '/../public');
}
