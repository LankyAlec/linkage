<?php
session_start();
include 'header.php';

// =========================
// AUTH
// =========================
if (empty($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}
$USER_ID = (int)($_SESSION['id_user'] ?? 0);

// =========================
// CSRF
// =========================
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// =========================
// CONFIG
// =========================
$PYTHON       = "/volume1/web/avvocati/venv/bin/python";
$SCRIPT       = __DIR__ . "/py/linka_normattiva.py";
$URN_INDEX    = __DIR__ . "/py/urn_index.json";
$RESULTS_BASE = __DIR__ . "/risultati/" . $USER_ID;

if (!is_dir($RESULTS_BASE)) {
    @mkdir($RESULTS_BASE, 0775, true);
}

// =========================
// DB
// =========================
$hasDb = (isset($connection) && $connection instanceof mysqli);

// =========================
// HELPERS
// =========================
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function flash_and_redirect(string $type, string $msg, string $qs = ''): void {
    $_SESSION['flash_tipo'] = $type;
    $_SESSION['flash_msg']  = $msg;
    header('Location: linkage.php' . ($qs ? ('?' . $qs) : ''));
    exit;
}

function rm_rf_dir(string $dir): bool {
    if (!is_dir($dir)) return false;

    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($rii as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    return @rmdir($dir);
}

function db_has_column(mysqli $conn, string $table, string $column): bool {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $column);
    $q = mysqli_query($conn, "SHOW COLUMNS FROM `$t` LIKE '$c'");
    return ($q && mysqli_num_rows($q) > 0);
}

$hasColOriginal = $hasDb ? db_has_column($connection, 'linkage_results', 'original_name') : false;
$hasColLinks    = $hasDb ? db_has_column($connection, 'linkage_results', 'links_found') : false;

/**
 * Ignora file "spazzatura" tipici Mac/Office e path indesiderati
 */
function is_junk_zip_entry(string $pathInZip): bool {
    $pathInZip = str_replace('\\', '/', $pathInZip);
    $bn = basename($pathInZip);

    if ($bn === '' || $bn === '.' || $bn === '..') return true;
    if ($bn === '.DS_Store') return true;
    if (str_starts_with($bn, '._')) return true;
    if (str_starts_with($bn, '~$')) return true;
    if (str_starts_with($pathInZip, '__MACOSX/')) return true;

    return false;
}

/**
 * Badge status + link "In attesa" che apre modale (via step=choose&run=...)
 */
function badge_status(int $rc, bool $hasOutput, string $dirName): string {
    if ($rc === 100) {
        $href = 'linkage.php?step=choose&run=' . rawurlencode($dirName);
        return '<a class="badge rounded-pill text-bg-warning text-decoration-none" href="'.h($href).'" title="Scegli il DOCX da elaborare">'
             . '<i class="bi bi-hourglass-split me-1"></i>In attesa</a>';
    }
    if ($rc === 0 && $hasOutput) {
        return '<span class="badge rounded-pill text-bg-success"><i class="bi bi-check2-circle me-1"></i>OK</span>';
    }
    return '<span class="badge rounded-pill text-bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>Errore</span>';
}

function normalize_display_name(string $name): string {
    $name = trim($name);
    $name = preg_replace('/\s+/', ' ', $name);
    return mb_substr($name, 0, 180);
}

function safe_filename(string $name): string {
    $name = basename((string)$name);
    $name = trim($name);
    if ($name === '') return 'file';

    $name = preg_replace('/\s+/', ' ', $name);
    $name = preg_replace('/[^A-Za-z0-9\.\-\_\s]/u', '_', $name);
    $name = str_replace(' ', '_', $name);

    if (function_exists('mb_substr')) $name = mb_substr($name, 0, 180);
    else $name = substr($name, 0, 180);

    if ($name === '' || $name === '.' || $name === '..') $name = 'file';
    return $name;
}

function uniq_name_in_dir(string $dir, string $filename): string {
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $ext  = pathinfo($filename, PATHINFO_EXTENSION);
    $ext  = $ext ? ('.' . $ext) : '';

    $candidate = $filename;
    $i = 2;
    while (is_file($dir . '/' . $candidate)) {
        $candidate = $base . '_' . $i . $ext;
        $i++;
    }
    return $candidate;
}

/**
 * Ricava data/ora:
 * - se created_at è DATETIME -> usa quello
 * - se created_at è DATE -> usa data da created_at + ora dal nome cartella
 * - fallback -> dal nome cartella
 */
function extract_date_time(string $createdAt, string $dirName): array {
    $createdAt = trim($createdAt);

    if ($createdAt && strlen($createdAt) >= 19) {
        $ts = strtotime($createdAt);
        if ($ts) {
            return ['date' => date('d/m/Y', $ts), 'time' => date('H:i', $ts)];
        }
    }

    $dateFromCreated = '';
    if ($createdAt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdAt)) {
        $ts = strtotime($createdAt . ' 00:00:00');
        if ($ts) $dateFromCreated = date('d/m/Y', $ts);
    }

    if (preg_match('/^(\d{4})_(\d{2})_(\d{2})_(\d{2})_(\d{2})_(\d{2})$/', $dirName, $m)) {
        $ts = mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
        return [
            'date' => $dateFromCreated ?: date('d/m/Y', $ts),
            'time' => date('H:i', $ts),
        ];
    }

    $ts = strtotime($createdAt);
    if ($ts) return ['date' => date('d/m/Y', $ts), 'time' => date('H:i', $ts)];
    return ['date' => '—', 'time' => '—'];
}

