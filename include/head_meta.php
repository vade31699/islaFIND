<?php
// ============================================================
// head_meta.php — Shared <head> metadata (layout partial)
// One include, so every page ships the SAME document metadata:
// title, meta description, favicon, PWA manifest, mobile hints
// and the social-preview (Open Graph / Twitter) tags.
//
// Set these BEFORE including it:
//   $headTitle — text after the "islaFIND — " prefix
//                (defaults to a generic island-services title)
//   $headDesc  — one-line description used by search engines
//                and link previews (optional)
// Page-specific <script> tags still belong to the page itself and
// can be added AFTER this include (e.g. profile_script.js).
//
// The include prints the stylesheet + busy.js too, because every
// page loads exactly those two files first — keeping them here
// means a new page cannot forget them.
// ============================================================

$headTitle = isset($headTitle) && $headTitle !== ''
    ? $headTitle
    : 'Bantayan Island services';
$headDesc = isset($headDesc) && $headDesc !== ''
    ? $headDesc
    : 'islaFIND connects Bantayan Island residents and visitors with local service providers — mechanics, electricians, resorts, rentals and more.';

// Escaped once, used several times below.
$headTitleEsc = htmlspecialchars($headTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$headDescEsc  = htmlspecialchars($headDesc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>islaFIND — <?php echo $headTitleEsc; ?></title>
    <meta name="description" content="<?php echo $headDescEsc; ?>">

    <!-- Brand color for the browser UI (mobile address bar, tab group) -->
    <meta name="theme-color" content="#0077B6">
    <meta name="color-scheme" content="light">

    <!-- Icons + installable-app manifest. The logo IS the favicon:
         SVG stays crisp at every size and needs no extra files. -->
    <link rel="icon" type="image/svg+xml" href="img/isla_logo.svg">
    <link rel="manifest" href="manifest.webmanifest">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="islaFIND">

    <!-- Social / link previews (Open Graph + Twitter card) -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="islaFIND">
    <meta property="og:title" content="<?php echo $headTitleEsc; ?>">
    <meta property="og:description" content="<?php echo $headDescEsc; ?>">
    <meta property="og:image" content="img/isla_logo.svg">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?php echo $headTitleEsc; ?>">
    <meta name="twitter:description" content="<?php echo $headDescEsc; ?>">

    <link rel="stylesheet" href="style.css">
    <script src="busy.js"></script>
