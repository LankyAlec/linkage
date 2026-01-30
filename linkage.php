<?php
session_start();
include 'header.php';

// Controllo login
if (empty($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}

$USER_ID = (int)($_SESSION['id_user'] ?? 0);

// CSRF
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// === CONFIG ===
$PYTHON = "/volume1/web/avvocati/venv/bin/python";
$SCRIPT = __DIR__ . "/py/elabora_docx.py";
$URN_INDEX = __DIR__ . "/py/urn_index.json";
$RESULTS_BASE = __DIR__ . "/risultati/" . $USER_ID;

// Crea base dir risultati
if (!is_dir($RESULTS_BASE)) {
    @mkdir($RESULTS_BASE, 0775, true);
}

// Helper flash
function flash_and_redirect(string $type, string $msg): void {
    $_SESSION['flash_tipo'] = $type;
    $_SESSION['flash_msg']  = $msg;
    header('Location: linkage.php');
    exit;
}

// === UPLOAD ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {

    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        flash_and_redirect('err', 'Richiesta non valida.');
    }

    if (!isset($_FILES['doc']) || ($_FILES['doc']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash_and_redirect('err', 'Caricamento fallito.');
    }

    $f = $_FILES['doc'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'docx') {
        flash_and_redirect('err', 'Sono accettati solo .docx');
    }

    // Verifiche file essenziali
    if (!is_file($PYTHON)) {
        flash_and_redirect('err', "Python non trovato: $PYTHON");
    }
    if (!is_file($SCRIPT)) {
        flash_and_redirect('err', "Script non trovato: $SCRIPT");
    }
    if (!is_file($URN_INDEX)) {
        flash_and_redirect('err', "urn_index.json non trovato: $URN_INDEX");
    }

    // Crea run dir
    $ts = date('Y_m_d_H_i_s');
    $runDir = $RESULTS_BASE . '/' . $ts;
    @mkdir($runDir, 0775, true);

    $orig = $runDir . '/input.docx';
    $out  = $runDir . '/output.docx';
    $log  = $runDir . '/exec.log';

    if (!move_uploaded_file($f['tmp_name'], $orig)) {
        flash_and_redirect('err', 'Impossibile salvare il file.');
    }

    // Comando: python script input output urn_index
    $cmd = escapeshellarg($PYTHON) . " " .
           escapeshellarg($SCRIPT) . " " .
           escapeshellarg($orig) . " " .
           escapeshellarg($out) . " " .
           escapeshellarg($URN_INDEX) . " 2>&1";

    // Esegui + log diagnostico
    $who = [];
    $pwd = [];
    $cmdOut = [];
    $rcWho = 0;
    $rcPwd = 0;
    $rc = 0;

    exec("whoami 2>&1", $who, $rcWho);
    exec("pwd 2>&1", $pwd, $rcPwd);
    exec($cmd, $cmdOut, $rc);

    $logTxt =
        "[WHO] " . implode("\n", $who) . "\n" .
        "[PWD] " . implode("\n", $pwd) . "\n" .
        "[CMD] $cmd\n\n" .
        implode("\n", $cmdOut) . "\n\n" .
        "[RC] $rc\n";

    file_put_contents($log, $logTxt);

    // DB insert (se $connection esiste da header.php)
    if (isset($connection) && $connection instanceof mysqli) {
        $runDirRel = 'risultati/' . $USER_ID . '/' . $ts;
        $dirEsc = mysqli_real_escape_string($connection, $runDirRel);
        $rcInt = (int)$rc;
        mysqli_query($connection, "INSERT INTO linkage_results (id_user, created_at, path_rel, last_rc)
                                  VALUES ($USER_ID, NOW(), '$dirEsc', $rcInt)");
    }

    if ($rc === 0 && is_file($out)) {
        flash_and_redirect('ok', 'Elaborazione completata.');
    } else {
        flash_and_redirect('err', 'Elaborazione conclusa con errori. Controlla il log.');
    }
}

// === DELETE ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {

    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        flash_and_redirect('err', 'Richiesta non valida.');
    }

    $dir = basename($_POST['dir'] ?? '');
    if ($dir) {
        $target = $RESULTS_BASE . '/' . $dir;

        if (is_dir($target)) {
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($rii as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($target);

            if (isset($connection) && $connection instanceof mysqli) {
                $dirRel = 'risultati/' . $USER_ID . '/' . $dir;
                $dirRelEsc = mysqli_real_escape_string($connection, $dirRel);
                mysqli_query($connection, "DELETE FROM linkage_results WHERE id_user=$USER_ID AND path_rel='$dirRelEsc'");
            }

            flash_and_redirect('ok', 'Risultato eliminato.');
        }
    }

    flash_and_redirect('err', 'Elemento non valido.');
}
?>

