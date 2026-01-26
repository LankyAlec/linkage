<?php
// functions.php
if (session_status() === PHP_SESSION_NONE) session_start();

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function flash(string $key, ?string $val = null) {
    if ($val === null) {
        $v = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $v;
    } else {
        $_SESSION['flash'][$key] = $val;
    }
}


function is_logged_in(): bool { return !empty($_SESSION['id_user']); }
function require_login() {
    if (!is_logged_in()) { header('Location: login.php'); exit; }
}
