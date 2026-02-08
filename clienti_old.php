<?php
/*******************************************************
 * clienti.php — Rubrica clienti + cause + upload
 * Requisiti: header.php deve aprire $connection (MySQLi) e la sessione
 *******************************************************/

/* ===================== Rileva AJAX PRIMA del header ===================== */
$IS_AJAX = (
  (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') ||
  (($_GET['ajax']  ?? '') === '1') ||
  (($_POST['ajax'] ?? '') === '1')
);
if ($IS_AJAX) {
    define('AJAX_MODE', true);
    ob_start(); // cattura qualsiasi output del header
}

include 'header.php'; // deve definire $connection (MySQLi) e sessione

/* ===================== DEBUG ===================== */
if (!defined('DEBUG')) {
  $debug_from_get = isset($_GET['debug']) && $_GET['debug'] === '1';
  define('DEBUG', $debug_from_get || getenv('APP_DEBUG') === '1');
}
if (DEBUG) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
} else {
  ini_set('display_errors', '0');
  error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
}

/* Log file */
$LOG_DIR = __DIR__ . '/logs';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0777, true);
$LOG_FILE = $LOG_DIR . '/clienti.log';

/* Logger minimale */
function log_line(string $msg): void {
  global $LOG_FILE;
  $uid = $_SESSION['id_account'] ?? '-';
  $ip  = $_SERVER['REMOTE_ADDR'] ?? '-';
  $line = sprintf("[%s] [uid:%s] [ip:%s] %s\n", date('Y-m-d H:i:s'), $uid, $ip, $msg);
  @file_put_contents($LOG_FILE, $line, FILE_APPEND);
}

/* Error & Exception handler */
set_error_handler(function($severity, $message, $file, $line){
  if (!(error_reporting() & $severity)) { return; }
  $msg = "PHP ERROR $message in $file:$line";
  log_line($msg);
  if (defined('AJAX_MODE')) {
    http_response_code(500);
    $payload = ['ok'=>false,'msg'=>$message];
    if (DEBUG) $payload['trace'] = ['file'=>$file,'line'=>$line];
    echo json_encode($payload);
    exit;
  }
  if (DEBUG) { throw new ErrorException($message, 0, $severity, $file, $line); }
  return true;
});
set_exception_handler(function(Throwable $ex){
  log_line('EXCEPTION: '.$ex->getMessage().' @ '.$ex->getFile().':'.$ex->getLine());
  if (defined('AJAX_MODE')) {
    http_response_code(500);
    $payload = ['ok'=>false,'msg'=>$ex->getMessage()];
    if (DEBUG) $payload['trace'] = explode("\n", $ex->getTraceAsString());
    echo json_encode($payload);
    exit;
  }
  if (DEBUG) {
    echo "<pre style='white-space:pre-wrap'>".$ex->getMessage()."\n".$ex->getTraceAsString()."</pre>";
  } else {
    echo "<div class='alert alert-danger'>Si è verificato un errore.</div>";
  }
  exit;
});

/* ===================== Helpers ===================== */
if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
function esc(mysqli $c, ?string $v): string { return mysqli_real_escape_string($c, $v ?? ''); }
function is_ajax(): bool { return defined('AJAX_MODE'); }

/** Dedup: evita doppio submit */
function dedup_ok(string $action): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $payload = $_POST;
    $acct = $_SESSION['id_account'] ?? '0';
    $hash = md5(json_encode($payload).$acct);
    if (!isset($_SESSION['last_post_hash'])) $_SESSION['last_post_hash'] = [];
    if (($_SESSION['last_post_hash'][$action] ?? '') === $hash) return false;
    $_SESSION['last_post_hash'][$action] = $hash;
    return true;
}

/** SQL wrapper con timing & log */
function q(mysqli $c, string $sql) {
    $t0 = microtime(true);
    $res = mysqli_query($c, $sql);
    $ms  = (microtime(true) - $t0) * 1000;
    if (!$res) {
        $err = mysqli_error($c);
        log_line("SQL ERROR ($ms ms): $err | SQL: $sql");
        throw new Exception('Errore DB: '.$err);
    } else {
        if (DEBUG) log_line("SQL OK ($ms ms): ".preg_replace('/\s+/', ' ', $sql));
    }
    return $res;
}

/* ===================== Config Upload ===================== */
$UPLOAD_BASE = __DIR__ . '/uploads';
if (!is_dir($UPLOAD_BASE)) @mkdir($UPLOAD_BASE, 0777, true);