/**
 * UPSERT su linkage_results (INSERT se non esiste, altrimenti UPDATE)
 * Scrive errori DB in exec.log (così capisci subito perché non aggiorna)
 */
function db_upsert_result(mysqli $conn, int $USER_ID, string $runDirRel, int $rc, string $origNameDisplay, int $linksFound, bool $hasColOriginal, bool $hasColLinks, string $logPathAbs): void {
    $dirEsc = mysqli_real_escape_string($conn, $runDirRel);
    $rcInt  = (int)$rc;
    $nameEsc = mysqli_real_escape_string($conn, $origNameDisplay);

    $q = mysqli_query($conn, "SELECT id FROM linkage_results WHERE id_user=$USER_ID AND path_rel='$dirEsc' LIMIT 1");
    $exists = ($q && mysqli_num_rows($q) > 0);

    if ($exists) {
        $sets = ["last_rc=$rcInt"];
        if ($hasColOriginal) $sets[] = "original_name='$nameEsc'";
        if ($hasColLinks)    $sets[] = "links_found=" . (int)$linksFound;

        $sql = "UPDATE linkage_results SET " . implode(", ", $sets) . " WHERE id_user=$USER_ID AND path_rel='$dirEsc'";
        $ok = mysqli_query($conn, $sql);
        if (!$ok) file_put_contents($logPathAbs, "\n[DB_ERROR_UPDATE] " . mysqli_error($conn) . "\n[DB_SQL] $sql\n", FILE_APPEND);
        return;
    }

    $cols = ["id_user", "created_at", "path_rel", "last_rc"];
    $vals = ["$USER_ID", "NOW()", "'$dirEsc'", "$rcInt"];

    if ($hasColOriginal) { $cols[] = "original_name"; $vals[] = "'$nameEsc'"; }
    if ($hasColLinks)    { $cols[] = "links_found";   $vals[] = (string)(int)$linksFound; }

    $sql = "INSERT INTO linkage_results (" . implode(",", $cols) . ") VALUES (" . implode(",", $vals) . ")";
    $ok = mysqli_query($conn, $sql);
    if (!$ok) file_put_contents($logPathAbs, "\n[DB_ERROR_INSERT] " . mysqli_error($conn) . "\n[DB_SQL] $sql\n", FILE_APPEND);
}

// =========================
// STEP ROUTING
// =========================
$step = (string)($_GET['step'] ?? '');
$run  = basename((string)($_GET['run'] ?? ''));