<h1 class="h4 mb-3">Linkage (Word → URN Normattiva)</h1>

<?php
if (!empty($_SESSION['flash_msg'])) {
    $classe = (($_SESSION['flash_tipo'] ?? '') === 'ok') ? 'alert-success' : 'alert-danger';
    echo "<div class='alert $classe'>" . htmlspecialchars($_SESSION['flash_msg'], ENT_QUOTES, 'UTF-8') . "</div>";
    unset($_SESSION['flash_msg'], $_SESSION['flash_tipo']);
}
?>

<form class="card card-body shadow-sm mb-4" method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="action" value="upload">
  <div class="row g-3 align-items-end">
    <div class="col-md-7">
      <label class="form-label">Carica documento .docx</label>
      <input required class="form-control" type="file" name="doc" accept=".docx">
    </div>
    <div class="col-md-5 d-flex gap-2">
      <button class="btn btn-primary mt-3 mt-md-0"><i class="bi bi-cloud-upload"></i> Elabora</button>
      <a class="btn btn-outline-secondary mt-3 mt-md-0" href="linkage.php"><i class="bi bi-arrow-clockwise"></i> Aggiorna</a>
    </div>
  </div>
</form>

<?php
$entries = [];
if (is_dir($RESULTS_BASE)) {
    foreach (array_diff(scandir($RESULTS_BASE), ['.', '..']) as $d) {
        if (is_dir($RESULTS_BASE . '/' . $d)) $entries[] = $d;
    }
    rsort($entries);
}
?>

<h2 class="h6 text-uppercase text-muted">I tuoi risultati</h2>
<?php if (!$entries): ?>
  <div class="text-muted">Nessun risultato.</div>
<?php else: ?>
  <div class="row g-3">
  <?php foreach ($entries as $d):
      $dir = $RESULTS_BASE . '/' . $d;
      $hasInput  = is_file("$dir/input.docx");
      $hasOutput = is_file("$dir/output.docx");
      $hasLog    = is_file("$dir/exec.log");
      $rel       = 'risultati/' . $USER_ID . '/' . $d;
  ?>
    <div class="col-md-6">
      <div class="file-tile">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-semibold"><i class="bi bi-folder2"></i> <?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?></div>
          <form method="post" onsubmit="return confirm('Eliminare definitivamente?')">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="dir" value="<?= htmlspecialchars($d, ENT_QUOTES, 'UTF-8') ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
        </div>
        <ul class="list-unstyled mb-2">
          <li><?= $hasInput ? '✅' : '❌' ?> <a href="<?= $rel ?>/input.docx" target="_blank">input.docx</a></li>
          <li><?= $hasOutput ? '✅' : '❌' ?> <a href="<?= $rel ?>/output.docx" target="_blank">output.docx</a></li>
          <li><?= $hasLog ? '✅' : '❌' ?> <a href="<?= $rel ?>/exec.log" target="_blank">exec.log</a></li>
        </ul>
        <div class="small text-muted">Percorso: <code><?= htmlspecialchars($rel, ENT_QUOTES, 'UTF-8') ?></code></div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
