<?php
/*******************************************************
 * clienti.php — Rubrica clienti
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

/* ========== Polyfill PHP 8 su PHP 7.x ========== */
if (!function_exists('str_starts_with')) {
  function str_starts_with(string $haystack, string $needle): bool {
    if ($needle === '') return true;
    return strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}

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

/* ====== Utilità schema: colonne opzionali ====== */
function col_exists(mysqli $c, string $table, string $col): bool {
    $col_esc = esc($c, $col);
    $res = q($c, "SHOW COLUMNS FROM `$table` LIKE '$col_esc'");
    return mysqli_num_rows($res) > 0;
}
function birth_state_col(mysqli $c): ?string {
    if (col_exists($c, 'clienti', 'stato_nascita'))   return 'stato_nascita';
    if (col_exists($c, 'clienti', 'nazione_nascita')) return 'nazione_nascita';
    return null;
}

/* ====== Normalizzazioni + duplicati ====== */
function norm_cf(?string $cf): string {
    $cf = strtoupper(trim((string)$cf));
    return preg_replace('/\s+/', '', $cf);
}
function norm_piva(?string $p): string {
    $p = preg_replace('/\D+/', '', (string)$p); // solo cifre
    return $p ?? '';
}
function exists_duplicate(mysqli $c, string $field, string $value, int $exclude_id=0): bool {
    if ($value === '') return false;
    $v = esc($c, $value);
    $where = $exclude_id > 0 ? "AND id <> $exclude_id" : "";
    $res = q($c, "SELECT id FROM clienti WHERE `$field` = '$v' $where LIMIT 1");
    return (mysqli_num_rows($res) > 0);
}

/* ===================== Liste (paesi/provincie) ===================== */
$LIST_DIR = __DIR__ . '/liste';
function list_file_path(string $base, string $dir): ?string {
    $candidates = [$dir."/$base.txt", $dir."/$base"];
    foreach ($candidates as $p) if (is_file($p)) return $p;
    return null;
}
function load_list_from_file(string $base, string $dir, bool $uppercase=false): array {
    $path = list_file_path($base, $dir);
    if (!$path) return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        log_line("WARN: impossibile leggere lista: $path");
        return [];
    }
    $out = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with(ltrim($line), '#')) continue;
        $out[] = $uppercase ? strtoupper($line) : $line;
    }
    return array_values(array_unique($out));
}
$LISTA_PAESI = load_list_from_file('paesi', $LIST_DIR, false);
$LISTA_PROV  = load_list_from_file('provincie', $LIST_DIR, true); // es. MI, CT, ...