/* ===================== Partials Rendering ===================== */
function render_clienti_table(mysqli $connection, string $search, int $page, int $per_page): array {
    $where = "WHERE 1";
    if ($search !== '') {
        $s = esc($connection, $search);
        $where .= " AND (nome LIKE '%$s%' OR cognome LIKE '%$s%' OR cod_fis LIKE '%$s%')";
    }
    $tot = (int)mysqli_fetch_row(q($connection, "SELECT COUNT(*) FROM clienti $where"))[0];
    $offset = ($page-1)*$per_page;
    $res = q($connection, "SELECT * FROM clienti $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");

    ob_start(); ?>
    <div class="small text-muted mb-2"><?= (int)$tot ?> risultati</div>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr><th>Cognome</th><th>Nome</th><th>C.F.</th><th>Telefono</th><th>E-mail</th><th></th></tr>
        </thead>
        <tbody>
        <?php if (mysqli_num_rows($res) === 0): ?>
          <tr><td colspan="6" class="text-muted">Nessun cliente trovato.</td></tr>
        <?php else: while ($r = mysqli_fetch_assoc($res)): ?>
          <tr>
            <td class="text-muted"><?= e($r['cognome']) ?></td>
            <td class="text-muted"><?= e($r['nome']) ?></td>
            <td class="text-muted"><?= e($r['cod_fis']) ?></td>
            <td class="small">
              <div><?= e($r['telefono'] ?: '-') ?></div>
              <div><?= e($r['email'] ?: '-') ?></div>
            </td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-primary btn-open" data-id="<?= e($r['id']) ?>">
                <i class="bi bi-folder2-open"></i> Apri
              </button>
            </td>
          </tr>
        <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    $pages = max(1, (int)ceil($tot / $per_page));
    if ($pages > 1): ?>
      <nav><ul class="pagination mb-0">
        <?php for ($p=1; $p<=$pages; $p++): ?>
          <li class="page-item <?= $p===$page ? 'active':'' ?>">
            <a class="page-link link-page" href="#" data-page="<?= $p ?>"><?= $p ?></a>
          </li>
        <?php endfor; ?>
      </ul></nav>
    <?php endif;

    return ['html'=>ob_get_clean(),'total'=>$tot];
}

function get_categorie(mysqli $connection): array {
    $out = [];
    $rc = q($connection, "SELECT id, nome, COALESCE(colore,'') AS colore FROM categorie_file WHERE attivo=1 ORDER BY nome");
    while ($c = mysqli_fetch_assoc($rc)) $out[] = $c;
    return $out;
}