// =========================
// ACTION: UPLOAD
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {

    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        flash_and_redirect('err', 'Richiesta non valida (CSRF).');
    }

    $uploadField = null;
    if (isset($_FILES['doc'])) $uploadField = 'doc';
    elseif (isset($_FILES['filepond'])) $uploadField = 'filepond';
    else flash_and_redirect('err', 'Nessun file ricevuto.');

    $f = $_FILES[$uploadField];
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        flash_and_redirect('err', 'Caricamento fallito (errore upload).');
    }

    $origNameDisplay = normalize_display_name((string)($f['name'] ?? 'file'));
    $origNameFs      = safe_filename((string)($f['name'] ?? 'file'));

    $ext = strtolower(pathinfo($origNameFs, PATHINFO_EXTENSION));
    if (!in_array($ext, ['zip', 'docx'], true)) {
        flash_and_redirect('err', 'Sono accettati solo file .docx o .zip');
    }

    if (!is_file($PYTHON))    flash_and_redirect('err', "Python non trovato: $PYTHON");
    if (!is_file($SCRIPT))    flash_and_redirect('err', "Script non trovato: $SCRIPT");
    if (!is_file($URN_INDEX)) flash_and_redirect('err', "urn_index.json non trovato: $URN_INDEX");

    $ts = date('Y_m_d_H_i_s');
    $runDir = $RESULTS_BASE . '/' . $ts;
    @mkdir($runDir, 0775, true);

    $log = $runDir . '/exec.log';
    $runDirRel = 'risultati/' . $USER_ID . '/' . $ts;

    // salva input con nome originale (safe)
    $origNameFs = uniq_name_in_dir($runDir, $origNameFs);
    $inputPath = $runDir . '/' . $origNameFs;

    if (!move_uploaded_file($f['tmp_name'], $inputPath)) {
        flash_and_redirect('err', 'Impossibile salvare il file caricato.');
    }

    // =========================
    // ZIP: estrai in /files, fai scegliere docx se >1
    // =========================
    if ($ext === 'zip') {

        if (!class_exists('ZipArchive')) {
            file_put_contents($log, "[RC] 98\n[ERROR] Estensione PHP zip (ZipArchive) non disponibile.\n");
            if ($hasDb) db_upsert_result($connection, $USER_ID, $runDirRel, 98, $origNameDisplay, 0, $hasColOriginal, $hasColLinks, $log);
            flash_and_redirect('err', 'ZipArchive non disponibile sul server. Abilita l’estensione PHP "zip".');
        }

        $filesDir = $runDir . '/files';
        @mkdir($filesDir, 0775, true);

        $zip = new ZipArchive();
        $docxList = [];
        $allFiles = []; // [{orig, saved, ext}]
        $rcZip = $zip->open($inputPath);

        if ($rcZip !== true) {
            file_put_contents($log, "[RC] 97\n[ERROR] Impossibile aprire lo zip.\n");
            if ($hasDb) db_upsert_result($connection, $USER_ID, $runDirRel, 97, $origNameDisplay, 0, $hasColOriginal, $hasColLinks, $log);
            flash_and_redirect('err', 'Impossibile aprire lo zip.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $name = $st['name'] ?? '';
            if (!$name) continue;

            // skip directories
            if (substr($name, -1) === '/') continue;

            // BONUS: skip junk
            if (is_junk_zip_entry($name)) continue;

            $origInside = basename(str_replace('\\', '/', $name));
            if ($origInside === '') continue;

            $saved = safe_filename($origInside);
            $saved = uniq_name_in_dir($filesDir, $saved);

            // estrai nel runDir mantenendo path, poi flatten
            $zip->extractTo($runDir, [$name]);
            $extractedPath = $runDir . '/' . str_replace('\\', '/', $name);

            // flatten se serve
            if (!is_file($extractedPath)) {
                $tmp = $runDir . '/' . basename($name);
                if (is_file($tmp)) $extractedPath = $tmp;
            }

            if (is_file($extractedPath)) {
                $dest = $filesDir . '/' . $saved;
                @rename($extractedPath, $dest);

                // cleanup sottocartelle (best effort)
                $maybeDir = dirname($runDir . '/' . str_replace('\\', '/', $name));
                if ($maybeDir !== $runDir && is_dir($maybeDir)) @rmdir($maybeDir);

                $e = strtolower(pathinfo($saved, PATHINFO_EXTENSION));
                $allFiles[] = ['orig' => $origInside, 'saved' => $saved, 'ext' => $e];

                if ($e === 'docx') $docxList[] = $saved;
            }
        }
        $zip->close();

        // BONUS: filtra ulteriormente docxList (sicurezza)
        $docxList = array_values(array_filter($docxList, function($f){
            $bn = basename($f);
            if (str_starts_with($bn, '._')) return false;
            if (str_starts_with($bn, '~$')) return false;
            return true;
        }));
        // ordina alfabetico
        natcasesort($docxList);
        $docxList = array_values($docxList);

        // salva manifest
        $manifest = [
            'uploaded_original' => $origNameDisplay,
            'uploaded_saved'    => basename($inputPath),
            'files'             => $allFiles,
            'docx'              => $docxList,
            'main_docx'         => null,
        ];
        file_put_contents($runDir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // DB "pending" (rc=100)
        file_put_contents($log, "[RC] 100\n[PENDING] Attesa scelta DOCX\n");
        if ($hasDb) db_upsert_result($connection, $USER_ID, $runDirRel, 100, $origNameDisplay, 0, $hasColOriginal, $hasColLinks, $log);

        if (count($docxList) === 0) {
            file_put_contents($log, "\n[ERROR] Nessun DOCX nello zip.\n", FILE_APPEND);
            if ($hasDb) db_upsert_result($connection, $USER_ID, $runDirRel, 99, $origNameDisplay, 0, $hasColOriginal, $hasColLinks, $log);
            flash_and_redirect('err', 'Lo .zip non contiene alcun file .docx.');
        }

        // se 1 docx: processa subito
        if (count($docxList) === 1) {
            $_POST = [
                'action' => 'process_zip',
                'csrf' => $csrf,
                'run' => $ts,
                'main_docx' => $docxList[0],
            ];
            // fall-through: proseguirà sotto nel blocco process_zip
        } else {
            // scelta utente: apri modale
            flash_and_redirect('ok', 'Seleziona il DOCX principale da elaborare.', 'step=choose&run=' . urlencode($ts));
        }
    }

    // =========================
    // DOCX diretto: processa subito
    // =========================
    if ($ext === 'docx') {
        $docxPathAbs = $inputPath;

        $base = pathinfo(basename($docxPathAbs), PATHINFO_FILENAME);
        $out  = $runDir . '/linked_' . safe_filename($base) . '.docx';

        $cmd = escapeshellarg($PYTHON) . " " .
               escapeshellarg($SCRIPT) . " " .
               escapeshellarg($docxPathAbs) . " " .
               escapeshellarg($out) . " " .
               escapeshellarg($URN_INDEX) . " 2>&1";

        $cmdOut = [];
        $rc = 0;
        exec($cmd, $cmdOut, $rc);

        $linksCreated = 0;
        foreach ($cmdOut as $line) {
            if (preg_match('/^LINKS_CREATED=(\d+)/', trim($line), $m)) { $linksCreated = (int)$m[1]; break; }
        }

        $logTxt =
            "==============================\n" .
            "[WHEN] " . date('c') . "\n" .
            "==============================\n" .
            "[ORIGINAL_NAME] $origNameDisplay\n" .
            "[INPUT_SAVED_AS] " . basename($inputPath) . "\n" .
            "[DOCX_USED] " . basename($docxPathAbs) . "\n" .
            "[LINKS_CREATED] $linksCreated\n" .
            "[CMD] $cmd\n\n" .
            "---------- OUTPUT ----------\n" . implode("\n", $cmdOut) . "\n" .
            "----------------------------\n\n" .
            "[RC] $rc\n";
        file_put_contents($log, $logTxt);

        if ($hasDb) db_upsert_result($connection, $USER_ID, $runDirRel, $rc, $origNameDisplay, $linksCreated, $hasColOriginal, $hasColLinks, $log);

        if ($rc === 0 && is_file($out)) flash_and_redirect('ok', 'Elaborazione completata con successo.');
        flash_and_redirect('err', 'Elaborazione conclusa con errori. Apri il log per i dettagli.');
    }

    // se siamo qui: ZIP con 1 docx e abbiamo forzato $_POST process_zip → continua
}

// =========================
// ACTION: PROCESS ZIP (dopo scelta docx)
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_zip') {

    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        flash_and_redirect('err', 'Richiesta non valida (CSRF).');
    }

    $run = basename((string)($_POST['run'] ?? ''));
    $main = basename((string)($_POST['main_docx'] ?? ''));

    if ($run === '' || $main === '') flash_and_redirect('err', 'Selezione non valida.');

    $runDir = $RESULTS_BASE . '/' . $run;
    $runDirRel = 'risultati/' . $USER_ID . '/' . $run;
    $log = $runDir . '/exec.log';

    $manifestPath = $runDir . '/manifest.json';
    if (!is_file($manifestPath)) flash_and_redirect('err', 'Manifest non trovato.');

    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($manifest)) flash_and_redirect('err', 'Manifest non valido.');

    $filesDir = $runDir . '/files';
    $docxPathAbs = $filesDir . '/' . $main;

    if (!is_file($docxPathAbs)) flash_and_redirect('err', 'DOCX selezionato non trovato.');

    $origNameDisplay = (string)($manifest['uploaded_original'] ?? 'file.zip');

    // filemap: tutti i file tranne main docx (e tranne junk)
    $filemap = [];
    $files = $manifest['files'] ?? [];
    foreach ($files as $it) {
        $saved = (string)($it['saved'] ?? '');
        $orig  = (string)($it['orig'] ?? $saved);
        if (!$saved) continue;
        if ($saved === $main) continue;

        // extra safety: ignora junk
        if (is_junk_zip_entry($orig)) continue;

        $url = 'download.php?run=' . rawurlencode($run) . '&file=' . rawurlencode($saved);
        $filemap[] = ['name' => $orig, 'url' => $url];
    }

    $filemapPath = $runDir . '/filemap.json';
    file_put_contents($filemapPath, json_encode($filemap, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $base = pathinfo(basename($docxPathAbs), PATHINFO_FILENAME);
    $out  = $runDir . '/linked_' . safe_filename($base) . '.docx';

    // python: input output urn_index filemap
    $cmd = escapeshellarg($PYTHON) . " " .
           escapeshellarg($SCRIPT) . " " .
           escapeshellarg($docxPathAbs) . " " .
           escapeshellarg($out) . " " .
           escapeshellarg($URN_INDEX) . " " .
           escapeshellarg($filemapPath) . " 2>&1";

    $cmdOut = [];
    $rc = 0;
    exec($cmd, $cmdOut, $rc);

    $linksCreated = 0;
    foreach ($cmdOut as $line) {
        if (preg_match('/^LINKS_CREATED=(\d+)/', trim($line), $m)) { $linksCreated = (int)$m[1]; break; }
    }

    // aggiorna manifest main_docx
    $manifest['main_docx'] = $main;
    file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $logTxt =
        "==============================\n" .
        "[WHEN] " . date('c') . "\n" .
        "==============================\n" .
        "[ORIGINAL_NAME] $origNameDisplay\n" .
        "[ZIP_SAVED_AS] " . (string)($manifest['uploaded_saved'] ?? 'input.zip') . "\n" .
        "[DOCX_USED] " . basename($docxPathAbs) . "\n" .
        "[LINKS_CREATED] $linksCreated\n" .
        "[CMD] $cmd\n\n" .
        "---------- OUTPUT ----------\n" . implode("\n", $cmdOut) . "\n" .
        "----------------------------\n\n" .
        "[RC] $rc\n";
    file_put_contents($log, $logTxt);

    if ($hasDb) db_upsert_result($connection, $USER_ID, $runDirRel, $rc, $origNameDisplay, $linksCreated, $hasColOriginal, $hasColLinks, $log);

    if ($rc === 0 && is_file($out)) flash_and_redirect('ok', 'Elaborazione completata con successo.');
    flash_and_redirect('err', 'Elaborazione conclusa con errori. Apri il log per i dettagli.');
}

// =========================
// ACTION: DELETE
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {

    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        flash_and_redirect('err', 'Richiesta non valida (CSRF).');
    }

    $dir = basename((string)($_POST['dir'] ?? ''));
    if (!$dir) flash_and_redirect('err', 'Elemento non valido.');

    $target = $RESULTS_BASE . '/' . $dir;
    if (!is_dir($target)) flash_and_redirect('err', 'Cartella non trovata.');

    rm_rf_dir($target);

    if ($hasDb) {
        $dirRel = 'risultati/' . $USER_ID . '/' . $dir;
        $dirRelEsc = mysqli_real_escape_string($connection, $dirRel);
        mysqli_query($connection, "DELETE FROM linkage_results WHERE id_user=$USER_ID AND path_rel='$dirRelEsc'");
    }

    flash_and_redirect('ok', 'Risultato eliminato.');
}

