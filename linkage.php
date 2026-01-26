<?php
session_start();
include 'header.php';

// Controllo login
if (empty($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}

$USER_ID = (int)($_SESSION['id_user'] ?? 0);

// Genera CSRF se non presente
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// Config: path Python/script
$PYTHON = '/usr/bin/python3';
$SCRIPT = __DIR__ . '/elabora_docx.py';
$RESULTS_BASE = __DIR__ . '/risultati/' . $USER_ID;

// Crea base dir
@mkdir($RESULTS_BASE, 0777, true);

// === UPLOAD ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (($_POST['csrf'] ?? '') !== $_SESSION['csrf']) {
        $_SESSION['flash_tipo'] = 'err';
        $_SESSION['flash_msg']  = 'Richiesta non valida.';
        header('Location: linkage.php'); exit;
    }

    if (!isset($_FILES['doc']) || $_FILES['doc']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_tipo'] = 'err';
        $_SESSION['flash_msg']  = 'Caricamento fallito.';
        header('Location: linkage.php'); exit;
    }

    $f = $_FILES['doc'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'docx') {
        $_SESSION['flash_tipo'] = 'err';
        $_SESSION['flash_msg']  = 'Sono accettati solo .docx';
        header('Location: linkage.php'); exit;
    }

    $ts = date('Y_m_d_H_i_s');
    $runDir = $RESULTS_BASE . '/' . $ts;
    @mkdir($runDir, 0777, true);

    $orig = $runDir . '/input.docx';
    $out  = $runDir . '/output.docx';
    $log  = $runDir . '/exec.log';

    if (!move_uploaded_file($f['tmp_name'], $orig)) {
        $_SESSION['flash_tipo'] = 'err';
        $_SESSION['flash_msg']  = 'Impossibile salvare il file.';
        header('Location: linkage.php'); exit;
    }

    $urnIndex = __DIR__ . '/urn_index.json';
    $cmd = $PYTHON . ' ' . escapeshellarg($SCRIPT) . ' ' . escapeshellarg($orig) . ' ' . escapeshellarg($out) . ' ' . escapeshellarg($urnIndex) . ' 2>&1';
    $output = [];
    $rc = 0;
    exec($cmd, $output, $rc);
    file_put_contents($log, "[CMD] $cmd\n\n" . implode("\n", $output) . "\n[RC] $rc\n");

    $runDirRel = 'risultati/' . $USER_ID . '/' . $ts;
    $cmdEsc = mysqli_real_escape_string($connection, $cmd);
    $dirEsc = mysqli_real_escape_string($connection, $runDirRel);
    mysqli_query($connection, "INSERT INTO linkage_results (id_user, created_at, path_rel, last_rc) VALUES ($USER_ID, NOW(), '$dirEsc', $rc)");

    if ($rc === 0 && file_exists($out)) {
        $_SESSION['flash_tipo'] = 'ok';
        $_SESSION['flash_msg']  = 'Elaborazione completata.';
    } else {
        $_SESSION['flash_tipo'] = 'err';
        $_SESSION['flash_msg']  = 'Elaborazione conclusa con errori. Controlla il log.';
    }
    header('Location: linkage.php'); exit;
}

// === DELETE ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (($_POST['csrf'] ?? '') !== $_SESSION['csrf']) {
        $_SESSION['flash_tipo'] = 'err';
        $_SESSION['flash_msg']  = 'Richiesta non valida.';
        header('Location: linkage.php'); exit;
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
                $file->isDir() ? rmdir($file) : unlink($file);
            }
            rmdir($target);

            $dirRel = 'risultati/' . $USER_ID . '/' . $dir;
            $dirRelEsc = mysqli_real_escape_string($connection, $dirRel);
            mysqli_query($connection, "DELETE FROM linkage_results WHERE id_user=$USER_ID AND path_rel='$dirRelEsc'");

            $_SESSION['flash_tipo'] = 'ok';
            $_SESSION['flash_msg']  = 'Risultato eliminato.';
        }
    }
    header('Location: linkage.php'); exit;
}
?>

<h1 class="h4 mb-3">Linkage (Word → URN Normattiva)</h1>

<?php
// Mostra eventuale messaggio
if (!empty($_SESSION['flash_msg'])) {
    $classe = ($_SESSION['flash_tipo'] === 'ok') ? 'alert-success' : 'alert-danger';
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
// Lista risultati
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
      $input = file_exists("$dir/input.docx");
      $output= file_exists("$dir/output.docx");
      $log   = file_exists("$dir/exec.log");
      $rel   = 'risultati/' . $USER_ID . '/' . $d;
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
          <li><?= $input ? '✅' : '❌' ?> <a href="<?= $rel ?>/input.docx" target="_blank">input.docx</a></li>
          <li><?= $output ? '✅' : '❌' ?> <a href="<?= $rel ?>/output.docx" target="_blank">output.docx</a></li>
          <li><?= $log    ? '✅' : '❌' ?> <a href="<?= $rel ?>/exec.log" target="_blank">exec.log</a></li>
        </ul>
        <div class="small text-muted">Percorso: <code><?= htmlspecialchars($rel, ENT_QUOTES, 'UTF-8') ?></code></div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
