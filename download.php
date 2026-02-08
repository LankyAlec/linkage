<?php
session_start();
include 'header.php';

// AUTH
if (empty($_SESSION['login'])) {
    http_response_code(403);
    exit('Forbidden');
}

$USER_ID = (int)($_SESSION['id_user'] ?? 0);
$BASE = __DIR__ . '/risultati/' . $USER_ID;

// Parametri
$run  = basename((string)($_GET['run'] ?? ''));
$file = basename((string)($_GET['file'] ?? ''));

if ($run === '' || $file === '') {
    http_response_code(400);
    exit('Bad request');
}

$runDir = $BASE . '/' . $run;

// File consentiti: input (root), output (root), estratti (files/)
$candidates = [
    $runDir . '/' . $file,
    $runDir . '/files/' . $file,
];

$target = null;
foreach ($candidates as $p) {
    if (is_file($p)) { $target = $p; break; }
}

if (!$target) {
    http_response_code(404);
    exit('Not found');
}

// Safety: realpath dentro runDir
$rp = realpath($target);
$rpRun = realpath($runDir);
if (!$rp || !$rpRun || strncmp($rp, $rpRun, strlen($rpRun)) !== 0) {
    http_response_code(403);
    exit('Forbidden');
}

// Mime basic
$mime = 'application/octet-stream';
if (class_exists('finfo')) {
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $m = $fi->file($rp);
    if ($m) $mime = $m;
}

$filename = basename($rp);

// Header download (attachment)
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($rp));
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');

readfile($rp);
exit;