// =========================
// LISTING (paginato)
// =========================
$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$rows = [];
$total = 0;

if ($hasDb) {
    $qTot = mysqli_query($connection, "SELECT COUNT(*) AS c FROM linkage_results WHERE id_user=$USER_ID");
    if ($qTot) $total = (int)(mysqli_fetch_assoc($qTot)['c'] ?? 0);

    $q = mysqli_query($connection, "SELECT * FROM linkage_results WHERE id_user=$USER_ID ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
    if ($q) while ($r = mysqli_fetch_assoc($q)) $rows[] = $r;
} else {
    $entries = [];
    if (is_dir($RESULTS_BASE)) {
        foreach (array_diff(scandir($RESULTS_BASE), ['.', '..']) as $d) {
            if (is_dir($RESULTS_BASE . '/' . $d)) $entries[] = $d;
        }
        rsort($entries);
        $total = count($entries);
        $slice = array_slice($entries, $offset, $perPage);

        foreach ($slice as $d) {
            $rows[] = [
                'created_at' => '',
                'path_rel'   => 'risultati/' . $USER_ID . '/' . $d,
                'last_rc'    => 999,
                'original_name' => '—',
                'links_found' => 0,
            ];
        }
    }
}

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);

// KPI
$okCount = 0; $errCount = 0;
foreach ($rows as $r) {
    $pathRel = (string)($r['path_rel'] ?? '');
    $absDir  = __DIR__ . '/' . $pathRel;

    $rc = (int)($r['last_rc'] ?? 999);
    $outputFiles = glob($absDir . '/linked_*.docx');
    $hasOutput = ($outputFiles && is_file($outputFiles[0]));

    if ($rc === 0 && $hasOutput) $okCount++;
    elseif ($rc !== 100) $errCount++;
}