/* ===================== Partials Rendering ===================== */
function render_clienti_table(mysqli $connection, string $search, int $page, int $per_page, ?int $id_exact=null): array {
    if ($id_exact && $id_exact > 0) {
        $where = "WHERE id=$id_exact";
    } else {
        $where = "WHERE 1";
        if ($search !== '') {
            $s = esc($connection, $search);
            $where .= " AND (nome LIKE '%$s%' OR cognome LIKE '%$s%' OR cod_fis LIKE '%$s%' OR email LIKE '%$s%' OR pec LIKE '%$s%')";
        }
    }
    $tot = (int)mysqli_fetch_row(q($connection, "SELECT COUNT(*) FROM clienti $where"))[0];
    $offset = ($page-1)*$per_page;
    $res = q($connection, "SELECT * FROM clienti $where ORDER BY id DESC LIMIT $per_page OFFSET $offset");

    ob_start(); ?>
    <div class="small text-muted mb-2"><?= (int)$tot ?> risultati</div>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead class="table-light">
          <tr>
            <th>Cognome</th><th>Nome</th><th>C.F.</th><th>Telefono</th><th>E-mail</th><th>PEC</th>
            <th class="text-end">Azioni</th>
          </tr>
        </thead>
        <tbody>
        <?php if (mysqli_num_rows($res) === 0): ?>
          <tr><td colspan="7" class="text-muted">Nessun cliente trovato.</td></tr>
        <?php else: while ($r = mysqli_fetch_assoc($res)): ?>
          <tr>
            <td class="text-muted"><?= e($r['cognome']) ?></td>
            <td class="text-muted"><?= e($r['nome']) ?></td>
            <td class="text-muted"><?= e($r['cod_fis']) ?></td>
            <td class="small"><?= e($r['telefono'] ?: '-') ?></td>
            <td class="small"><?= e($r['email'] ?: '-') ?></td>
            <td class="small"><?= e(($r['pec'] ?? '') ?: '-') ?></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-secondary me-1 btn-anagrafica"
                      data-id="<?= e($r['id']) ?>" title="Anagrafica">
                <i class="bi bi-person-vcard"></i>
              </button>
              <a class="btn btn-sm btn-outline-primary"
                 href="clienti_cause.php?id_cliente=<?= e($r['id']) ?>"
                 title="Cause">
                <i class="bi bi-folder2-open"></i>
              </a>
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

/* ===================== API AJAX ===================== */
if (is_ajax()) {
    if (ob_get_length()) { ob_clean(); }
    header('Content-Type: application/json; charset=utf-8');

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

        /* ---- Lista clienti (per tabella) ---- */
        if ($action === 'list_clienti') {
            $q = trim($_GET['q'] ?? '');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per_page = 10;
            $id_exact = (int)($_GET['id'] ?? 0) ?: null; // filtro per ID
            $partial = render_clienti_table($connection, $q, $page, $per_page, $id_exact);
            echo json_encode(['ok'=>true, 'html'=>$partial['html'], 'total'=>$partial['total']]); exit;
        }

        /* ---- Modulo anagrafica (HTML) ---- */
        if ($action === 'view_cliente_form' && $method==='GET') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID non valido.');

            $res = q($connection, "SELECT * FROM clienti WHERE id=$id LIMIT 1");
            if (mysqli_num_rows($res) === 0) throw new Exception('Cliente non trovato.');
            $r = mysqli_fetch_assoc($res);

            $col_birth = birth_state_col($connection);
            $val_birth = $col_birth ? ($r[$col_birth] ?? '') : '';

            ob_start(); ?>
            <form id="form-update-cliente" class="row g-2">
              <input type="hidden" name="id" value="<?= e($r['id']) ?>">

              <!-- Row 1 -->
              <div class="col-md-3">
                <label class="form-label">Nome *</label>
                <input name="nome" class="form-control" required value="<?= e($r['nome']) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Cognome *</label>
                <input name="cognome" class="form-control" required value="<?= e($r['cognome']) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Cod. Fis.</label>
                <input name="cod_fis" class="form-control" value="<?= e($r['cod_fis']) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">P.IVA</label>
                <input name="piva" class="form-control" value="<?= e($r['piva']) ?>">
              </div>

              <!-- Row 2 (contatti) – 3/4 -->
              <div class="col-md-3">
                <label class="form-label">Telefono</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                  <input name="telefono" class="form-control" value="<?= e($r['telefono']) ?>">
                </div>
              </div>
              <div class="col-md-3">
                <label class="form-label">Email</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                  <input type="email" name="email" class="form-control" value="<?= e($r['email']) ?>">
                </div>
              </div>
              <div class="col-md-3">
                <label class="form-label">PEC</label>
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-envelope-check"></i></span>
                  <input type="email" name="pec" class="form-control" value="<?= e($r['pec'] ?? '') ?>">
                </div>
              </div>
              <div class="col-md-3">
              </div>

              <!-- Row 3 -->
              <div class="col-md-3">
                <label class="form-label">Città</label>
                <input name="citta" class="form-control" value="<?= e($r['citta']) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Provincia</label>
                <input name="provincia" maxlength="2" class="form-control text-uppercase" list="dl-provincie"
                       value="<?= e($r['provincia']) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">CAP</label>
                <input name="cap" class="form-control" inputmode="numeric" maxlength="5" value="<?= e($r['cap']) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Paese di nascita</label>
                <input name="stato_nascita" class="form-control" list="dl-paesi" value="<?= e($val_birth) ?>">
              </div>

              <!-- Row 4 -->
              <div class="col-12">
                <label class="form-label">Indirizzo</label>
                <input name="indirizzo" class="form-control" value="<?= e($r['indirizzo']) ?>">
              </div>

              <!-- Row 5 -->
              <div class="col-12">
                <label class="form-label">Note</label>
                <textarea name="note" class="form-control" rows="2"><?= e($r['note']) ?></textarea>
              </div>

              <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                <button class="btn btn-primary"><i class="bi bi-check2-circle"></i> Salva modifiche</button>
              </div>
            </form>
            <?php
            $html = ob_get_clean();
            echo json_encode(['ok'=>true,'html'=>$html]); 
            exit;
        }

        /* ---- Creazione cliente ---- */
        if ($action === 'add_cliente' && $method==='POST') {
            if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');

            // lettura + normalizzazione
            $nome     = esc($connection, $_POST['nome'] ?? '');
            $cognome  = esc($connection, $_POST['cognome'] ?? '');
            if ($nome==='' || $cognome==='') throw new Exception('Nome e cognome obbligatori.');

            $cod_fis_norm = norm_cf($_POST['cod_fis'] ?? '');
            $piva_norm    = norm_piva($_POST['piva'] ?? '');

            $cod_fis  = esc($connection, $cod_fis_norm);
            $piva     = esc($connection, $piva_norm);
            $email    = esc($connection, $_POST['email'] ?? '');
            $telefono = esc($connection, $_POST['telefono'] ?? '');
            $pec      = esc($connection, $_POST['pec'] ?? '');
            $indir    = esc($connection, $_POST['indirizzo'] ?? '');
            $cap      = esc($connection, $_POST['cap'] ?? '');
            $citta    = esc($connection, $_POST['citta'] ?? '');
            $prov     = esc($connection, strtoupper($_POST['provincia'] ?? ''));
            $note     = esc($connection, $_POST['note'] ?? '');
            $stato_nascita = esc($connection, $_POST['stato_nascita'] ?? '');

            // duplicati se valorizzati
            if ($cod_fis !== '' && exists_duplicate($connection, 'cod_fis', $cod_fis)) {
                throw new Exception('Codice Fiscale già registrato.');
            }
            if ($piva !== '' && exists_duplicate($connection, 'piva', $piva)) {
                throw new Exception('Partita IVA già registrata.');
            }

            $cols = ['nome','cognome','cod_fis','piva','email','telefono','pec','indirizzo','cap','citta','provincia','note','stato'];
            $vals = ["'$nome'","'$cognome'","'$cod_fis'","'$piva'","'$email'","'$telefono'","'$pec'","'$indir'","'$cap'","'$citta'","'$prov'","'$note'","'attivo'"];
            if ($col = birth_state_col($connection)) { $cols[] = "`$col`"; $vals[] = "'$stato_nascita'"; }

            q($connection, "INSERT INTO clienti (".implode(',',$cols).") VALUES (".implode(',',$vals).")");
            $id_cliente = mysqli_insert_id($connection);

            echo json_encode(['ok'=>true,'id'=>$id_cliente,'msg'=>'Cliente creato']); exit;
        }

        /* ---- Modifica cliente ---- */
        if ($action === 'update_cliente' && $method==='POST') {
            if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
            $id = (int)($_POST['id'] ?? 0);
            if ($id<=0) throw new Exception('ID non valido.');

            $nome     = esc($connection, $_POST['nome'] ?? '');
            $cognome  = esc($connection, $_POST['cognome'] ?? '');
            if ($nome==='' || $cognome==='') throw new Exception('Nome e cognome obbligatori.');

            $cod_fis_norm = norm_cf($_POST['cod_fis'] ?? '');
            $piva_norm    = norm_piva($_POST['piva'] ?? '');
            $cod_fis  = esc($connection, $cod_fis_norm);
            $piva     = esc($connection, $piva_norm);
            $email    = esc($connection, $_POST['email'] ?? '');
            $telefono = esc($connection, $_POST['telefono'] ?? '');
            $pec      = esc($connection, $_POST['pec'] ?? '');
            $indir    = esc($connection, $_POST['indirizzo'] ?? '');
            $cap      = esc($connection, $_POST['cap'] ?? '');
            $citta    = esc($connection, $_POST['citta'] ?? '');
            $prov     = esc($connection, strtoupper($_POST['provincia'] ?? ''));
            $note     = esc($connection, $_POST['note'] ?? '');
            $stato_nascita = esc($connection, $_POST['stato_nascita'] ?? '');

            // duplicati (escludo l'ID corrente)
            if ($cod_fis !== '' && exists_duplicate($connection, 'cod_fis', $cod_fis, $id)) {
                throw new Exception('Codice Fiscale già registrato su un altro cliente.');
            }
            if ($piva !== '' && exists_duplicate($connection, 'piva', $piva, $id)) {
                throw new Exception('Partita IVA già registrata su un altro cliente.');
            }

            $sets = [];
            foreach ([
              "nome='$nome'","cognome='$cognome'","cod_fis='$cod_fis'","piva='$piva'","email='$email'",
              "telefono='$telefono'","pec='$pec'","indirizzo='$indir'","cap='$cap'","citta='$citta'","provincia='$prov'","note='$note'"
            ] as $s) $sets[] = $s;
            if ($col = birth_state_col($connection)) $sets[] = "`$col`='$stato_nascita'";

            q($connection, "UPDATE clienti SET ".implode(',', $sets)." WHERE id=$id");
            echo json_encode(['ok'=>true,'msg'=>'Modifica salvata']); exit;
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
<style>
/* Mini toast (senza X) */
.toast-mini { position: fixed; right: 1rem; bottom: 1rem; z-index: 1080; }
.toast-mini > div { background: #198754; color:#fff; border-radius: .5rem; padding:.5rem .75rem; box-shadow:0 0.25rem 1rem rgba(0,0,0,.15); }
</style>

<div class="container my-4 container-narrow">
  <div class="d-flex align-items-center justify-content-between">
    <h1 class="h4 mb-0">Clienti</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAddCliente">
      <i class="bi bi-person-plus"></i> Nuovo cliente
    </button>
  </div>

  <!-- Ricerca -->
  <form id="form-search" class="row g-2 align-items-center mt-3">
    <div class="col-md-6">
      <input type="text" name="q" class="form-control" placeholder="Cerca per Nome, Cognome, Cod. Fis., Email o PEC">
    </div>
    <div class="col-auto">
      <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Cerca</button>
    </div>
    <div class="col-auto">
      <button class="btn btn-outline-danger btn-clear" type="button"><i class="bi bi-x-circle"></i> Pulisci</button>
    </div>
  </form>

  <div id="list-area" class="mt-3"></div>
</div>

<!-- Modal: Nuovo cliente -->
<div class="modal fade" id="modalAddCliente" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form id="form-add-cliente" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Nuovo cliente</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <!-- Row 1 -->
          <div class="col-md-3"><label class="form-label">Nome *</label><input name="nome" class="form-control" required></div>
          <div class="col-md-3"><label class="form-label">Cognome *</label><input name="cognome" class="form-control" required></div>
          <div class="col-md-3"><label class="form-label">Cod. Fis.</label><input name="cod_fis" class="form-control"></div>
          <div class="col-md-3"><label class="form-label">P.IVA</label><input name="piva" class="form-control"></div>

          <!-- Row 2 (contatti) – 3/4 -->
          <div class="col-md-3">
            <label class="form-label">Telefono</label>
            <div class="input-group"><span class="input-group-text"><i class="bi bi-telephone"></i></span>
              <input name="telefono" class="form-control"></div>
          </div>
          <div class="col-md-3">
            <label class="form-label">Email</label>
            <div class="input-group"><span class="input-group-text"><i class="bi bi-envelope"></i></span>
              <input type="email" name="email" class="form-control"></div>
          </div>
          <div class="col-md-3">
            <label class="form-label">PEC</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-envelope-check"></i></span>
              <input type="email" name="pec" class="form-control" placeholder="nome@pec.it">
            </div>
          </div>
          <div class="col-md-3">
          </div>

          <!-- Row 3 -->
          <div class="col-md-3"><label class="form-label">Città</label><input name="citta" class="form-control"></div>
          <div class="col-md-3"><label class="form-label">Provincia</label><input name="provincia" maxlength="2" class="form-control text-uppercase" list="dl-provincie" placeholder="MI"></div>
          <div class="col-md-3"><label class="form-label">CAP</label><input name="cap" class="form-control" inputmode="numeric" maxlength="5"></div>
          <div class="col-md-3"><label class="form-label">Paese di nascita</label><input name="stato_nascita" class="form-control" list="dl-paesi" placeholder="Italia"></div>

          <!-- Row 4 -->
          <div class="col-12"><label class="form-label">Indirizzo</label><input name="indirizzo" class="form-control"></div>

          <!-- Row 5 -->
          <div class="col-12"><label class="form-label">Note</label><textarea name="note" class="form-control" rows="2"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Annulla</button>
        <button class="btn btn-primary"><i class="bi bi-check2-circle"></i> Crea</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Anagrafica -->
<div class="modal fade" id="modalAnagrafica" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-vcard me-2"></i>Anagrafica cliente</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body" id="anagraficaBody"></div>
    </div>
  </div>
</div>

<!-- DATALISTS (caricate da file) -->
<datalist id="dl-paesi">
  <?php foreach ($LISTA_PAESI as $p): ?>
    <option value="<?= e($p) ?>"></option>
  <?php endforeach; ?>
</datalist>

<datalist id="dl-provincie">
  <?php foreach ($LISTA_PROV as $p): ?>
    <option value="<?= e($p) ?>"></option>
  <?php endforeach; ?>
</datalist>

<script>
(() => {
  const listArea   = document.getElementById('list-area');
  const formSearch = document.getElementById('form-search');
  const formAddCli = document.getElementById('form-add-cliente');

  const modalAnagraficaEl = document.getElementById('modalAnagrafica');
  const anagraficaBody    = document.getElementById('anagraficaBody');

  /* ===== helpers ===== */
  let currentController = null;
  async function api(action, params={}, method='GET') {
    if (currentController) currentController.abort();
    currentController = new AbortController();

    const url = new URL('clienti.php', window.location.href);
    url.searchParams.set('ajax','1');
    url.searchParams.set('action', action);
    if (new URLSearchParams(window.location.search).get('debug') === '1') url.searchParams.set('debug','1');

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

  function showLoading(area) { area.innerHTML = '<div class="text-muted small">Caricamento…</div>'; }
  function flash(text) {
    const wrap = document.createElement('div');
    wrap.className = 'toast-mini';
    wrap.innerHTML = `<div>${text}</div>`;
    document.body.appendChild(wrap);
    setTimeout(() => { wrap.remove(); }, 2000);
  }

  /* ===== Lista ===== */
  let currentQuery = '';
  let currentIdFilter = null; // <- filtro per ID

  async function loadList(page=1) {
    showLoading(listArea);
    try {
      const params = { q: currentQuery, page };
      if (currentIdFilter && currentIdFilter > 0) params.id = String(currentIdFilter);
      const { html } = await api('list_clienti', params, 'GET');
      listArea.innerHTML = html;

      listArea.querySelectorAll('.link-page').forEach(a => {
        a.addEventListener('click', (e) => { e.preventDefault(); loadList(parseInt(a.dataset.page,10)||1); });
      });

      // Anagrafica (modal)
      listArea.querySelectorAll('.btn-anagrafica').forEach(btn => {
        btn.addEventListener('click', async () => {
          anagraficaBody.innerHTML = '<div class="text-muted small">Caricamento…</div>';
          new bootstrap.Modal(modalAnagraficaEl).show();
          try {
            const { ok, html } = await api('view_cliente_form', { id: btn.dataset.id }, 'GET');
            if (ok) {
              anagraficaBody.innerHTML = html;
              bindAnagrafica(anagraficaBody);
            }
          } catch (err) { anagraficaBody.innerHTML = '<div class="alert alert-danger">'+(err.message||'Errore')+'</div>'; }
        });
      });

    } catch (err) {
      listArea.innerHTML = '<div class="alert alert-danger">'+(err.message||'Errore')+'</div>';
    }
  }

  /* ===== Binder Anagrafica ===== */
  function bindAnagrafica(root) {
    const formUpdate = root.querySelector('#form-update-cliente');
    if (formUpdate) {
      formUpdate.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(formUpdate);
        fd.set('ajax','1'); fd.set('action','update_cliente');
        try {
          const { msg } = await api('update_cliente', fd, 'POST');
          flash(msg || 'Modifica salvata');
          loadList(1);
        } catch (err) { alert(err.message); }
      });
    }
  }

  /* ===== Live search ===== */
  const qInput = formSearch.q;
  let debounceTimer = null, composing = false;
  qInput.addEventListener('compositionstart', () => composing = true);
  qInput.addEventListener('compositionend', () => { composing = false; triggerSearch(); });
  qInput.addEventListener('input', () => { if (!composing) triggerSearch(); });
  function triggerSearch() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => { 
      currentQuery = qInput.value.trim(); 
      currentIdFilter = null; // se cerco manualmente, azzero filtro ID
      loadList(1); 
    }, 250);
  }
  formSearch.addEventListener('submit', (e) => { e.preventDefault(); });
  formSearch.querySelector('.btn-clear')?.addEventListener('click', () => {
    qInput.value=''; currentQuery=''; currentIdFilter=null; loadList(1); qInput.focus();
  });

  // Creazione cliente -> filtro immediato per ID
  formAddCli.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(formAddCli);
    fd.set('ajax','1'); fd.set('action','add_cliente');
    try {
      const { id, msg } = await api('add_cliente', fd, 'POST');
      window.bootstrap?.Modal?.getInstance(document.getElementById('modalAddCliente'))?.hide();
      formAddCli.reset();
      currentQuery = '';
      qInput.value = '';
      currentIdFilter = parseInt(id, 10) || null;
      await loadList(1);
      flash(msg || 'Cliente creato');
    } catch (err) { alert(err.message); }
  });

  // Primo load
  loadList(1);
})();
</script>

<?php
  include 'footer.php';
?>