function render_cause_files_table(mysqli $connection, int $id_causa, array $cats_index): string {
    $rf = q($connection, "SELECT * FROM cause_file WHERE id_causa=$id_causa ORDER BY uploaded_at DESC");
    ob_start();
    if (mysqli_num_rows($rf) === 0) {
        echo "<div class='text-muted mt-2'>Nessun file caricato.</div>";
    } else {
        echo "<div class='table-responsive mt-2'><table class='table table-sm align-middle'>";
        echo "<thead class='table-light'><tr><th>File</th><th>Categoria</th><th>Dimensione</th><th>Caricato</th><th></th></tr></thead><tbody>";
        while ($f = mysqli_fetch_assoc($rf)) {
            $url = e($f['path_relativo']);
            $size = $f['size_bytes'] ? number_format($f['size_bytes']/1024, 1, ',', '.').' KB' : '-';
            $catLabel = '-';
            if (!empty($f['id_categoria']) && isset($cats_index[$f['id_categoria']])) {
                $c = $cats_index[$f['id_categoria']];
                $style = $c['colore'] ? "style='background:{$c['colore']};'" : "";
                $catLabel = "<span class='badge' $style>".e($c['nome'])."</span>";
            }
            echo "<tr>";
            echo "<td><a href='$url' target='_blank'>".e($f['nome_originale'])."</a></td>";
            echo "<td>$catLabel</td>";
            echo "<td>$size</td>";
            echo "<td>".e($f['uploaded_at'])."</td>";
            echo "<td class='text-end'>
                    <button class='btn btn-sm btn-outline-danger btn-del-file' data-id='{$f['id']}' data-causa='$id_causa'><i class='bi bi-trash'></i></button>
                  </td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
    }
    return ob_get_clean();
}

function render_cliente_detail(mysqli $connection, int $id): string {
    $res = q($connection, "SELECT * FROM clienti WHERE id=$id LIMIT 1");
    $cli = mysqli_fetch_assoc($res);
    if (!$cli) return "<div class='alert alert-warning mt-3'>Cliente non trovato.</div>";

    $cats = get_categorie($connection);
    $cats_index = [];
    foreach ($cats as $c) $cats_index[(int)$c['id']] = $c;

    ob_start(); ?>
    <div class="card mt-3">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <h2 class="h5 mb-3">Scheda cliente #<?= e($cli['id']) ?></h2>
          <button class="btn btn-sm btn-outline-warning btn-archive" data-id="<?= e($cli['id']) ?>"><i class="bi bi-archive"></i> Archivia</button>
        </div>

        <form id="form-update-cliente" class="row g-2">
          <input type="hidden" name="action" value="update_cliente">
          <input type="hidden" name="id" value="<?= e($cli['id']) ?>">
          <div class="col-md-3"><label class="form-label">Nome</label>
            <input name="nome" class="form-control" value="<?= e($cli['nome']) ?>" required></div>
          <div class="col-md-3"><label class="form-label">Cognome</label>
            <input name="cognome" class="form-control" value="<?= e($cli['cognome']) ?>" required></div>
          <div class="col-md-3"><label class="form-label">Cod. Fis.</label>
            <input name="cod_fis" class="form-control" value="<?= e($cli['cod_fis']) ?>"></div>
          <div class="col-md-3"><label class="form-label">P.IVA</label>
            <input name="piva" class="form-control" value="<?= e($cli['piva']) ?>"></div>
          <div class="col-md-4"><label class="form-label">Email</label>
            <input name="email" type="email" class="form-control" value="<?= e($cli['email']) ?>"></div>
          <div class="col-md-4"><label class="form-label">Telefono</label>
            <input name="telefono" class="form-control" value="<?= e($cli['telefono']) ?>"></div>
          <div class="col-md-4"><label class="form-label">Stato</label>
            <select name="stato" class="form-select">
              <option value="attivo"   <?= $cli['stato']==='attivo'?'selected':'' ?>>Attivo</option>
              <option value="archiviato" <?= $cli['stato']==='archiviato'?'selected':'' ?>>Archiviato</option>
            </select></div>
          <div class="col-md-6"><label class="form-label">Indirizzo</label>
            <input name="indirizzo" class="form-control" value="<?= e($cli['indirizzo']) ?>"></div>
          <div class="col-md-2"><label class="form-label">CAP</label>
            <input name="cap" class="form-control" value="<?= e($cli['cap']) ?>"></div>
          <div class="col-md-3"><label class="form-label">Città</label>
            <input name="citta" class="form-control" value="<?= e($cli['citta']) ?>"></div>
          <div class="col-md-1"><label class="form-label">PR</label>
            <input name="provincia" maxlength="2" class="form-control text-uppercase" value="<?= e($cli['provincia']) ?>"></div>
          <div class="col-12"><label class="form-label">Note</label>
            <textarea name="note" class="form-control" rows="2"><?= e($cli['note']) ?></textarea></div>
          <div class="col-12 mt-2">
            <button class="btn btn-success"><i class="bi bi-save"></i> Salva modifiche</button>
          </div>
        </form>
      </div>
    </div>

    <?php
    $rc = q($connection, "SELECT * FROM cause WHERE id_cliente=".$cli['id']." ORDER BY created_at DESC");
    ?>
    <div class="card mt-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Cause</span>
        <button class="btn btn-sm btn-primary" data-bs-toggle="collapse" data-bs-target="#addCausa">
          <i class="bi bi-plus-circle"></i> Aggiungi causa
        </button>
      </div>
      <div class="card-body">
        <div class="collapse mb-3" id="addCausa">
          <form id="form-add-causa" class="row g-2">
            <input type="hidden" name="action" value="add_causa">
            <input type="hidden" name="id_cliente" value="<?= e($cli['id']) ?>">
            <div class="col-md-6"><label class="form-label">Titolo *</label>
              <input name="titolo" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Autorità</label>
              <input name="autorita" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Numero RG</label>
              <input name="numero_rg" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Data inizio</label>
              <input type="date" name="data_inizio" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Data fine</label>
              <input type="date" name="data_fine" class="form-control"></div>
            <div class="col-md-8"><label class="form-label">Descrizione</label>
              <textarea name="descrizione" class="form-control" rows="2"></textarea></div>
            <div class="col-md-4"><label class="form-label">Stato</label>
              <select name="stato" class="form-select">
                <option value="aperte">Aperte</option>
                <option value="sospese">Sospese</option>
                <option value="chiuse">Chiuse</option>
              </select></div>
            <div class="col-12">
              <button class="btn btn-primary"><i class="bi bi-plus-circle"></i> Crea causa</button>
            </div>
          </form>
        </div>

        <div id="cause-list">
          <?php while ($ca = mysqli_fetch_assoc($rc)): ?>
            <div id="causa-<?= e($ca['id']) ?>" class="border rounded p-3 mb-3">
              <div class="d-flex justify-content-between">
                <div>
                  <div class="fw-semibold"><?= e($ca['titolo']) ?></div>
                  <div class="small text-muted">
                    Autorità: <?= e($ca['autorita']) ?: '-' ?> ·
                    RG: <?= e($ca['numero_rg']) ?: '-' ?> ·
                    Stato: <?= e($ca['stato']) ?> ·
                    Dal: <?= e($ca['data_inizio'] ?: '-') ?> al <?= e($ca['data_fine'] ?: '-') ?>
                  </div>
                </div>
                <button class="btn btn-sm btn-outline-danger btn-del-causa" data-id="<?= e($ca['id']) ?>" data-cliente="<?= e($cli['id']) ?>">
                  <i class="bi bi-trash"></i>
                </button>
              </div>

              <form class="mt-2 form-upload-file" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_file">
                <input type="hidden" name="id_causa" value="<?= e($ca['id']) ?>">
                <input type="hidden" name="id_cliente" value="<?= e($cli['id']) ?>">
                <div class="row g-2 align-items-center">
                  <div class="col-md-6">
                    <input class="form-control" type="file" name="files[]" multiple>
                  </div>
                    <div class="col-md-4">
                      <select name="id_categoria" class="form-select">
                        <option value="">-- Categoria --</option>
                        <?php foreach ($cats as $cat): ?>
                          <option value="<?= e($cat['id']) ?>"><?= e($cat['nome']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  <div class="col-md-2">
                    <button class="btn btn-outline-primary w-100"><i class="bi bi-upload"></i> Carica</button>
                  </div>
                </div>
              </form>

              <div class="files-area" data-causa="<?= e($ca['id']) ?>">
                <?= render_cause_files_table($connection, (int)$ca['id'], $cats_index) ?>
              </div>
            </div>
          <?php endwhile; ?>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

/* ===================== API AJAX ===================== */
if (is_ajax()) {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    // Trace parametri in ingresso (solo in DEBUG)
    if (DEBUG) {
      $method_dbg = $_SERVER['REQUEST_METHOD'] ?? 'GET';
      $action_dbg = $_POST['action'] ?? $_GET['action'] ?? '(none)';
      $payload = $method_dbg === 'POST' ? $_POST : $_GET;
      $safePayload = $payload;
      foreach (['password','token','csrf'] as $k) if (isset($safePayload[$k])) $safePayload[$k] = '***';
      log_line("AJAX {$method_dbg} action={$action_dbg} payload=" . json_encode($safePayload));
    }

    try {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_POST['action'] ?? $_GET['action'] ?? '';

        /* ---- endpoint di test ---- */
        if ($action === 'ping') {
            echo json_encode(['ok'=>true,'now'=>date('c'),'session_uid'=>($_SESSION['id_account'] ?? null)]); 
            exit;
        }

        /* ---- Ricerca rapida ---- */
        if ($action === 'cliente_ricerca') {
            $term = esc($connection, trim($_POST['term'] ?? $_GET['term'] ?? ''));
            $where = $term !== '' ? "WHERE nome LIKE '%$term%' OR cognome LIKE '%$term%' OR email LIKE '%$term%'" : "";
            $rs = q($connection, "SELECT id, nome, cognome, email, telefono FROM clienti $where ORDER BY cognome, nome LIMIT 100");
            $out = [];
            while ($r = mysqli_fetch_assoc($rs)) $out[] = $r;
            echo json_encode(['ok'=>true,'risultati'=>$out]); exit;
        }

        /* ---- UI ricca: lista + dettaglio ---- */
        if ($action === 'list_clienti') {
            $q = trim($_GET['q'] ?? '');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per_page = 10;
            $partial = render_clienti_table($connection, $q, $page, $per_page);
            echo json_encode(['ok'=>true, 'html'=>$partial['html'], 'total'=>$partial['total']]); exit;
        }

        if ($action === 'view_cliente') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id<=0) throw new Exception('ID non valido.');
            $html = render_cliente_detail($connection, $id);
            echo json_encode(['ok'=>true,'html'=>$html]); exit;
        }

        /* ---- Creazione cliente (+ prima causa opzionale) ---- */
        if ($action === 'add_cliente' && $method==='POST') {
            if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
            $nome     = esc($connection, $_POST['nome'] ?? '');
            $cognome  = esc($connection, $_POST['cognome'] ?? '');
            if ($nome==='' || $cognome==='') throw new Exception('Nome e cognome obbligatori.');
            $cod_fis       = esc($connection, $_POST['cod_fis'] ?? '');
            $piva     = esc($connection, $_POST['piva'] ?? '');
            $email    = esc($connection, $_POST['email'] ?? '');
            $telefono = esc($connection, $_POST['telefono'] ?? '');
            $indir    = esc($connection, $_POST['indirizzo'] ?? '');
            $cap      = esc($connection, $_POST['cap'] ?? '');
            $citta    = esc($connection, $_POST['citta'] ?? '');
            $prov     = esc($connection, strtoupper($_POST['provincia'] ?? ''));
            $note     = esc($connection, $_POST['note'] ?? '');

            q($connection, "INSERT INTO clienti (nome,cognome,cod_fis,piva,email,telefono,indirizzo,cap,citta,provincia,note,stato)
                            VALUES ('$nome','$cognome','$cod_fis','$piva','$email','$telefono','$indir','$cap','$citta','$prov','$note','attivo')");
            $id_cliente = mysqli_insert_id($connection);

            // Prima causa opzionale
            $titolo = trim($_POST['titolo_causa'] ?? '');
            if ($titolo !== '') {
                $titolo     = esc($connection, $titolo);
                $autorita   = esc($connection, $_POST['autorita'] ?? '');
                $numero_rg  = esc($connection, $_POST['numero_rg'] ?? '');
                $data_inizio= esc($connection, $_POST['data_inizio'] ?? '');
                $descr      = esc($connection, $_POST['descrizione'] ?? '');
                $data_sql   = $data_inizio ? "'$data_inizio'" : "NULL";
                q($connection, "INSERT INTO cause (id_cliente,titolo,autorita,numero_rg,data_inizio,descrizione,stato)
                                VALUES ($id_cliente,'$titolo','$autorita','$numero_rg',$data_sql,'$descr','aperte')");
            }

            $html = render_cliente_detail($connection, $id_cliente);
            echo json_encode(['ok'=>true,'id'=>$id_cliente,'html'=>$html,'msg'=>'Cliente creato']); exit;
        }

        /* ---- Modifica cliente ---- */
        if ($action === 'update_cliente' && $method==='POST') {
            if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
            $id = (int)($_POST['id'] ?? 0);
            if ($id<=0) throw new Exception('ID non valido.');
            $nome     = esc($connection, $_POST['nome'] ?? '');
            $cognome  = esc($connection, $_POST['cognome'] ?? '');
            if ($nome==='' || $cognome==='') throw new Exception('Nome e cognome obbligatori.');
            $cod_fis       = esc($connection, $_POST['cod_fis'] ?? '');
            $piva     = esc($connection, $_POST['piva'] ?? '');
            $email    = esc($connection, $_POST['email'] ?? '');
            $telefono = esc($connection, $_POST['telefono'] ?? '');
            $indir    = esc($connection, $_POST['indirizzo'] ?? '');
            $cap      = esc($connection, $_POST['cap'] ?? '');
            $citta    = esc($connection, $_POST['citta'] ?? '');
            $prov     = esc($connection, strtoupper($_POST['provincia'] ?? ''));
            $note     = esc($connection, $_POST['note'] ?? '');
            $stato    = esc($connection, $_POST['stato'] ?? 'attivo');

            q($connection, "UPDATE clienti SET 
                nome='$nome',
                cognome='$cognome',
                cod_fis='$cod_fis',
                piva='$piva',
                email='$email',
                telefono='$telefono',
                indirizzo='$indir',
                cap='$cap',
                citta='$citta',
                provincia='$prov',
                note='$note',
                stato='$stato'
              WHERE id=$id");

            $html = render_cliente_detail($connection, $id);
            echo json_encode(['ok'=>true,'html'=>$html,'msg'=>'Cliente aggiornato']); exit;
        }

        /* ---- Archiviazione cliente ---- */
        if ($action === 'archive_cliente' && $method==='POST') {
            if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
            $id = (int)($_POST['id'] ?? 0);
            if ($id<=0) throw new Exception('ID non valido.');
            q($connection, "UPDATE clienti SET stato='archiviato' WHERE id=$id");
            $html = render_cliente_detail($connection, $id);
            echo json_encode(['ok'=>true,'html'=>$html,'msg'=>'Cliente archiviato']); exit;
        }

        /* ---- Cause: add / delete ---- */
        if ($action === 'add_causa' && $method==='POST') {
            if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
            $id_cliente = (int)($_POST['id_cliente'] ?? 0);
            if ($id_cliente<=0) throw new Exception('Cliente non valido.');
            $titolo = esc($connection, $_POST['titolo'] ?? '');
            if ($titolo==='') throw new Exception('Titolo obbligatorio.');
            $autorita   = esc($connection, $_POST['autorita'] ?? '');
            $numero_rg  = esc($connection, $_POST['numero_rg'] ?? '');
            $data_inizio= esc($connection, $_POST['data_inizio'] ?? '');
            $data_fine  = esc($connection, $_POST['data_fine'] ?? '');
            $descr      = esc($connection, $_POST['descrizione'] ?? '');
            $stato      = esc($connection, $_POST['stato'] ?? 'aperte');

            $d_i = $data_inizio ? "'$data_inizio'" : "NULL";
            $d_f = $data_fine   ? "'$data_fine'"   : "NULL";
            q($connection, "INSERT INTO cause (id_cliente,titolo,autorita,numero_rg,data_inizio,data_fine,descrizione,stato)
                            VALUES ($id_cliente,'$titolo','$autorita','$numero_rg',$d_i,$d_f,'$descr','$stato')");
            $html = render_cliente_detail($connection, $id_cliente);
            echo json_encode(['ok'=>true,'html'=>$html,'msg'=>'Causa aggiunta']); exit;
        }

        if ($action === 'delete_causa' && $method==='POST') {
            if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
            $id_causa   = (int)($_POST['id_causa'] ?? 0);
            $id_cliente = (int)($_POST['id_cliente'] ?? 0);
            if ($id_causa<=0 || $id_cliente<=0) throw new Exception('Parametri non validi.');
            q($connection, "DELETE FROM cause WHERE id=$id_causa AND id_cliente=$id_cliente");
            $html = render_cliente_detail($connection, $id_cliente);
            echo json_encode(['ok'=>true,'html'=>$html,'msg'=>'Causa eliminata']); exit;
        }

        /* ---- File: upload / delete ---- */
        if ($action === 'upload_file' && $method==='POST') {
            if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
            $id_causa   = (int)($_POST['id_causa'] ?? 0);
            $id_cliente = (int)($_POST['id_cliente'] ?? 0);
            $id_cat     = (int)($_POST['id_categoria'] ?? 0) ?: null;
            if ($id_causa<=0 || $id_cliente<=0) throw new Exception('Parametri non validi.');

            $dir_cliente = $UPLOAD_BASE . '/cliente_'.$id_cliente;
            $dir_causa   = $dir_cliente . '/causa_'.$id_causa;
            if (!is_dir($dir_cliente)) @mkdir($dir_cliente, 0777, true);
            if (!is_dir($dir_causa))   @mkdir($dir_causa,   0777, true);
            if (!isset($_FILES['files'])) throw new Exception('Nessun file selezionato.');

            foreach ($_FILES['files']['error'] as $i => $err) {
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                if ($err !== UPLOAD_ERR_OK) throw new Exception('Errore upload (code '.$err.').');
                $tmp  = $_FILES['files']['tmp_name'][$i];
                $orig = basename($_FILES['files']['name'][$i]);
                $size = (int)$_FILES['files']['size'][$i];
                $dest = $dir_causa . '/'. time().'_'.preg_replace('/[^\w\-.]+/','_',$orig);
                if (!move_uploaded_file($tmp, $dest)) throw new Exception('Impossibile spostare il file.');
                $rel = 'uploads/cliente_'.$id_cliente.'/causa_'.$id_causa.'/'.basename($dest);
                $orig_esc = esc($connection, $orig);
                $rel_esc  = esc($connection, $rel);
                $cat_sql  = $id_cat ? (int)$id_cat : 'NULL';
                q($connection, "INSERT INTO cause_file (id_causa,id_categoria,nome_originale,path_relativo,size_bytes)
                                VALUES ($id_causa,$cat_sql,'$orig_esc','$rel_esc',$size)");
            }

            $cats = get_categorie($connection);
            $cats_index = [];
            foreach ($cats as $c) $cats_index[(int)$c['id']] = $c;
            $html_files = render_cause_files_table($connection, $id_causa, $cats_index);
            echo json_encode(['ok'=>true,'html'=>$html_files,'msg'=>'File caricati']); exit;
        }

        if ($action === 'delete_file' && $method==='POST') {
            if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
            $id_file  = (int)($_POST['id_file'] ?? 0);
            $id_causa = (int)($_POST['id_causa'] ?? 0);
            if ($id_file<=0 || $id_causa<=0) throw new Exception('Parametri non validi.');
            $res = q($connection, "SELECT id, path_relativo FROM cause_file WHERE id=$id_file AND id_causa=$id_causa LIMIT 1");
            $f = mysqli_fetch_assoc($res);
            if (!$f) throw new Exception('File non trovato.');
            $full = __DIR__ . '/'.$f['path_relativo'];
            if (is_file($full)) @unlink($full);
            q($connection, "DELETE FROM cause_file WHERE id=".$f['id']);
            $cats = get_categorie($connection);
            $cats_index = [];
            foreach ($cats as $c) $cats_index[(int)$c['id']] = $c;
            $html_files = render_cause_files_table($connection, $id_causa, $cats_index);
            echo json_encode(['ok'=>true,'html'=>$html_files,'msg'=>'File eliminato']); exit;
        }

        /* ---- Categorie ---- */
        if ($action === 'list_categorie') {
            echo json_encode(['ok'=>true,'items'=>get_categorie($connection)]); exit;
        }

        throw new Exception('Azione non riconosciuta.');
    } catch (Exception $ex) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'msg'=>$ex->getMessage()]);
    }
    exit; // IMPORTANTISSIMO
}

/* ===================== UI (HTML + JS) ===================== */
?>
<div class="container my-4 container-narrow">
  <div class="d-flex align-items-center justify-content-between">
    <h1 class="h4 mb-0">Clienti</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddCliente">
      <i class="bi bi-check2-circle"></i> Nuovo cliente
    </button>
  </div>

  <!-- Ricerca -->
  <form id="form-search" class="row g-2 align-items-center mt-3">
    <div class="col-md-6">
      <input type="text" name="q" class="form-control" placeholder="Cerca per Nome, Cognome o Cod. Fis.">
    </div>
    <div class="col-auto">
      <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Cerca</button>
    </div>
    <div class="col-auto">
      <button class="btn btn-outline-danger btn-clear" type="button"><i class="bi bi-x-circle"></i> Pulisci</button>
    </div>
  </form>

  <div id="list-area" class="mt-3"></div>
  <div id="detail-area"></div>
</div>

<!-- Modal: Nuovo cliente (+ opzionale prima causa) -->
<div class="modal fade" id="modalAddCliente" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form id="form-add-cliente" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Nuovo cliente</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-3"><label class="form-label">Nome *</label><input name="nome" class="form-control" required></div>
          <div class="col-md-3"><label class="form-label">Cognome *</label><input name="cognome" class="form-control" required></div>
          <div class="col-md-3"><label class="form-label">Cod. Fis.</label><input name="cod_fis" class="form-control"></div>
          <div class="col-md-3"><label class="form-label">P.IVA</label><input name="piva" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Telefono</label><input name="telefono" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Provincia</label><input name="provincia" maxlength="2" class="form-control text-uppercase"></div>
          <div class="col-md-6"><label class="form-label">Indirizzo</label><input name="indirizzo" class="form-control"></div>
          <div class="col-md-2"><label class="form-label">CAP</label><input name="cap" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Città</label><input name="citta" class="form-control"></div>
          <div class="col-12"><label class="form-label">Note</label><textarea name="note" class="form-control" rows="2"></textarea></div>
        </div>
        <hr class="my-3">
        <div class="small text-muted mb-2">Opzionale: prima causa</div>
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label">Titolo causa</label><input name="titolo_causa" class="form-control"></div>
          <div class="col-md-6"><label class="form-label">Autorità</label><input name="autorita" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Numero RG</label><input name="numero_rg" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Data inizio</label><input type="date" name="data_inizio" class="form-control"></div>
          <div class="col-md-12"><label class="form-label">Descrizione</label><textarea name="descrizione" class="form-control" rows="2"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Annulla</button>
        <button class="btn btn-primary"><i class="bi bi-check2-circle"></i> Crea</button>
      </div>
    </form>
  </div>
</div>

<script>
(() => {
  const listArea   = document.getElementById('list-area');
  const detailArea = document.getElementById('detail-area');
  const formSearch = document.getElementById('form-search');
  const formAddCli = document.getElementById('form-add-cliente');

  // ===== API helper con AbortController (annulla call precedente) =====
  let currentController = null;
  async function api(action, params={}, method='GET') {
    if (currentController) currentController.abort();
    currentController = new AbortController();

    const url = new URL('clienti.php', window.location.href);
    url.searchParams.set('ajax','1');
    url.searchParams.set('action', action);
    if (new URLSearchParams(window.location.search).get('debug') === '1') {
      url.searchParams.set('debug','1');
    }

    const opts = { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: currentController.signal };

    if (method === 'GET') {
      for (const [k,v] of Object.entries(params)) if (v!==undefined) url.searchParams.set(k, v);
      opts.method = 'GET';
    } else {
      opts.method = 'POST';
      opts.headers['Accept'] = 'application/json';
      if (params instanceof FormData) {
        opts.body = params;
      } else {
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
        opts.body = new URLSearchParams(params).toString();
      }
    }

    const r = await fetch(url, opts);
    const text = await r.text();
    let j;
    try { j = JSON.parse(text); } catch (_) {
      throw new Error('Risposta non valida dal server:\n' + text.slice(0, 1200));
    }
    if (!j.ok) throw new Error(j.msg || 'Errore');
    return j;
  }

  // ===== Loader UI minimale =====
  function showLoading(area) { area.innerHTML = '<div class="text-muted small">Caricamento…</div>'; }

  // ===== Caricamento lista con query e pagina =====
  let currentQuery = '';
  async function loadList(page=1) {
    showLoading(listArea);
    try {
      const { html } = await api('list_clienti', { q: currentQuery, page }, 'GET');
      listArea.innerHTML = html;

      listArea.querySelectorAll('.btn-open').forEach(btn => {
        btn.addEventListener('click', () => openCliente(btn.dataset.id));
      });
      listArea.querySelectorAll('.link-page').forEach(a => {
        a.addEventListener('click', (e) => { e.preventDefault(); loadList(parseInt(a.dataset.page,10)||1); });
      });
    } catch (err) {
      listArea.innerHTML = '<div class="alert alert-danger">'+(err.message||'Errore')+'</div>';
    }
  }

  async function openCliente(id) {
    showLoading(detailArea);
    try {
      const { html } = await api('view_cliente', { id }, 'GET');
      detailArea.innerHTML = html;
      bindDetailEvents();
      window.scrollTo({ top: detailArea.offsetTop - 10, behavior: 'smooth' });
    } catch (err) {
      detailArea.innerHTML = '<div class="alert alert-danger">'+(err.message||'Errore')+'</div>';
    }
  }

  function bindDetailEvents() {
    const formUpdate = document.getElementById('form-update-cliente');
    if (formUpdate) {
      formUpdate.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(formUpdate);
        fd.set('ajax','1');
        fd.set('action','update_cliente');
        try {
          const { html } = await api('update_cliente', fd, 'POST');
          detailArea.innerHTML = html;
          bindDetailEvents();
          // ricarica lista mantenendo query/pagina corrente
          loadList(1);
        } catch (err) { alert(err.message); }
      });
    }

    const btnArch = detailArea.querySelector('.btn-archive');
    if (btnArch) {
      btnArch.addEventListener('click', async () => {
        if (!confirm('Archiviare questo cliente?')) return;
        const fd = new FormData();
        fd.set('ajax','1'); fd.set('action','archive_cliente'); fd.set('id', btnArch.dataset.id);
        try {
          const { html } = await api('archive_cliente', fd, 'POST');
          detailArea.innerHTML = html;
          bindDetailEvents();
          loadList(1);
        } catch (err) { alert(err.message); }
      });
    }

    const formAddCausa = document.getElementById('form-add-causa');
    if (formAddCausa) {
      formAddCausa.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(formAddCausa);
        fd.set('ajax','1'); fd.set('action','add_causa');
        try {
          const { html } = await api('add_causa', fd, 'POST');
          detailArea.innerHTML = html;
          bindDetailEvents();
        } catch (err) { alert(err.message); }
      });
    }

    detailArea.querySelectorAll('.form-upload-file').forEach(fu => {
      fu.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(fu);
        fd.set('ajax','1'); fd.set('action','upload_file');
        try {
          const { html } = await api('upload_file', fd, 'POST');
          const causaId = fu.querySelector('input[name="id_causa"]').value;
          const area = detailArea.querySelector(`.files-area[data-causa="${causaId}"]`);
          if (area) area.innerHTML = html;
          fu.reset();
        } catch (err) { alert(err.message); }
      });
    });

    detailArea.querySelectorAll('.btn-del-file').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Eliminare questo file?')) return;
        const fd = new FormData();
        fd.set('ajax','1'); fd.set('action','delete_file');
        fd.set('id_file', btn.dataset.id); fd.set('id_causa', btn.dataset.causa);
        try {
          const { html } = await api('delete_file', fd, 'POST');
          const area = detailArea.querySelector(`.files-area[data-causa="${btn.dataset.causa}"]`);
          if (area) area.innerHTML = html;
        } catch (err) { alert(err.message); }
      });
    });

    detailArea.querySelectorAll('.btn-del-causa').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Eliminare questa causa?')) return;
        const fd = new FormData();
        fd.set('ajax','1'); fd.set('action','delete_causa');
        fd.set('id_causa', btn.dataset.id); fd.set('id_cliente', btn.dataset.cliente);
        try {
          const { html } = await api('delete_causa', fd, 'POST');
          detailArea.innerHTML = html;
          bindDetailEvents();
        } catch (err) { alert(err.message); }
      });
    });
  }

  // ====== LIVE SEARCH con debounce e gestione IME ======
  const qInput = formSearch.q;
  let debounceTimer = null;
  let composing = false;

  // Evita trigger mentre l'utente usa IME (accenti / cinese ecc.)
  qInput.addEventListener('compositionstart', () => composing = true);
  qInput.addEventListener('compositionend', () => { composing = false; triggerSearch(); });

  qInput.addEventListener('input', () => {
    if (composing) return;
    triggerSearch();
  });

  function triggerSearch() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
      currentQuery = qInput.value.trim();
      loadList(1);
    }, 250); // debounce 250ms
  }

  // Disabilita submit tradizionale (non serve più)
  formSearch.addEventListener('submit', (e) => { e.preventDefault(); });

  // Pulsante "Pulisci" conserva UX
  const btnClear = formSearch.querySelector('.btn-clear');
  if (btnClear) {
    btnClear.addEventListener('click', () => {
      qInput.value = '';
      currentQuery = '';
      loadList(1);
      qInput.focus();
    });
  }

  // ===== Creazione cliente (resta uguale) =====
  formAddCli.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(formAddCli);
    fd.set('ajax','1'); fd.set('action','add_cliente');
    try {
      const { html } = await api('add_cliente', fd, 'POST');
      window.bootstrap?.Modal?.getInstance(document.getElementById('modalAddCliente'))?.hide();
      formAddCli.reset();
      detailArea.innerHTML = html;
      bindDetailEvents();
      loadList(1);
    } catch (err) { alert(err.message); }
  });

  // Primo load
  loadList(1);
})();
</script>



<?php
  include 'footer.php'; // include anche il js di bootstrap
?>