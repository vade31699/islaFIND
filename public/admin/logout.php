<?php
// ============================================================
// admin/logout.php — sign the superadmin out
//
// POST + CSRF on purpose. A logout link would be a GET, and a GET that
// changes state is a link any page on the internet could put in an
// <img> to sign an admin out at a convenient moment.
//
// Only the admin identity is dropped: a member signed in on the same
// browser keeps their own login, because the two sessions are separate
// (see include/admin_auth.php).
// ============================================================

require_once __DIR__ . '/../../include/security.php';
session_harden();
session_start();

require_once __DIR__ . '/../../include/db.php';
require_once __DIR__ . '/../../include/admin_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    header('Location: ' . sid_append('index.php'));
    exit;
}

admin_forget();

header('Location: ' . sid_append('../login.php'));
exit;