// =========================
// STEP: CHOOSE (render)
// =========================
$chooseRun = null;
$chooseDocx = [];
if ($step === 'choose' && $run) {
    $chooseRun = $run;
    $runDir = $RESULTS_BASE . '/' . $run;
    $manifestPath = $runDir . '/manifest.json';
    if (is_file($manifestPath)) {
        $m = json_decode((string)file_get_contents($manifestPath), true);
        if (is_array($m) && !empty($m['docx']) && is_array($m['docx'])) {
            // BONUS: filtra di nuovo
            $chooseDocx = array_values(array_filter($m['docx'], function($f){
                $bn = basename((string)$f);
                if ($bn === '' || $bn === '.DS_Store') return false;
                if (str_starts_with($bn, '._')) return false;
                if (str_starts_with($bn, '~$')) return false;
                return true;
            }));
            natcasesort($chooseDocx);
            $chooseDocx = array_values($chooseDocx);
        }
    }
}
?>

<style>
.linkage-hero{
  background:
    radial-gradient(1100px 420px at 12% 0%, rgba(13,110,253,.16), transparent 55%),
    radial-gradient(900px 360px at 88% 18%, rgba(32,201,151,.16), transparent 55%),
    linear-gradient(180deg, rgba(255,255,255,.70), rgba(255,255,255,.94));
  border: 1px solid rgba(0,0,0,.06);
  border-radius: 18px;
}
.kpi{
  border: 1px solid rgba(0,0,0,.06);
  border-radius: 16px;
  background: #fff;
  padding: 18px 18px;
  min-height: 138px;
  display: flex;
  flex-direction: column;
  justify-content: center;
}
.kpi .num{
  font-size: 1.8rem;
  font-weight: 900;
  letter-spacing: -0.03em;
  margin-top: 4px;
}
.kpi .text-muted{ font-size: .95rem; }
.table thead th{
  font-size: .82rem;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: #6c757d;
}
.doc-icons{ display:flex; gap:.5rem; align-items:center; }
.icon-btn{
  width: 40px; height: 36px;
  display:inline-flex; align-items:center; justify-content:center;
  border-radius: 10px; border: 1px solid rgba(0,0,0,.10);
  background: #fff; text-decoration:none;
}
.icon-btn:hover{ background: rgba(0,0,0,.03); }
.icon-btn.disabled{ opacity:.35; pointer-events:none; }
.name-cell{
  max-width: 420px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 mb-1">Linkage <span class="text-muted">(Word → URN Normattiva + link allegati ZIP)</span></h1>
    <div class="text-muted small">
      Carica un <strong>.docx</strong> oppure un <strong>.zip</strong>.
      Se nello zip ci sono più DOCX, scegli quale elaborare; gli altri file diventano link “intelligenti” nel testo.
    </div>
  </div>
</div>

<?php
if (!empty($_SESSION['flash_msg'])) {
    $ok = (($_SESSION['flash_tipo'] ?? '') === 'ok');
    $classe = $ok ? 'alert-success' : 'alert-danger';
    $icona  = $ok ? 'bi-check2-circle' : 'bi-exclamation-triangle';
    echo "<div id='autoAlert' class='alert $classe d-flex align-items-center gap-2 shadow-sm'>
            <i class='bi $icona'></i>
            <div>" . h($_SESSION['flash_msg']) . "</div>
          </div>";
    unset($_SESSION['flash_msg'], $_SESSION['flash_tipo']);
}
?>

<div class="linkage-hero p-3 p-md-4 mb-4 shadow-sm">
  <div class="row g-3 align-items-end">
    <div class="col-lg-7">
      <div class="fw-semibold mb-2"><i class="bi bi-cloud-upload me-1"></i> Carica file</div>

      <form method="post" enctype="multipart/form-data" class="d-flex flex-column gap-2">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="upload">

        <input id="zipUpload" required class="form-control" type="file" name="doc" accept=".docx,.zip">

        <div class="d-flex gap-2">
          <button class="btn btn-primary d-inline-flex align-items-center gap-2">
            <i class="bi bi-magic"></i> Elabora
          </button>
        </div>
      </form>
    </div>

    <div class="col-lg-5">
      <div class="row g-2">
        <div class="col-4">
          <div class="kpi shadow-sm">
            <div class="text-muted small">Totale</div>
            <div class="num"><?= (int)$total ?></div>
          </div>
        </div>
        <div class="col-4">
          <div class="kpi p-3 shadow-sm">
            <div class="text-muted small">OK</div>
            <div class="num text-success"><?= (int)$okCount ?></div>
          </div>
        </div>
        <div class="col-4">
          <div class="kpi p-3 shadow-sm">
            <div class="text-muted small">Errori</div>
            <div class="num text-danger"><?= (int)$errCount ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="d-flex align-items-center justify-content-between mb-2">
  <h2 class="h6 text-uppercase text-muted mb-0">I tuoi risultati</h2>
  <div class="small text-muted">exec.log visibile solo se “Errore”</div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <?php if (!$rows): ?>
      <div class="text-muted">Nessun risultato.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width: 130px;">Data</th>
              <th style="width: 90px;">Ora</th>
              <th>Nome</th>
              <th style="width: 120px;">Link inseriti</th>
              <th style="width: 220px;">Documenti</th>
              <th style="width: 120px;">Stato</th>
              <th style="width: 110px;" class="text-end">Azioni</th>
            </tr>
          </thead>

          <tbody>
          <?php foreach ($rows as $r):
              $pathRel  = (string)($r['path_rel'] ?? '');
              $absDir   = __DIR__ . '/' . $pathRel;

              $rc = (int)($r['last_rc'] ?? 999);
              $createdAt = (string)($r['created_at'] ?? '');
              $dirName = basename($pathRel);
              $dt = extract_date_time($createdAt, $dirName);

              // input file (root): primo zip/docx trovato
              $inputFiles = glob($absDir . '/*.{zip,docx}', GLOB_BRACE);
              $hasInput   = ($inputFiles && is_file($inputFiles[0]));
              $inputRel   = $hasInput ? ($pathRel . '/' . basename($inputFiles[0])) : null;

              // output linked_*.docx
              $outputFiles = glob($absDir . '/linked_*.docx');
              $hasOutput = ($outputFiles && is_file($outputFiles[0]));
              $outputRel = $hasOutput ? ($pathRel . '/' . basename($outputFiles[0])) : null;

              $hasLog    = is_file($absDir . '/exec.log');
              $isError = !($rc === 0 && $hasOutput);

              $origName = (string)($r['original_name'] ?? '—');
              $links_found = isset($r['links_found']) ? (string)$r['links_found'] : '0';
              if ($links_found === '') $links_found = '0';

              $isZip = ($hasInput && $inputRel && str_ends_with(strtolower($inputRel), '.zip'));
          ?>
            <tr>
              <td><?= h($dt['date']) ?></td>
              <td><?= h($dt['time']) ?></td>
              <td class="fw-semibold name-cell" title="<?= h($origName) ?>"><?= h($origName) ?></td>
              <td><?= h($links_found) ?></td>

              <td>
                <div class="doc-icons">
                  <!-- input -->
                  <a class="icon-btn <?= $hasInput ? '' : 'disabled' ?>"
                     href="<?= $hasInput ? h($inputRel) : '#' ?>"
                     target="_blank"
                     title="File caricato">
                    <i class="bi <?= $isZip ? 'bi-file-earmark-zip' : 'bi-file-earmark-word' ?>"></i>
                  </a>

                  <!-- output -->
                  <a class="icon-btn <?= $hasOutput ? '' : 'disabled' ?>"
                     href="<?= $hasOutput ? h($outputRel) : '#' ?>"
                     target="_blank"
                     title="Output">
                    <i class="bi bi-file-earmark-check"></i>
                  </a>

                  <!-- log solo se errore -->
                  <?php if ($isError && $hasLog): ?>
                    <a class="icon-btn"
                       href="<?= h($pathRel . '/exec.log') ?>"
                       target="_blank"
                       title="Log errore">
                      <i class="bi bi-file-earmark-code"></i>
                    </a>
                  <?php endif; ?>
                </div>
              </td>

              <td><?= badge_status($rc, $hasOutput, $dirName) ?></td>

              <td class="text-end">
                <form method="post" class="d-inline" onsubmit="return confirm('Eliminare definitivamente questo risultato?')">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="dir" value="<?= h($dirName) ?>">
                  <button class="btn btn-sm btn-outline-danger" title="Elimina run">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <nav class="mt-3">
        <ul class="pagination justify-content-end mb-0">
          <?php
            $prev = max(1, $page - 1);
            $next = min($totalPages, $page + 1);
            $disabledPrev = ($page <= 1) ? ' disabled' : '';
            $disabledNext = ($page >= $totalPages) ? ' disabled' : '';
            $start = max(1, $page - 3);
            $end   = min($totalPages, $page + 3);
          ?>
          <li class="page-item<?= $disabledPrev ?>">
            <a class="page-link" href="?page=<?= (int)$prev ?>">«</a>
          </li>
          <?php for ($p = $start; $p <= $end; $p++): ?>
            <li class="page-item<?= ($p === $page) ? ' active' : '' ?>">
              <a class="page-link" href="?page=<?= (int)$p ?>"><?= (int)$p ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item<?= $disabledNext ?>">
            <a class="page-link" href="?page=<?= (int)$next ?>">»</a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>
  </div>
</div>

<?php if ($chooseRun && $chooseDocx): ?>
<div class="modal fade" id="chooseDocxModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">

      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="process_zip">
        <input type="hidden" name="run" value="<?= h($chooseRun) ?>">

        <div class="modal-header">
          <h5 class="modal-title">
            <i class="bi bi-ui-radios-grid me-2"></i>
            Seleziona il DOCX principale
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="list-group">
            <?php foreach ($chooseDocx as $i => $fname): ?>
              <label class="list-group-item d-flex align-items-center gap-2">
                <input class="form-check-input me-2"
                       type="radio"
                       name="main_docx"
                       value="<?= h($fname) ?>"
                       <?= $i===0?'checked':'' ?>>
                <span class="fw-semibold"><?= h($fname) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-primary">
            <i class="bi bi-magic"></i>
            Elabora DOCX selezionato
          </button>
        </div>

      </form>

    </div>
  </div>
</div>
<?php endif; ?>


<!-- FilePond -->
<link href="https://unpkg.com/filepond@^4/dist/filepond.css" rel="stylesheet" />
<script src="https://unpkg.com/filepond@^4/dist/filepond.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {

  // =========================
  // AUTO-CLOSE ALERT (3s)
  // =========================
  const el = document.getElementById('autoAlert');
  if (el) {
    setTimeout(() => {
      if (window.bootstrap && bootstrap.Alert) {
        bootstrap.Alert.getOrCreateInstance(el).close();
      } else {
        el.style.transition = 'opacity .35s ease';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 400);
      }
    }, 3000);
  }

  // =========================
  // AUTO-OPEN MODAL (se presente)
  // =========================
  const chooseModal = document.getElementById('chooseDocxModal');
  if (chooseModal && window.bootstrap && bootstrap.Modal) {
    bootstrap.Modal.getOrCreateInstance(chooseModal).show();
  }

  // =========================
  // FILEPOND
  // =========================
  const inputFile = document.getElementById('zipUpload');
  if (inputFile && window.FilePond) {
    FilePond.create(inputFile, {
      allowMultiple: false,
      storeAsFile: true,
      labelIdle:
        'Trascina qui un <strong>.docx</strong> o <strong>.zip</strong> ' +
        'oppure <span class="filepond--label-action">Sfoglia</span>',
      allowFileTypeValidation: false,
      beforeAddFile: (item) => {
        const name = (item?.file?.name || '').toLowerCase().trim();
        const ok = name.endsWith('.docx') || name.endsWith('.zip');
        if (!ok) {
          alert('Sono accettati solo file Word (.docx) o archivi .zip');
          return false;
        }
        return true;
      },
    });
  }

});
</script>

<?php include 'footer.php'; ?>
