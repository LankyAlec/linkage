<?php
/************************************************************
 * clienti_cause.php — REWRITE (modali, preventivi, agenda)
 * Ultimo aggiornamento: 2025-10-11
 ************************************************************/

// ========= Mittente & intestazione preventivo (PERSONALIZZA) =========
$MAIL_FROM = 'studio@example.it';
$MAIL_FROM_NAME = 'Studio Legale Rossi';
$AVVOCATO = [
  'nome'      => 'Avv. Mario Rossi',
  'indirizzo' => 'Via Roma 1 – 20100 Milano',
  'telefono'  => '+39 02 123456',
  'email'     => 'studio@example.it',
  'pec'       => 'studio@pec.it',
  'piva'      => 'IT01234567890',
  'cf'        => 'RSSMRA80A01H501X',
];

// ===================== Rileva AJAX =====================
$IS_AJAX = (
  (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') ||
  (($_GET['ajax']  ?? '') === '1') || (($_POST['ajax'] ?? '') === '1')
);
if ($IS_AJAX) { define('AJAX_MODE', true); ob_start(); }

include 'header.php'; // -> $connection + sessione

// ===================== DEBUG =====================
if (!defined('DEBUG')) {
  $debug_from_get = isset($_GET['debug']) && $_GET['debug'] === '1';
  define('DEBUG', $debug_from_get || getenv('APP_DEBUG') === '1');
}
if (DEBUG) { ini_set('display_errors','1'); error_reporting(E_ALL); }
else { ini_set('display_errors','0'); error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED); }

// ===================== Log & error handling =====================
$LOG_DIR = __DIR__ . '/logs'; if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0777, true);
$LOG_FILE = $LOG_DIR . '/clienti_cause_rework.log';
function log_line(string $msg): void {
  global $LOG_FILE;
  $uid = $_SESSION['id_account'] ?? '-'; $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
  @file_put_contents($LOG_FILE, sprintf("[%s] [uid:%s] [ip:%s] %s\n", date('Y-m-d H:i:s'), $uid, $ip, $msg), FILE_APPEND);
}
set_error_handler(function($sev,$message,$file,$line){
  if (!(error_reporting() & $sev)) return;
  log_line("PHP ERROR $message in $file:$line");
  if (defined('AJAX_MODE')) { while (ob_get_level()>0) @ob_end_clean(); http_response_code(500); echo json_encode(['ok'=>false,'msg'=>$message]); exit; }
  if (DEBUG) throw new ErrorException($message,0,$sev,$file,$line);
  return true;
});
set_exception_handler(function(Throwable $ex){
  log_line('EXCEPTION: '.$ex->getMessage().' @ '.$ex->getFile().':'.$ex->getLine());
  if (defined('AJAX_MODE')) { while (ob_get_level()>0) @ob_end_clean(); http_response_code(500); echo json_encode(['ok'=>false,'msg'=>$ex->getMessage()]); exit; }
  echo "<div class='alert alert-danger'>Si è verificato un errore.</div>"; exit;
});

// ===================== Helpers =====================
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
function esc(mysqli $c, ?string $v): string { return mysqli_real_escape_string($c, $v ?? ''); }
function q(mysqli $c, string $sql) {
  $t0 = microtime(true); $res = mysqli_query($c, $sql);
  if (!$res) { $err = mysqli_error($c); log_line("SQL ERROR: $err | $sql"); throw new Exception('Errore DB: '.$err); }
  if (DEBUG) log_line("SQL OK (".round((microtime(true)-$t0)*1000,1)." ms): ".preg_replace('/\s+/', ' ', $sql));
  return $res;
}
function table_exists(mysqli $c, string $table): bool {
  $t = mysqli_real_escape_string($c, $table);
  $r = q($c, "SHOW TABLES LIKE '$t'"); return mysqli_num_rows($r) > 0;
}
function col_exists(mysqli $c, string $table, string $col): bool {
  $t = mysqli_real_escape_string($c, $table); $k = mysqli_real_escape_string($c, $col);
  $r = q($c, "SHOW COLUMNS FROM `$t` LIKE '$k'"); return mysqli_num_rows($r) > 0;
}
function money_e($n){ return number_format((float)$n, 2, ',', '.'); }
function fmt_it_date(?string $d): string { if(!$d || $d==='0000-00-00') return '-'; $t=strtotime($d); return $t?date('d/m/Y',$t):$d; }
function fmt_it_dt(?string $dt): string { if(!$dt) return '-'; $t=strtotime($dt); return $t?date('d/m/Y H:i',$t):$dt; }
function dedup_ok(string $action, int $ttl_sec = 3): bool {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $hash = md5(json_encode($_POST).($_SESSION['id_account'] ?? '0'));
  $_SESSION['__dedup'] = $_SESSION['__dedup'] ?? [];
  $now = microtime(true);
  $prev = $_SESSION['__dedup'][$action] ?? null;
  if ($prev && $prev['h'] === $hash && ($now - $prev['t']) < $ttl_sec) return false;
  $_SESSION['__dedup'][$action] = ['h' => $hash, 't' => $now];
  return true;
}

// ===================== Upload root =====================
$UPLOAD_BASE = __DIR__ . '/uploads'; if (!is_dir($UPLOAD_BASE)) @mkdir($UPLOAD_BASE, 0777, true);

// ===================== PDF helpers (semplicissimi) =====================
function pdf_sanitize($s){
  $map = ["€"=>"EUR", "—"=>"-", "–"=>"-", "’"=>"'", "“"=>'"', "”"=>'"', "·"=>"."];
  $s = strtr($s, $map); $t = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
  return $t!==false ? $t : $s;
}
function generate_simple_pdf(string $path, array $lines){
  $w = 595.28; $h = 841.89; $content = "BT\n/F1 12 Tf\n";
  foreach ($lines as $L) {
    [$xmm,$ymm,$size,$text] = $L;
    $text = str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], pdf_sanitize($text));
    $x = $xmm * 2.83465; $y = $h - ($ymm * 2.83465);
    $content .= sprintf("%.2f %.2f Td\n%.2f Tf\n(%s) Tj\n", $x, $y, $size, $text);
  }
  $content .= "ET"; $len = strlen($content); $parts = [];
  $parts[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
  $parts[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
  $parts[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n";
  $parts[] = "4 0 obj << /Length $len >> stream\n$content\nendstream endobj\n";
  $parts[] = "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n";
  $pdf = "%PDF-1.4\n"; $offs=[0]; foreach($parts as $p){ $offs[] = strlen($pdf); $pdf.=$p; }
  $xref = "xref\n0 ".(count($offs))."\n0000000000 65535 f \n";
  for($i=1;$i<count($offs);$i++) $xref .= sprintf("%010d 00000 n \n", $offs[$i]);
  $pdf .= $xref."trailer << /Size ".count($offs)." /Root 1 0 R >>\nstartxref\n".strlen($pdf)."\n%%EOF";
  return @file_put_contents($path,$pdf)!==false;
}

// ===================== Costanti UI =====================
$AUTORITA_LIST = [
 'Giudice di Pace','Tribunale — Sez. Civile','Tribunale — Sez. Penale',
 'Tribunale per i Minorenni','Corte d’Appello — Sez. Civile','Corte d’Appello — Sez. Penale',
 'Corte di Cassazione — Sez. Civile','Corte di Cassazione — Sez. Penale','TAR','Consiglio di Stato',
 'Commissione Tributaria Provinciale','Commissione Tributaria Regionale',
 'Tribunale di Sorveglianza','Giudice del Lavoro','Tribunale Amministrativo Regionale'
];
$CAUSE_STATUS = ['da_aprire'=>'Da aprire','aperte'=>'Aperte','sospese'=>'Sospese','chiuse'=>'Chiuse'];

/* ===================== Helpers schema/tabelle ===================== */
function ensure_cp_schema(mysqli $c): void {
  if (!table_exists($c, 'cause_controparti')) return;
  $defs = [
    'indirizzo' => "VARCHAR(255) NULL",
    'cap'       => "VARCHAR(10) NULL",
    'citta'     => "VARCHAR(120) NULL",
    'provincia' => "VARCHAR(64) NULL",
    'nazione'   => "VARCHAR(64) NULL",
    'cf_piva'   => "VARCHAR(32) NULL",
    'note'      => "TEXT NULL",
  ];
  foreach ($defs as $col => $ddl) {
    if (!col_exists($c, 'cause_controparti', $col)) {
      @q($c, "ALTER TABLE cause_controparti ADD COLUMN `$col` $ddl");
    }
  }
}
function ensure_prev_schema(mysqli $c): void {
  q($c,"CREATE TABLE IF NOT EXISTS cause_prev_docs(
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_causa INT NOT NULL,
    numero INT NOT NULL,
    data DATE NOT NULL,
    path_pdf VARCHAR(255) NOT NULL,
    imponibile DECIMAL(10,2) NULL,
    iva DECIMAL(10,2) NULL,
    totale DECIMAL(10,2) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'attesa',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_prev (id_causa,numero),
    INDEX(id_causa)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  foreach (['imponibile'=>'DECIMAL(10,2) NULL','iva'=>'DECIMAL(10,2) NULL','totale'=>'DECIMAL(10,2) NULL'] as $cc=>$ddl) {
    if (!col_exists($c,'cause_prev_docs',$cc)) @q($c,"ALTER TABLE cause_prev_docs ADD COLUMN `$cc` $ddl");
  }
  if (!col_exists($c,'cause_prev_docs','status')) {
    @q($c,"ALTER TABLE cause_prev_docs ADD COLUMN `status` VARCHAR(16) NOT NULL DEFAULT 'attesa'");
  }
  q($c,"CREATE TABLE IF NOT EXISTS cause_prev_docs_items(
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_doc INT NOT NULL,
    voce VARCHAR(255) NOT NULL,
    qty DECIMAL(10,2) NOT NULL,
    prezzo DECIMAL(10,2) NOT NULL,
    iva_perc DECIMAL(5,2) NOT NULL,
    subtot DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (id_doc) REFERENCES cause_prev_docs(id) ON DELETE CASCADE,
    INDEX(id_doc)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ===================== Parti avverse compact label ===================== */
function controparti_compatte(mysqli $c, int $id_causa): string {
  if (!table_exists($c, 'cause_controparti')) return '';
  $r = q($c, "SELECT 
      TRIM(CONCAT(COALESCE(NULLIF(CONCAT_WS(' ', nome, cognome),''), ''), 
                  CASE WHEN ragione_sociale IS NOT NULL AND ragione_sociale<>'' 
                       THEN CASE WHEN (nome<>'' OR cognome<>'') THEN CONCAT(' (',ragione_sociale,')') ELSE ragione_sociale END
                       ELSE '' END)) AS label
    FROM cause_controparti WHERE id_causa=$id_causa");
  $labels = [];
  while ($x = mysqli_fetch_assoc($r)) { $lab = trim($x['label']); if ($lab!=='') $labels[] = $lab; }
  return implode(', ', $labels);
}

/* ===================== Righe elenco cause ===================== */
function render_causa_row_slim(mysqli $c, array $causa): string {
  global $CAUSE_STATUS;
  $id = (int)$causa['id'];
  $ultimo = $causa['updated_at'] ?? $causa['created_at'] ?? '';
  $cp = controparti_compatte($c, $id);
  $cpHtml = $cp ? "<div class='small text-muted'>Parti avverse: ".e($cp)."</div>" : "";

  $statusLabel = e($CAUSE_STATUS[$causa['status']] ?? $causa['status']);
  $esitoBadge = '';
  if (($causa['status'] ?? '') === 'chiuse' && !empty($causa['esito'])) {
    $map = [
      'vinta'      => ['Vinta','success'],
      'persa'      => ['Persa','danger'],
      'pareggiata' => ['Pareggiata','secondary'],
    ];
    $eKey = strtolower($causa['esito']);
    if (isset($map[$eKey])) {
      [$lab,$cls] = $map[$eKey];
      $esitoBadge = " <span class='badge bg-$cls'>$lab</span>";
    }
  }

  return "
  <tr class='cause-row'>
    <td class='fw-semibold'>".e($causa['titolo']).$cpHtml."</td>
    <td>".e($causa['autorita'] ?: '-')."</td>
    <td>$statusLabel$esitoBadge</td>
    <td>".e($ultimo ? fmt_it_dt($ultimo) : '-')."</td>
    <td>".e($causa['numero_rg'] ?: '-')."</td>
    <td class='text-end'>
      <button class='btn btn-sm btn-outline-secondary me-1 btn-toggle' data-id='$id' title='Dettaglio'><i class='bi bi-chevron-down'></i></button>
      <button class='btn btn-sm btn-outline-primary me-1 btn-edit-causa' data-id='$id' title='Modifica'><i class='bi bi-pencil'></i></button>
      <button class='btn btn-sm btn-outline-danger btn-del-causa' data-id='$id' title='Elimina'><i class='bi bi-trash'></i></button>
    </td>
  </tr>
  <tr id='row-detail-$id' class='d-none cause-detail-row'>
    <td colspan='6'>
      <div id='causa-box-$id' class='cause-box card shadow-sm border-2 mb-5'></div>
      <div class='cause-divider'></div>
    </td>
  </tr>";
}


/* ===================== Box dettaglio causa ===================== */
function render_causa_box(mysqli $c, array $causa, array $avv, string $mail_from_name): string {
  global $UPLOAD_BASE;

  $id_causa   = (int)$causa['id'];

  // Prospetto economico su preventivo ACCETTATO (se esiste)
  ensure_prev_schema($c);
  $acc = mysqli_fetch_assoc(q($c, "SELECT id, totale FROM cause_prev_docs WHERE id_causa=$id_causa AND status='accettato' ORDER BY numero DESC LIMIT 1"));
  if ($acc) {
    $tot_prev = (float)($acc['totale'] ?? 0);
    if ($tot_prev <= 0) {
      $ri = q($c, "SELECT COALESCE(SUM(subtot*(1+iva_perc/100)),0) t FROM cause_prev_docs_items WHERE id_doc=".(int)$acc['id']);
      $tot_prev = (float)(mysqli_fetch_assoc($ri)['t'] ?? 0);
    }
  } else {
    $sum = mysqli_fetch_assoc(q($c, "SELECT COALESCE(SUM(qty*prezzo*(1+iva_perc/100)),0) t FROM cause_preventivo WHERE id_causa=$id_causa"));
    $tot_prev = (float)($sum['t'] ?? 0);
  }
  $sumv = mysqli_fetch_assoc(q($c, "SELECT COALESCE(SUM(importo),0) vers FROM cause_versamenti WHERE id_causa=$id_causa"));
  $vers = (float)($sumv['vers'] ?? 0);
  $residuo = max(0.0, $tot_prev - $vers);

  // Documenti
  $hasTipo = table_exists($c,'cause_file') && col_exists($c,'cause_file','tipo');
  $ord = (col_exists($c,'cause_file','doc_date') && col_exists($c,'cause_file','uploaded_at'))
          ? 'COALESCE(doc_date, uploaded_at)' : (col_exists($c,'cause_file','doc_date') ? 'doc_date'
          : (col_exists($c,'cause_file','uploaded_at') ? 'uploaded_at' : 'id'));
  if ($hasTipo) {
    $docs_cli = q($c, "SELECT * FROM cause_file WHERE id_causa=$id_causa AND (tipo='cliente' OR tipo IS NULL) ORDER BY $ord DESC");
    $docs_in  = q($c, "SELECT * FROM cause_file WHERE id_causa=$id_causa AND tipo='inviato' ORDER BY $ord DESC");
    $docs_rcv = q($c, "SELECT * FROM cause_file WHERE id_causa=$id_causa AND tipo='ricevuto' ORDER BY $ord DESC");
    $docs_timeline = q($c, "SELECT id,tipo,nome_originale,path_relativo,nota,
                                   COALESCE(doc_date,uploaded_at) AS eff_date
                             FROM cause_file
                             WHERE id_causa=$id_causa AND tipo IN ('inviato','ricevuto')
                             ORDER BY eff_date ASC, id ASC");
  }

  // Agenda
  if (!table_exists($c,'cause_agenda')) {
    q($c,"CREATE TABLE IF NOT EXISTS cause_agenda (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_causa INT NOT NULL,
        start_dt DATETIME NOT NULL,
        tipo VARCHAR(32) NOT NULL,
        luogo VARCHAR(255) DEFAULT NULL,
        note TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(id_causa), INDEX(start_dt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
  $agenda = q($c, "SELECT * FROM cause_agenda WHERE id_causa=$id_causa ORDER BY start_dt ASC");
  $next = mysqli_fetch_assoc(q($c, "SELECT * FROM cause_agenda WHERE id_causa=$id_causa AND start_dt>=NOW() ORDER BY start_dt ASC LIMIT 1"));

  $desc = $causa['descrizione'] ?? '';

  // Preventivi generati
  $docsPrev = q($c, "SELECT * FROM cause_prev_docs WHERE id_causa=$id_causa ORDER BY numero DESC");

  // Controparti
  $hasCP = table_exists($c,'cause_controparti'); if ($hasCP) { ensure_cp_schema($c); $rcp = q($c, "SELECT * FROM cause_controparti WHERE id_causa=$id_causa ORDER BY id ASC"); }

  ob_start(); ?>

  <?php if (!defined('CAUSE_DOC_TABLE_CSS')) { define('CAUSE_DOC_TABLE_CSS', true); ?>
  <style>
    /* Cronostoria: tabella moderna */
    .doc-chrono-wrap { max-height: 60vh; overflow: auto; border-radius:.5rem; }
    .doc-chrono thead th { position: sticky; top: 0; z-index: 1; }
    .doc-chrono .date-chip{
      display:inline-flex; align-items:center; gap:.4rem;
      color:#2b2f33; background:var(--bs-light);
      border-radius:999px; padding:.25rem .6rem; white-space:nowrap;
    }
    .doc-chrono .date-chip .bi { opacity:.7; }
    .doc-chrono .badge-pill{
      display:inline-flex; align-items:center; gap:.35rem;
      padding:.25rem .55rem; border-radius:999px;
    }
    .doc-chrono .badge-inv { background:rgba(var(--bs-primary-rgb), .10); color:var(--bs-primary); }
    .doc-chrono .badge-rcv { background:rgba(var(--bs-success-rgb), .12); color:var(--bs-success); }
    .doc-chrono .file-link { max-width: 46vw; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:inline-block; }
    @media (min-width: 1200px){ .doc-chrono .file-link { max-width: 640px; } }
    .doc-chrono .note { color:var(--bs-secondary-color); max-width: 28vw; white-space:nowrap; overflow:hidden;}
    .doc-chrono tbody tr:hover { background: rgba(0,0,0,.02); }
    .doc-chrono .btn-icon { padding:.2rem .45rem; }
  </style>
  <?php } ?>


  <div class="card-body p-3 p-md-4">

    <!-- RECAP -->
    <div class="row g-3 mb-3">
      <div class="col-lg-8">
        <div class="card h-100 shadow-sm">
          <div class="card-header bg-light fw-semibold"><i class="bi bi-calendar-event me-1"></i> Prossimo evento</div>
          <div class="card-body">
            <?php if ($next): $ts = strtotime($next['start_dt']);
              $months = ['Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
              $wdShort = ['DOM','LUN','MAR','MER','GIO','VEN','SAB'][(int)date('w',$ts)]; ?>
              <div class="d-flex align-items-center">
                <div class="me-3 text-center" style="width:84px;border-radius:18px;background:#0d6efd;color:#fff;padding:10px 0;">
                  <div style="font-size:10px;font-weight:700;letter-spacing:.08em"><?= e($wdShort) ?></div>
                  <div style="font-size:42px;font-weight:800;line-height:.9"><?= (int)date('j',$ts) ?></div>
                  <div style="font-size:11px;letter-spacing:.08em"><?= e($months[(int)date('n',$ts)-1]) ?></div>
                </div>
                <div class="flex-grow-1">
                  <div class="h5 mb-1"><?= e($next['tipo']) ?></div>
                  <div class="text-muted"><?= e(date('d/m/Y H:i', $ts)) ?><?= $next['luogo']?' · '.e($next['luogo']):'' ?></div>
                </div>
              </div>
            <?php else: ?>
              <div class="text-muted">Nessun evento futuro definito.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card h-100 shadow-sm">
          <div class="card-header bg-light fw-semibold"><i class="bi bi-receipt me-1"></i> Prospetto economico</div>
          <div class="card-body">
            <div class="d-flex justify-content-between"><span>Totale preventivo</span><span><b>€ <?= money_e($tot_prev) ?></b></span></div>
            <div class="d-flex justify-content-between"><span>Versato</span><span><b>€ <?= money_e($vers) ?></b></span></div>
            <hr class="my-2">
            <div class="d-flex justify-content-between align-items-center">
              <span class="fw-semibold">Residuo</span>
              <span class="badge bg-<?= $residuo>0?'warning text-dark':'success' ?> fs-6">€ <?= money_e($residuo) ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- NOTE -->
    <div class="card mb-3 shadow-sm">
      <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-journal-text me-1"></i> Note</span>
        <button class="btn btn-sm btn-outline-secondary btn-note-edit-modal" data-id="<?= $id_causa ?>">
          <i class="bi bi-pencil"></i>
        </button>
      </div>
      <div class="card-body">
        <div id="notesView-<?= $id_causa ?>" class="<?= $desc!=='' ? '' : 'text-muted' ?>">
          <?= $desc!=='' ? nl2br(e($desc)) : 'Nessuna nota.' ?>
        </div>
      </div>
    </div>

    <!-- PARTI AVVERSE -->
    <div class="card mb-3 shadow-sm">
      <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people me-1"></i> Parti avverse</span>
        <button class="btn btn-sm btn-primary btn-open-add-cp" data-causa="<?= $id_causa ?>"><i class="bi bi-plus-circle"></i> Aggiungi</button>
      </div>
      <div class="card-body">
        <?php if ($hasCP): ?>
          <div class="table-responsive mb-2">
            <table class="table table-sm align-middle">
              <thead class="table-light"><tr><th>Parte</th><th>Contatto</th><th>Avvocato</th><th class="text-end" style="width:160px"></th></tr></thead>
              <tbody>
                <?php if (mysqli_num_rows($rcp)===0): ?>
                  <tr><td colspan="4" class="text-muted">Nessuna controparte.</td></tr>
                <?php else: while ($cp = mysqli_fetch_assoc($rcp)):
                  $parte = ($cp['ragione_sociale'] ?: trim(($cp['nome'] ?? '').' '.($cp['cognome'] ?? ''))) ?: '—';
                  $cont_parte = !empty($cp['pec']) ? 'PEC: '.e($cp['pec']) :
                                (!empty($cp['email']) ? e($cp['email']) :
                                (!empty($cp['telefono']) ? 'Tel: '.e($cp['telefono']) : '—'));
                  $avv_nome = trim(($cp['avv_nome'] ?? '').' '.($cp['avv_cognome'] ?? ''));
                  $avv_label = $avv_nome ? e($avv_nome) : '—';
                  $cont_avv = '';
                  if (!empty($cp['avv_pec']))          $cont_avv = ' · PEC: '.e($cp['avv_pec']);
                  elseif (!empty($cp['avv_email']))    $cont_avv = ' · '.e($cp['avv_email']);
                  elseif (!empty($cp['avv_telefono'])) $cont_avv = ' · '.e($cp['avv_telefono']);
                ?>
                <tr>
                  <td><?= e($parte) ?></td>
                  <td><?= $cont_parte ?></td>
                  <td><?= $avv_label ?><?= $cont_avv ?></td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <button class="btn btn-outline-secondary btn-view-cp" data-id="<?= (int)$cp['id'] ?>"><i class="bi bi-pencil"></i></button>
                      <button class="btn btn-outline-danger btn-del-cp" data-id="<?= (int)$cp['id'] ?>"><i class="bi bi-trash"></i></button>
                    </div>
                  </td>
                </tr>
                <?php endwhile; endif; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="alert alert-warning mb-0">Tabella <code>cause_controparti</code> mancante.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- PREVENTIVI (PDF) -->
    <div class="card mb-3 shadow-sm">
      <div class="card-header bg-light fw-semibold d-flex align-items-center justify-content-between">
        <div><i class="bi bi-files me-1"></i> Preventivi (PDF)</div>
        <button class="btn btn-sm btn-primary btn-prev-open-add" data-causa="<?= $id_causa ?>">
          <i class="bi bi-plus-circle"></i> Aggiungi
        </button>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>#</th>
                <th>File</th>
                <th class="text-end">Totale</th>
                <th>Data</th>
                <th>Status</th>
                <th class="text-end">Azioni</th>
              </tr>
            </thead>
            <tbody>
              <?php if (mysqli_num_rows($docsPrev)===0): ?>
                <tr><td colspan="6" class="text-muted">Nessun PDF generato.</td></tr>
              <?php else: while ($d = mysqli_fetch_assoc($docsPrev)): ?>
                <tr>
                  <td><?= (int)$d['numero'] ?></td>
                  <td><a target="_blank" href="<?= e($d['path_pdf']) ?>"><?= basename($d['path_pdf']) ?></a></td>
                  <td class="text-end"><?= $d['totale']!==null ? '€ '.money_e($d['totale']) : '—' ?></td>
                  <td><?= e(fmt_it_date($d['data'])) ?></td>
                  <td>
                    <?php $st = $d['status'] ?: 'attesa';
                      $badge = ($st==='accettato') ? 'success' : (($st==='non_accettato') ? 'secondary' : 'warning text-dark'); ?>
                    <span class="badge bg-<?= $badge ?>"><?= ucfirst(e($st)) ?></span>
                  </td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <button class="btn btn-outline-info btn-prevdoc-detail" data-iddoc="<?= (int)$d['id'] ?>"><i class="bi bi-eye"></i> Dettagli</button>
                      <button class="btn btn-outline-danger btn-prevdoc-del" data-iddoc="<?= (int)$d['id'] ?>"><i class="bi bi-trash"></i> Elimina</button>
                    </div>
                  </td>
                </tr>
              <?php endwhile; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- DOCUMENTI (Cronostoria default attiva) -->
    <div class="card mb-3 shadow-sm">
      <div class="card-header bg-light fw-semibold"><i class="bi bi-folder2-open me-1"></i> Documenti</div>
      <div class="card-body">
        <?php if ($hasTipo): ?>
          <?php
            $cnt_cli = mysqli_num_rows($docs_cli);
            $cnt_in  = mysqli_num_rows($docs_in);
            $cnt_rcv = mysqli_num_rows($docs_rcv);
          ?>
          <ul class="nav nav-tabs mb-3" role="tablist" data-causa="<?= $id_causa ?>">
            <li class="nav-item">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cli-<?= $id_causa ?>" type="button">
                Forniti dal cliente <span class="badge bg-secondary"><?= $cnt_cli ?></span>
              </button>
            </li>
            <li class="nav-item">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-inv-<?= $id_causa ?>" type="button">
                Inviati <span class="badge bg-secondary"><?= $cnt_in ?></span>
              </button>
            </li>
            <li class="nav-item">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-rcv-<?= $id_causa ?>" type="button">
                Ricevuti <span class="badge bg-secondary"><?= $cnt_rcv ?></span>
              </button>
            </li>
            <li class="nav-item">
              <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-crono-<?= $id_causa ?>" type="button">
                <i class="bi bi-clock-history me-1"></i> Cronostoria
              </button>
            </li>
          </ul>

          <div class="tab-content">
            <!-- CLIENTE -->
            <div class="tab-pane fade" id="tab-cli-<?= $id_causa ?>">
              <form class="row g-2 align-items-center form-upload-doc mb-3" data-causa="<?= $id_causa ?>" data-tipo="cliente">
                <input type="hidden" name="action" value="upload_doc">
                <input type="hidden" name="id_causa" value="<?= $id_causa ?>">
                <input type="hidden" name="tipo" value="cliente">
                <div class="col-md-5"><input class="form-control" type="file" name="file" required></div>
                <div class="col-md-3"><input type="date" class="form-control" name="doc_date"></div>
                <div class="col-md-2 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-upload"></i> Carica</button></div>
                <div class="col-md-12"><input class="form-control" name="nota" placeholder="Nota (facoltativa)"></div>
              </form>
              <ul class="list-group">
                <?php mysqli_data_seek($docs_cli,0);
                if (!$cnt_cli) echo "<li class='list-group-item text-muted'>Nessun file.</li>";
                while ($f = mysqli_fetch_assoc($docs_cli)):
                  $url = e($f['path_relativo']); ?>
                  <li class="list-group-item d-flex align-items-center">
                    <i class="bi bi-file-earmark-text me-2"></i>
                    <div class="flex-grow-1">
                      <a href="<?= $url ?>" target="_blank"><?= e($f['nome_originale']) ?></a>
                      <div class="small text-muted">Data: <?= e($f['doc_date'] ? fmt_it_date($f['doc_date']) : '-') ?> · Nota: <?= e($f['nota'] ?: '—') ?></div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary me-1 btn-edit-file" data-id="<?= (int)$f['id'] ?>"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-del-file" data-id="<?= (int)$f['id'] ?>"><i class="bi bi-trash"></i></button>
                  </li>
                <?php endwhile; ?>
              </ul>
            </div>

            <!-- INVIATI -->
            <div class="tab-pane fade" id="tab-inv-<?= $id_causa ?>">
              <form class="row g-2 align-items-center form-upload-doc mb-3" data-causa="<?= $id_causa ?>" data-tipo="inviato">
                <input type="hidden" name="action" value="upload_doc">
                <input type="hidden" name="id_causa" value="<?= $id_causa ?>">
                <input type="hidden" name="tipo" value="inviato">
                <div class="col-md-5"><input class="form-control" type="file" name="file" required></div>
                <div class="col-md-3"><input type="date" class="form-control" name="doc_date"></div>
                <div class="col-md-2 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-upload"></i> Aggiungi</button></div>
                <div class="col-md-12"><input class="form-control" name="nota" placeholder="Nota (facoltativa)"></div>
              </form>
              <ul class="list-group">
                <?php mysqli_data_seek($docs_in,0);
                if (!$cnt_in) echo "<li class='list-group-item text-muted'>Nessun file.</li>";
                while ($f = mysqli_fetch_assoc($docs_in)):
                  $url = e($f['path_relativo']); ?>
                  <li class="list-group-item d-flex align-items-center">
                    <i class="bi bi-file-earmark-arrow-up me-2"></i>
                    <div class="flex-grow-1">
                      <a href="<?= $url ?>" target="_blank"><?= e($f['nome_originale']) ?></a>
                      <div class="small text-muted">Data: <?= e($f['doc_date'] ? fmt_it_date($f['doc_date']) : '-') ?> · Nota: <?= e($f['nota'] ?: '—') ?></div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary me-1 btn-edit-file" data-id="<?= (int)$f['id'] ?>"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-del-file" data-id="<?= (int)$f['id'] ?>"><i class="bi bi-trash"></i></button>
                  </li>
                <?php endwhile; ?>
              </ul>
            </div>

            <!-- RICEVUTI -->
            <div class="tab-pane fade" id="tab-rcv-<?= $id_causa ?>">
              <form class="row g-2 align-items-center form-upload-doc mb-3" data-causa="<?= $id_causa ?>" data-tipo="ricevuto">
                <input type="hidden" name="action" value="upload_doc">
                <input type="hidden" name="id_causa" value="<?= $id_causa ?>">
                <input type="hidden" name="tipo" value="ricevuto">
                <div class="col-md-5"><input class="form-control" type="file" name="file" required></div>
                <div class="col-md-3"><input type="date" class="form-control" name="doc_date"></div>
                <div class="col-md-2 d-grid"><button class="btn btn-outline-primary"><i class="bi bi-upload"></i> Aggiungi</button></div>
                <div class="col-md-12"><input class="form-control" name="nota" placeholder="Nota (facoltativa)"></div>
              </form>
              <ul class="list-group">
                <?php mysqli_data_seek($docs_rcv,0);
                if (!$cnt_rcv) echo "<li class='list-group-item text-muted'>Nessun file.</li>";
                while ($f = mysqli_fetch_assoc($docs_rcv)):
                  $url = e($f['path_relativo']); ?>
                  <li class="list-group-item d-flex align-items-center">
                    <i class="bi bi-file-earmark-arrow-down me-2"></i>
                    <div class="flex-grow-1">
                      <a href="<?= $url ?>" target="_blank"><?= e($f['nome_originale']) ?></a>
                      <div class="small text-muted">Data: <?= e($f['doc_date'] ? fmt_it_date($f['doc_date']) : '-') ?> · Nota: <?= e($f['nota'] ?: '—') ?></div>
                    </div>
                    <button class="btn btn-sm btn-outline-secondary me-1 btn-edit-file" data-id="<?= (int)$f['id'] ?>"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-danger btn-del-file" data-id="<?= (int)$f['id'] ?>"><i class="bi bi-trash"></i></button>
                  </li>
                <?php endwhile; ?>
              </ul>
            </div>

            <!-- CRONOSTORIA -->
            <div class="tab-pane fade show active" id="tab-crono-<?= $id_causa ?>">
              <div class="doc-chrono-wrap">
                <table class="table table-sm align-middle doc-chrono">
                  <thead class="table-light">
                    <tr>
                      <th style="width:140px">Data</th>
                      <th style="width:120px">Tipo</th>
                      <th>File</th>
                      <th>Note</th>
                      <th class="text-end" style="width:110px">Azioni</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                    if (!isset($docs_timeline) || mysqli_num_rows($docs_timeline)===0):
                  ?>
                    <tr><td colspan="5" class="text-muted">Nessun documento in cronostoria.</td></tr>
                  <?php
                    else:
                      mysqli_data_seek($docs_timeline,0);
                      while ($f = mysqli_fetch_assoc($docs_timeline)):
                        $idFile = (int)$f['id'];
                        $tipo   = ($f['tipo']==='inviato') ? 'Inviato' : 'Ricevuto';
                        $badgeC = ($f['tipo']==='inviato') ? 'badge-inv' : 'badge-rcv';
                        $icon   = ($f['tipo']==='inviato') ? 'bi-box-arrow-up-right' : 'bi-download';
                        $dt     = $f['eff_date'] ? date('d/m/Y', strtotime($f['eff_date'])) : '—';
                        $url    = e($f['path_relativo']);
                        $name   = e($f['nome_originale']);
                        $nota   = e($f['nota'] ?: '—');
                  ?>
                    <tr data-file-id="<?= $idFile ?>">
                      <td>
                        <span class="date-chip"><i class="bi bi-calendar-event"></i><?= $dt ?></span>
                      </td>
                      <td>
                        <span class="badge-pill <?= $badgeC ?>">
                          <i class="bi <?= $icon ?>"></i> <?= $tipo ?>
                        </span>
                      </td>
                      <td>
                        <a class="file-link" href="<?= $url ?>" target="_blank" title="<?= $name ?>"><?= $name ?></a>
                      </td>
                      <td><span class="note" title="<?= $nota ?>"><?= $nota ?></span></td>
                      <td class="text-end">
                        <div class="btn-group btn-group-sm" role="group">
                          <button class="btn btn-outline-secondary btn-icon btn-edit-file" data-id="<?= $idFile ?>" title="Modifica">
                            <i class="bi bi-pencil"></i>
                          </button>
                          <button class="btn btn-outline-danger btn-icon btn-del-file" data-id="<?= $idFile ?>" title="Elimina">
                            <i class="bi bi-trash"></i>
                          </button>
                        </div>
                      </td>
                    </tr>
                  <?php
                      endwhile;
                    endif;
                  ?>
                  </tbody>
                </table>
              </div>
            </div>

          </div>
        <?php else: ?>
          <div class="alert alert-warning mb-0">Tabella <code>cause_file</code> senza campo <code>tipo</code> (le schede non possono essere mostrate).</div>
        <?php endif; ?>
      </div>
    </div>


    <!-- AGENDA completa -->
    <div class="card shadow-sm">
      <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar2-week me-1"></i> Agenda</span>
        <button class="btn btn-sm btn-primary btn-open-add-evt" data-causa="<?= $id_causa ?>"><i class="bi bi-plus-circle"></i> Nuovo evento</button>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr><th>Data/Ora</th><th>Tipo</th><th>Luogo</th><th>Note</th><th class="text-end"></th></tr>
            </thead>
            <tbody>
              <?php if (mysqli_num_rows($agenda)===0): ?>
                <tr><td colspan="5" class="text-muted">Nessun evento in agenda.</td></tr>
              <?php else: while ($ev = mysqli_fetch_assoc($agenda)): ?>
                <tr>
                  <td><?= e(date('d/m/Y H:i', strtotime($ev['start_dt']))) ?></td>
                  <td><?= e($ev['tipo']) ?></td>
                  <td><?= e($ev['luogo'] ?: '—') ?></td>
                  <td><?= e($ev['note'] ?: '—') ?></td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <button class="btn btn-outline-secondary btn-edit-evt" data-id="<?= (int)$ev['id'] ?>"><i class="bi bi-pencil"></i></button>
                      <button class="btn btn-outline-danger btn-del-evt" data-id="<?= (int)$ev['id'] ?>"><i class="bi bi-trash"></i></button>
                    </div>
                  </td>
                </tr>
              <?php endwhile; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
  <?php
  return ob_get_clean();
}

/* ===================== API AJAX ===================== */
if (defined('AJAX_MODE')) {
  while (ob_get_level() > 0) { @ob_end_clean(); }
  header('Content-Type: application/json; charset=utf-8');

  try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    // ---- Utils lato server ----
    $canonTipo = function(string $t): string {
      $t = mb_strtolower(trim($t));
      if (strpos($t,'udien')!==false) return 'udienza';
      if (strpos($t,'scad')!==false)  return 'scadenza';
      if (strpos($t,'appun')!==false) return 'appuntamento';
      return in_array($t,['udienza','scadenza','appuntamento','altro'], true) ? $t : 'altro';
    };

    // ---- Causa ----
    if ($action === 'load_page') {
      $id_cliente = (int)($_GET['id_cliente'] ?? 0); if ($id_cliente<=0) throw new Exception('Cliente non valido.');
      $cli = mysqli_fetch_assoc(q($connection, "SELECT * FROM clienti WHERE id=$id_cliente")); if (!$cli) throw new Exception('Cliente non trovato.');
      $hasUpd = col_exists($connection, 'cause', 'updated_at'); $hasCre = col_exists($connection, 'cause', 'created_at');
      if ($hasUpd && $hasCre)      { $orderBy = 'updated_at DESC, created_at DESC'; }
      elseif ($hasUpd)             { $orderBy = 'updated_at DESC, id DESC'; }
      elseif ($hasCre)             { $orderBy = 'created_at DESC, id DESC'; }
      else                         { $orderBy = 'id DESC'; }
      $rc = q($connection, "SELECT * FROM cause WHERE id_cliente=$id_cliente ORDER BY $orderBy");

      ob_start(); ?>
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <div class="h5 mb-1">Cause di <?= e($cli['nome'].' '.$cli['cognome']) ?></div>
          <div class="small text-muted"><?= e($cli['email'] ?: '-') ?> · <?= e($cli['telefono'] ?: '-') ?></div>
        </div>
        <div class="d-flex gap-2">
          <a href="clienti.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Torna ai clienti</a>
          <button class="btn btn-primary" id="btnShowNewCausa"><i class="bi bi-plus-circle"></i> Nuova causa</button>
        </div>
      </div>

      <div class="table-responsive mt-3">
        <table class="table align-middle cause-table">
          <thead class="table-light">
            <tr><th>Titolo</th><th>Autorità</th><th>Stato</th><th>Ultimo agg.</th><th>RG</th><th class="text-end">Azioni</th></tr>
          </thead>
          <tbody id="tbody-cause">
            <?php while ($ca = mysqli_fetch_assoc($rc)) echo render_causa_row_slim($connection, $ca); ?>
          </tbody>
        </table>
      </div>
      <?php
      echo json_encode(['ok'=>true,'html'=>ob_get_clean()]); exit;
    }

    if ($action === 'load_causa_box') {
      $id_causa = (int)($_GET['id_causa'] ?? 0); if ($id_causa<=0) throw new Exception('ID causa non valido.');
      $r = q($connection, "SELECT * FROM cause WHERE id=$id_causa LIMIT 1");
      $causa = mysqli_fetch_assoc($r); if (!$causa) throw new Exception('Causa non trovata.');
      echo json_encode(['ok'=>true,'html'=>render_causa_box($connection,$causa,$AVVOCATO,$MAIL_FROM_NAME)]); exit;
    }

    if ($action === 'save_desc' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id_causa = (int)($_POST['id_causa'] ?? 0); if ($id_causa<=0) throw new Exception('ID causa non valido.');
      $desc = esc($connection, $_POST['descrizione'] ?? '');
      q($connection, "UPDATE cause SET descrizione='$desc' WHERE id=$id_causa");
      echo json_encode(['ok'=>true,'msg'=>'Note salvate']); exit;
    }

    /* ---- Cause (CRUD) ---- */
    if (in_array($action, ['add_causa','get_causa','update_causa','delete_causa'], true)) {
      // Tabella base
      q($connection,"CREATE TABLE IF NOT EXISTS cause(
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_cliente INT NOT NULL,
        titolo VARCHAR(255) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'da_aprire',
        autorita VARCHAR(255) NULL,
        numero_rg VARCHAR(64) NULL,
        data_inizio DATE NULL,
        esito VARCHAR(16) NULL,                  -- <--- NEW
        descrizione MEDIUMTEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(id_cliente)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      // Se la colonna esito non c'è (DB già esistente), aggiungila senza rompere nulla
      if (!col_exists($connection, 'cause', 'esito')) {
        @q($connection, "ALTER TABLE cause ADD COLUMN esito VARCHAR(16) NULL AFTER data_inizio");
      }
    }

    if ($action === 'add_causa' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id_cliente = (int)($_POST['id_cliente'] ?? 0); if ($id_cliente<=0) throw new Exception('Cliente mancante.');
      $titolo = esc($connection, $_POST['titolo'] ?? ''); if ($titolo==='') throw new Exception('Titolo obbligatorio.');
      $allowed = ['da_aprire','aperte','sospese','chiuse'];
      $status = in_array(($_POST['status'] ?? ''), $allowed, true) ? $_POST['status'] : 'aperte';
      $autorita   = esc($connection, $_POST['autorita']   ?? '');
      $numero_rg  = esc($connection, $_POST['numero_rg']  ?? '');
      $data_inizio= esc($connection, $_POST['data_inizio']?? '');

      // esito vale solo se chiuse
      $allowed_esito = ['vinta','persa','pareggiata'];
      $esito_in = ($_POST['esito'] ?? '');
      $esito = ($status==='chiuse' && in_array($esito_in,$allowed_esito,true)) ? $esito_in : null;

      q($connection, "INSERT INTO cause (id_cliente,titolo,status,autorita,numero_rg,data_inizio,esito)
                      VALUES ($id_cliente,'$titolo','$status',".
                        ($autorita? "'$autorita'":"NULL").",".
                        ($numero_rg?"'$numero_rg'":"NULL").",".
                        ($data_inizio?"'$data_inizio'":"NULL").",".
                        ($esito? "'$esito'":"NULL").")");
      echo json_encode(['ok'=>true,'msg'=>'Causa creata','id'=>(int)mysqli_insert_id($connection)]); exit;
    }

    if ($action === 'get_causa') {
      $id_causa = (int)($_GET['id_causa'] ?? 0); if ($id_causa<=0) throw new Exception('ID causa non valido.');
      $r = q($connection, "SELECT * FROM cause WHERE id=$id_causa LIMIT 1");
      $row = mysqli_fetch_assoc($r); if (!$row) throw new Exception('Causa non trovata.');
      echo json_encode(['ok'=>true,'row'=>$row]); exit;
    }

    if ($action === 'update_causa' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id_causa = (int)($_POST['id_causa'] ?? 0); if ($id_causa<=0) throw new Exception('ID causa non valido.');
      $allowed = ['da_aprire','aperte','sospese','chiuse'];
      $allowed_esito = ['vinta','persa','pareggiata'];

      $titolo = esc($connection, $_POST['titolo'] ?? '');
      $status = in_array(($_POST['status'] ?? ''), $allowed, true) ? $_POST['status'] : 'aperte';
      $autorita   = esc($connection, $_POST['autorita']   ?? '');
      $numero_rg  = esc($connection, $_POST['numero_rg']  ?? '');
      $data_inizio= esc($connection, $_POST['data_inizio']?? '');
      $esito_in   = $_POST['esito'] ?? '';

      // Se non è chiusa => esito NULL. Se è chiusa, valida l’esito.
      $esito_sql = ($status==='chiuse' && in_array($esito_in,$allowed_esito,true)) ? "'".esc($connection,$esito_in)."'" : "NULL";

      $sets = [
        "titolo='$titolo'",
        "status='$status'",
        "autorita="  . ($autorita  ? "'$autorita'":"NULL"),
        "numero_rg=" . ($numero_rg ? "'$numero_rg'":"NULL"),
        "data_inizio=". ($data_inizio? "'$data_inizio'":"NULL"),
        "esito=".$esito_sql
      ];
      q($connection, "UPDATE cause SET ".implode(',', $sets)." WHERE id=$id_causa");
      echo json_encode(['ok'=>true,'msg'=>'Causa aggiornata']); exit;
    }

    if ($action === 'delete_causa' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id_causa = (int)($_POST['id_causa'] ?? 0); if ($id_causa<=0) throw new Exception('ID causa non valido.');

      // (cleanup collegati come già avevamo)
      if (table_exists($connection,'cause_file')) {
        $rf = q($connection,"SELECT path_relativo FROM cause_file WHERE id_causa=$id_causa");
        while ($f = mysqli_fetch_assoc($rf)) { $abs = __DIR__.'/'.$f['path_relativo']; if (is_file($abs)) @unlink($abs); }
        q($connection,"DELETE FROM cause_file WHERE id_causa=$id_causa");
      }
      if (table_exists($connection,'cause_agenda'))       q($connection,"DELETE FROM cause_agenda WHERE id_causa=$id_causa");
      if (table_exists($connection,'cause_controparti'))  q($connection,"DELETE FROM cause_controparti WHERE id_causa=$id_causa");
      if (table_exists($connection,'cause_preventivo'))   q($connection,"DELETE FROM cause_preventivo WHERE id_causa=$id_causa");
      if (table_exists($connection,'cause_prev_docs')) {
        q($connection,"DELETE i FROM cause_prev_docs_items i JOIN cause_prev_docs d ON d.id=i.id_doc WHERE d.id_causa=$id_causa");
        q($connection,"DELETE FROM cause_prev_docs WHERE id_causa=$id_causa");
      }
      if (table_exists($connection,'cause_versamenti'))   q($connection,"DELETE FROM cause_versamenti WHERE id_causa=$id_causa");

      q($connection,"DELETE FROM cause WHERE id=$id_causa");
      echo json_encode(['ok'=>true,'msg'=>'Causa eliminata']); exit;
    }



    // ---- Controparti ----
    if (in_array($action, ['add_controparte','get_controparte','update_controparte','delete_controparte'], true)) {
      q($connection,"CREATE TABLE IF NOT EXISTS cause_controparti(
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_causa INT NOT NULL,
        ragione_sociale VARCHAR(255) NULL,
        nome VARCHAR(120) NULL,
        cognome VARCHAR(120) NULL,
        cf_piva VARCHAR(32) NULL,
        pec VARCHAR(190) NULL,
        email VARCHAR(190) NULL,
        telefono VARCHAR(64) NULL,
        indirizzo VARCHAR(255) NULL,
        cap VARCHAR(10) NULL,
        citta VARCHAR(120) NULL,
        provincia VARCHAR(64) NULL,
        nazione VARCHAR(64) NULL,
        avv_nome VARCHAR(120) NULL,
        avv_cognome VARCHAR(120) NULL,
        avv_pec VARCHAR(190) NULL,
        avv_email VARCHAR(190) NULL,
        avv_telefono VARCHAR(64) NULL,
        note TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(id_causa)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if ($action === 'add_controparte' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id_causa = (int)($_POST['id_causa'] ?? 0); if ($id_causa<=0) throw new Exception('Causa mancante.');
      $F = [];
      foreach (['ragione_sociale','nome','cognome','cf_piva','pec','email','telefono','indirizzo','cap','citta','provincia','nazione','avv_nome','avv_cognome','avv_pec','avv_email','avv_telefono','note'] as $k) $F[$k] = esc($connection, $_POST[$k] ?? '');
      q($connection,"INSERT INTO cause_controparti (id_causa,ragione_sociale,nome,cognome,cf_piva,pec,email,telefono,indirizzo,cap,citta,provincia,nazione,avv_nome,avv_cognome,avv_pec,avv_email,avv_telefono,note)
                     VALUES ($id_causa,'{$F['ragione_sociale']}','{$F['nome']}','{$F['cognome']}','{$F['cf_piva']}','{$F['pec']}','{$F['email']}','{$F['telefono']}','{$F['indirizzo']}','{$F['cap']}','{$F['citta']}','{$F['provincia']}','{$F['nazione']}','{$F['avv_nome']}','{$F['avv_cognome']}','{$F['avv_pec']}','{$F['avv_email']}','{$F['avv_telefono']}','{$F['note']}')");
      echo json_encode(['ok'=>true,'msg'=>'Parte avversa aggiunta']); exit;
    }
    if ($action === 'get_controparte') {
      $id = (int)($_GET['id'] ?? 0); if ($id<=0) throw new Exception('ID non valido.');
      $r = q($connection,"SELECT * FROM cause_controparti WHERE id=$id"); $row=mysqli_fetch_assoc($r);
      if (!$row) throw new Exception('Controparte non trovata.');
      echo json_encode(['ok'=>true,'row'=>$row]); exit;
    }
    if ($action === 'update_controparte' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id = (int)($_POST['id'] ?? 0); if ($id<=0) throw new Exception('ID non valido.');
      $sets=[];
      foreach (['ragione_sociale','nome','cognome','cf_piva','pec','email','telefono','indirizzo','cap','citta','provincia','nazione','avv_nome','avv_cognome','avv_pec','avv_email','avv_telefono','note'] as $k) {
        $v = esc($connection, $_POST[$k] ?? '');
        $sets[] = "$k=" . ($v!==''?"'$v'":"NULL");
      }
      q($connection,"UPDATE cause_controparti SET ".implode(',',$sets)." WHERE id=$id");
      echo json_encode(['ok'=>true,'msg'=>'Parte avversa aggiornata']); exit;
    }
    if ($action === 'delete_controparte' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id = (int)($_POST['id'] ?? 0); if ($id<=0) throw new Exception('ID non valido.');
      q($connection,"DELETE FROM cause_controparti WHERE id=$id");
      echo json_encode(['ok'=>true,'msg'=>'Parte avversa eliminata']); exit;
    }

    // ---- Preventivo (voci correnti) ----
    if (in_array($action, ['add_prev_item','update_prev_item','delete_prev_item'], true)) {
      q($connection,"CREATE TABLE IF NOT EXISTS cause_preventivo(
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_causa INT NOT NULL,
        voce VARCHAR(255) NOT NULL,
        qty DECIMAL(10,2) NOT NULL DEFAULT 1.00,
        prezzo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        iva_perc DECIMAL(5,2) NOT NULL DEFAULT 22.00,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(id_causa)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if ($action === 'add_prev_item' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id_causa=(int)($_POST['id_causa']??0); if($id_causa<=0) throw new Exception('Causa mancante.');
      $voce=esc($connection,$_POST['voce']??''); if($voce==='') throw new Exception('Voce obbligatoria.');
      $qty=(float)($_POST['qty']??1); $prezzo=(float)($_POST['prezzo']??0); $iva=(float)($_POST['iva_perc']??22);
      q($connection,"INSERT INTO cause_preventivo (id_causa,voce,qty,prezzo,iva_perc) VALUES ($id_causa,'$voce',$qty,$prezzo,$iva)");
      echo json_encode(['ok'=>true,'msg'=>'Voce aggiunta']); exit;
    }
    if ($action === 'update_prev_item' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id=(int)($_POST['id']??0); if($id<=0) throw new Exception('ID non valido.');
      $voce=esc($connection,$_POST['voce']??''); $qty=(float)($_POST['qty']??1); $prezzo=(float)($_POST['prezzo']??0); $iva=(float)($_POST['iva_perc']??22);
      q($connection,"UPDATE cause_preventivo SET voce='$voce', qty=$qty, prezzo=$prezzo, iva_perc=$iva WHERE id=$id");
      echo json_encode(['ok'=>true,'msg'=>'Voce aggiornata']); exit;
    }
    if ($action === 'delete_prev_item' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id=(int)($_POST['id']??0); if($id<=0) throw new Exception('ID non valido.');
      q($connection,"DELETE FROM cause_preventivo WHERE id=$id");
      echo json_encode(['ok'=>true,'msg'=>'Voce eliminata']); exit;
    }

    // ---- Preventivi PDF (docs + items snapshot) ----
    ensure_prev_schema($connection);

    if ($action === 'gen_prev_pdf' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id_causa=(int)($_POST['id_causa']??0); if($id_causa<=0) throw new Exception('Causa mancante.');

      $r=q($connection,"SELECT c.*, cli.nome AS cli_nome, cli.cognome AS cli_cognome FROM cause c LEFT JOIN clienti cli ON cli.id=c.id_cliente WHERE c.id=$id_causa");
      $ca=mysqli_fetch_assoc($r); if(!$ca) throw new Exception('Causa non trovata.');
      $items=q($connection,"SELECT * FROM cause_preventivo WHERE id_causa=$id_causa ORDER BY id ASC");

      $imp=0; $iva=0; $lines=[];
      $lines[]=[20,20,16,$MAIL_FROM_NAME.' — Preventivo'];
      $lines[]=[20,30,11,$AVVOCATO['nome']];
      $lines[]=[20,36,11,$AVVOCATO['indirizzo']];
      $lines[]=[20,42,11,'Tel '.$AVVOCATO['telefono'].' · '.$AVVOCATO['email'].' · PEC '.$AVVOCATO['pec']];
      $lines[]=[20,58,12,'Cliente: '.trim(($ca['cli_nome']??'').' '.($ca['cli_cognome']??''))];
      $y=75; $i=1;

      $snapshot = [];
      while($r=mysqli_fetch_assoc($items)){
        $sub=(float)$r['qty']*(float)$r['prezzo']; $imp+=$sub; $iva+= $sub*(float)$r['iva_perc']/100;
        $lines[]=[20,$y,11,sprintf("%d) %s — Q.tà %s × € %s (IVA %s%%)",$i++, $r['voce'], $r['qty'], money_e($r['prezzo']), $r['iva_perc'])];
        $snapshot[] = $r;
        $y+=6; if($y>760){ $y+=12; }
      }
      $tot=$imp+$iva; $y+=8;
      $lines[]=[20,$y,12,'Imponibile: € '.money_e($imp)]; $y+=6;
      $lines[]=[20,$y,12,'IVA: € '.money_e($iva)]; $y+=6;
      $lines[]=[20,$y,12,'Totale: € '.money_e($tot)];
      $dir=$UPLOAD_BASE."/cause_$id_causa"; if(!is_dir($dir)) @mkdir($dir,0777,true);
      $rnum=q($connection,"SELECT COALESCE(MAX(numero),0)+1 n FROM cause_prev_docs WHERE id_causa=$id_causa"); $n=mysqli_fetch_assoc($rnum)['n']??1;
      $date=date('Y-m-d'); $fname="Preventivo-$n-{$date}.pdf"; $path="$dir/$fname"; $rel="uploads/cause_$id_causa/$fname";
      if(!generate_simple_pdf($path,$lines)) throw new Exception('Impossibile generare PDF.');

      q($connection,"INSERT INTO cause_prev_docs (id_causa,numero,data,path_pdf,imponibile,iva,totale,status)
                     VALUES ($id_causa,$n,'$date','$rel',$imp,$iva,$tot,'attesa')");
      $id_doc = (int)mysqli_insert_id($connection);
      foreach ($snapshot as $s) {
        $sub = (float)$s['qty']*(float)$s['prezzo'];
        q($connection, "INSERT INTO cause_prev_docs_items (id_doc,voce,qty,prezzo,iva_perc,subtot)
                        VALUES ($id_doc, '".esc($connection,$s['voce'])."', ".(float)$s['qty'].", ".(float)$s['prezzo'].", ".(float)$s['iva_perc'].", $sub)");
      }

      echo json_encode(['ok'=>true,'msg'=>'PDF generato','path'=>$rel,'numero'=>$n]); exit;
    }

    // Dettagli PDF: restituisce anche items
    if ($action === 'get_prev_doc') {
      $id_doc = (int)($_GET['id_doc'] ?? 0); if ($id_doc<=0) throw new Exception('ID non valido.');
      $r = q($connection, "SELECT * FROM cause_prev_docs WHERE id=$id_doc"); $doc = mysqli_fetch_assoc($r);
      if (!$doc) throw new Exception('Documento non trovato.');
      $items = []; $ri = q($connection, "SELECT * FROM cause_prev_docs_items WHERE id_doc=$id_doc ORDER BY id ASC");
      while ($x = mysqli_fetch_assoc($ri)) $items[] = $x;
      echo json_encode(['ok'=>true,'doc'=>$doc,'items'=>$items]); exit;
    }
    if ($action === 'update_prev_doc' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id_doc = (int)($_POST['id_doc'] ?? 0); if ($id_doc<=0) throw new Exception('ID non valido.');
      $sets = [];
      if (isset($_POST['data']) && $_POST['data']!=='')   $sets[] = "data='".esc($connection,$_POST['data'])."'";
      if (isset($_POST['totale']) && $_POST['totale']!=='') $sets[] = "totale=".(float)$_POST['totale'];
      if (isset($_POST['status']) && $_POST['status']!=='') $sets[] = "status='".esc($connection,$_POST['status'])."'";
      if (!$sets) throw new Exception('Nessun campo da aggiornare');
      q($connection, "UPDATE cause_prev_docs SET ".implode(',', $sets)." WHERE id=$id_doc");
      echo json_encode(['ok'=>true,'msg'=>'Documento aggiornato']); exit;
    }
    if ($action === 'delete_prev_doc' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id_doc = (int)($_POST['id_doc'] ?? 0); if ($id_doc<=0) throw new Exception('ID non valido.');
      $r = q($connection, "SELECT path_pdf FROM cause_prev_docs WHERE id=$id_doc");
      if ($row = mysqli_fetch_assoc($r)) {
        $abs = __DIR__ . '/' . $row['path_pdf'];
        if (is_file($abs)) @unlink($abs);
      }
      q($connection, "DELETE FROM cause_prev_docs WHERE id=$id_doc");
      echo json_encode(['ok'=>true,'msg'=>'Preventivo eliminato']); exit;
    }
    // Items del PDF
    if ($action === 'add_prevdoc_item' && $method==='POST') {
      $id_doc = (int)($_POST['id_doc'] ?? 0); if ($id_doc<=0) throw new Exception('Documento mancante');
      $voce = esc($connection, $_POST['voce'] ?? ''); if ($voce==='') throw new Exception('Voce obbligatoria');
      $qty = (float)($_POST['qty'] ?? 1); $prezzo = (float)($_POST['prezzo'] ?? 0); $iva = (float)($_POST['iva_perc'] ?? 22);
      $sub = $qty * $prezzo;
      q($connection, "INSERT INTO cause_prev_docs_items (id_doc,voce,qty,prezzo,iva_perc,subtot) VALUES ($id_doc,'$voce',$qty,$prezzo,$iva,$sub)");
      echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'update_prevdoc_item' && $method==='POST') {
      $id = (int)($_POST['id'] ?? 0); if ($id<=0) throw new Exception('ID mancante');
      $voce = esc($connection, $_POST['voce'] ?? ''); if ($voce==='') throw new Exception('Voce obbligatoria');
      $qty = (float)($_POST['qty'] ?? 1); $prezzo = (float)($_POST['prezzo'] ?? 0); $iva = (float)($_POST['iva_perc'] ?? 22);
      $sub = $qty * $prezzo;
      q($connection, "UPDATE cause_prev_docs_items SET voce='$voce', qty=$qty, prezzo=$prezzo, iva_perc=$iva, subtot=$sub WHERE id=$id");
      echo json_encode(['ok'=>true]); exit;
    }
    if ($action === 'delete_prevdoc_item' && $method==='POST') {
      $id = (int)($_POST['id'] ?? 0); if ($id<=0) throw new Exception('ID mancante');
      q($connection, "DELETE FROM cause_prev_docs_items WHERE id=$id");
      echo json_encode(['ok'=>true]); exit;
    }

    // ---- Documenti ----
    if (in_array($action,['upload_doc','get_file','update_file','delete_file'], true)) {
      q($connection,"CREATE TABLE IF NOT EXISTS cause_file(
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_causa INT NOT NULL,
        tipo VARCHAR(16) NULL,
        nome_originale VARCHAR(255) NOT NULL,
        path_relativo VARCHAR(255) NOT NULL,
        doc_date DATE NULL,
        nota TEXT NULL,
        req_uid VARCHAR(64) NULL,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(id_causa), INDEX(doc_date),
        UNIQUE KEY uk_req_uid (req_uid)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
      // se la tabella esiste già senza req_uid, la aggiungo (senza crashare se c'è già)
      if (!col_exists($connection,'cause_file','req_uid')) {
        @q($connection,"ALTER TABLE cause_file ADD COLUMN req_uid VARCHAR(64) NULL");
        @q($connection,"ALTER TABLE cause_file ADD UNIQUE KEY uk_req_uid (req_uid)");
      }
    }
    if ($action==='upload_doc' && $method==='POST') {
      // anti doppio-submit “ravvicinato” (3s)
      if (!dedup_ok($action, 3)) { echo json_encode(['ok'=>true,'msg'=>'Richiesta duplicata ignorata']); exit; }

      $id_causa=(int)($_POST['id_causa']??0); if($id_causa<=0) throw new Exception('Causa mancante.');
      $tipo=esc($connection,$_POST['tipo']??'cliente'); 
      if(empty($_FILES['file']['tmp_name'])) throw new Exception('Nessun file.');

      $doc_date = esc($connection, $_POST['doc_date'] ?? '');
      $nota     = esc($connection, $_POST['nota'] ?? '');

      // token idempotenza lato server
      $req_uid = preg_replace('/[^A-Za-z0-9_-]/','', $_POST['req_uid'] ?? '');
      if ($req_uid === '') { $req_uid = bin2hex(random_bytes(16)); }

      // se già registrato, non rifaccio nulla (evita duplicati veri)
      $chk = q($connection,"SELECT id FROM cause_file WHERE req_uid='$req_uid' LIMIT 1");
      if (mysqli_fetch_assoc($chk)) { echo json_encode(['ok'=>true,'msg'=>'Già caricato']); exit; }

      $dir=$UPLOAD_BASE."/cause_$id_causa"; if(!is_dir($dir)) @mkdir($dir,0777,true);
      $orig=basename($_FILES['file']['name']); $safe=preg_replace('/[^A-Za-z0-9._-]+/','_',$orig);

      // nome unico filesystem
      $ts = date('Ymd_His').sprintf('_%03d',(int)((microtime(true)-floor(microtime(true)))*1000));
      $target=$dir.'/'.$ts.'_'.$safe;

      if(!@move_uploaded_file($_FILES['file']['tmp_name'],$target)) throw new Exception('Upload fallito.');
      $rel = 'uploads/cause_'.$id_causa.'/'.basename($target);

      q($connection,"INSERT INTO cause_file (id_causa,tipo,nome_originale,path_relativo,doc_date,nota,req_uid)
                     VALUES ($id_causa,'$tipo','".esc($connection,$orig)."','$rel',".
                     ($doc_date?"'$doc_date'":"NULL").",".
                     ($nota?"'$nota'":"NULL").",'$req_uid')");

      echo json_encode(['ok'=>true,'msg'=>'File caricato','path'=>$rel]); 
      exit;
    }


    if ($action==='get_file') {
      $id=(int)($_GET['id']??0); if($id<=0) throw new Exception('ID non valido.');
      $r=q($connection,"SELECT * FROM cause_file WHERE id=$id"); $row=mysqli_fetch_assoc($r);
      if(!$row) throw new Exception('File non trovato.');
      echo json_encode(['ok'=>true,'row'=>$row]); exit;
    }
    if ($action==='update_file' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id=(int)($_POST['id']??0); if($id<=0) throw new Exception('ID non valido.');
      $tipo=esc($connection,$_POST['tipo']??'cliente'); $doc_date=esc($connection,$_POST['doc_date']??''); $nota=esc($connection,$_POST['nota']??'');
      q($connection,"UPDATE cause_file SET tipo='$tipo', doc_date=".($doc_date?"'$doc_date'":"NULL").", nota=".($nota?"'$nota'":"NULL")." WHERE id=$id");
      echo json_encode(['ok'=>true,'msg'=>'Documento aggiornato']); exit;
    }
    if ($action==='delete_file' && $method==='POST') {
      if (!dedup_ok($action)) throw new Exception('Richiesta duplicata ignorata.');
      $id=(int)($_POST['id']??0); if($id<=0) throw new Exception('ID non valido.');
      $r=q($connection,"SELECT path_relativo FROM cause_file WHERE id=$id"); $row=mysqli_fetch_assoc($r);
      if($row){ $abs=__DIR__ . '/'. $row['path_relativo']; if(is_file($abs)) @unlink($abs); }
      q($connection,"DELETE FROM cause_file WHERE id=$id");
      echo json_encode(['ok'=>true,'msg'=>'Documento eliminato']); exit;
    }

    // ---- Agenda ----
    if (in_array($action, ['add_evt','get_evt','update_evt','delete_evt'], true)) {
      q($connection,"CREATE TABLE IF NOT EXISTS cause_agenda (
          id INT AUTO_INCREMENT PRIMARY KEY,
          id_causa INT NOT NULL,
          start_dt DATETIME NOT NULL,
          tipo VARCHAR(32) NOT NULL,
          luogo VARCHAR(255) DEFAULT NULL,
          note TEXT DEFAULT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX(id_causa), INDEX(start_dt)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    if ($action==='add_evt' && $method==='POST') {
      $id_causa = (int)($_POST['id_causa'] ?? 0); if ($id_causa<=0) throw new Exception('Causa mancante.');
      $start_dt = str_replace('T',' ', esc($connection, $_POST['start_dt'] ?? date('Y-m-d H:i')));
      $tipo     = $canonTipo($_POST['tipo'] ?? 'altro');
      $luogo    = esc($connection, $_POST['luogo'] ?? '');
      $note     = esc($connection, $_POST['note'] ?? '');
      q($connection, "INSERT INTO cause_agenda (id_causa,start_dt,tipo,luogo,note) VALUES ($id_causa,'$start_dt','$tipo',".($luogo?"'$luogo'":"NULL").",".($note?"'$note'":"NULL").")");
      echo json_encode(['ok'=>true,'msg'=>'Evento aggiunto']); exit;
    }
    if ($action==='get_evt') {
      $id=(int)($_GET['id']??0); if($id<=0) throw new Exception('ID non valido.');
      $r=q($connection,"SELECT * FROM cause_agenda WHERE id=$id"); $row=mysqli_fetch_assoc($r);
      if(!$row) throw new Exception('Evento non trovato.');
      echo json_encode(['ok'=>true,'row'=>$row]); exit;
    }
    if ($action==='update_evt' && $method==='POST') {
      $id=(int)($_POST['id']??0); if($id<=0) throw new Exception('ID non valido.');
      $start_dt = str_replace('T',' ', esc($connection, $_POST['start_dt'] ?? ''));
      $tipo     = $canonTipo($_POST['tipo'] ?? 'altro');
      $luogo    = esc($connection, $_POST['luogo'] ?? '');
      $note     = esc($connection, $_POST['note'] ?? '');
      q($connection,"UPDATE cause_agenda SET ".
        ($start_dt?"start_dt='$start_dt',":"").
        "tipo='$tipo', luogo=".($luogo?"'$luogo'":"NULL").", note=".($note?"'$note'":"NULL").
        " WHERE id=$id");
      echo json_encode(['ok'=>true,'msg'=>'Evento aggiornato']); exit;
    }
    if ($action==='delete_evt' && $method==='POST') {
      $id=(int)($_POST['id']??0); if($id<=0) throw new Exception('ID non valido.');
      q($connection,"DELETE FROM cause_agenda WHERE id=$id");
      echo json_encode(['ok'=>true,'msg'=>'Evento eliminato']); exit;
    }

    throw new Exception('Azione non riconosciuta.');
  } catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
  }
  exit;
}

/* ===================== PAGE (non-AJAX) ===================== */
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Cause cliente</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap CSS opzionale -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
<div class="container py-3">
  <datalist id="datalistAutorita">
    <?php foreach ($AUTORITA_LIST as $a): ?><option value="<?= e($a) ?>"></option><?php endforeach; ?>
  </datalist>

  <div id="mainArea" class="my-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">Cause cliente</h4>
    </div>
    <div class="text-center text-muted py-5">
      <div class="spinner-border"></div>
      <div>Caricamento…</div>
    </div>
  </div>
</div>

<!-- Modal principale -->
<div class="modal fade" id="modalMain" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Dettagli</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body"></div>
      <div class="modal-footer"></div>
    </div>
  </div>
</div>
<!-- Modal secondario -->
<div class="modal fade" id="modalSub" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Dettaglio</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body"></div>
      <div class="modal-footer"></div>
    </div>
  </div>
</div>


<script>
(() => {
  /* ===================== Utils ===================== */
  const qs  = (s, r = document) => r.querySelector(s);
  const qsa = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = (s) => (s ?? '').toString().replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));

  /* ===================== Toast TOP-RIGHT ===================== */
  (function ensureToastsTR() {
    if (qs('#toastsTR')) return;
    const host = document.createElement('div');
    host.id = 'toastsTR';
    document.body.appendChild(host);
    const css = document.createElement('style');
    css.textContent = `
      #toastsTR{position:fixed; right:1.25rem; top:1.25rem; display:flex; flex-direction:column; gap:.6rem; z-index:2000; pointer-events:none}
      .toast-tr{pointer-events:auto; min-width:300px; max-width:520px; border-radius:14px; padding:1rem 1.25rem; font-weight:600;
                border:1px solid rgba(0,0,0,.06); box-shadow:0 14px 38px rgba(0,0,0,.18); opacity:0; transform:translateY(-8px) scale(.98);
                animation:toast-in .18s ease forwards}
      .toast-tr.toast-out{animation:toast-out .18s ease forwards}
      .toast-tr.success{background:#d8efe3; color:#0f5132; border-color:#c7e7d8}
      .toast-tr.error{background:#f8d7da; color:#842029; border-color:#f1b0b7}
      .toast-tr.warn{background:#fff3cd; color:#664d03; border-color:#ffe69c}
      .toast-tr.info{background:#dbeafe; color:#0b3868; border-color:#bfdbfe}
      @keyframes toast-in{from{opacity:0; transform:translateY(-8px) scale(.98)}to{opacity:1; transform:translateY(0) scale(1)}}
      @keyframes toast-out{from{opacity:1; transform:translateY(0) scale(1)}to{opacity:0; transform:translateY(-8px) scale(.98)}}
    `;
    document.head.appendChild(css);
  })();
  function showToast(message, type='success', timeout=2800) {
    const host = qs('#toastsTR'); if (!host) return alert(message);
    const max = 4; while (host.children.length >= max) host.firstElementChild?.remove();
    const el = document.createElement('div');
    el.className = 'toast-tr ' + (['success','error','warn','info'].includes(type) ? type : 'success');
    el.innerHTML = `<div>${esc(message)}</div>`;
    host.appendChild(el);
    let closed=false;
    const close=()=>{ if(closed) return; closed=true; el.classList.add('toast-out'); setTimeout(()=>el.remove(),210); };
    el.addEventListener('click', close);
    setTimeout(close, timeout);
  }

  /* ===================== Fallback Bootstrap (modali & tab) ===================== */
  const modalMainEl = document.getElementById('modalMain');
  const modalSubEl  = document.getElementById('modalSub');
  (function ensureFallbackCss(){
    if (window.bootstrap?.Modal) return;
    const style = document.createElement('style');
    style.textContent = `
      .modal{ display:none; position:fixed; inset:0; z-index:1055; background:rgba(0,0,0,.35) }
      .modal .modal-dialog{ margin:2rem auto; max-width:800px }
      .modal-content{ background:#fff; border-radius:.5rem; overflow:hidden; box-shadow:0 20px 60px rgba(0,0,0,.25) }
      .modal.show{ display:block }
      body.modal-open{ overflow:hidden }
      .fade{transition:opacity .15s linear} .fade.show{opacity:1}
      .nav-tabs .nav-link.active{ background:#fff; border-color:#dee2e6 #dee2e6 #fff }
      .tab-pane{ display:none } .tab-pane.show.active{ display:block }
    `;
    document.head.appendChild(style);
  })();
  let _backdrops = 0;
  function ensureBackdrop() {
    if (window.bootstrap?.Modal) return;
    const bd = document.createElement('div');
    bd.className='modal-backdrop show';
    bd.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1050';
    bd.dataset.fallback='1';
    document.body.appendChild(bd);
    _backdrops++;
  }
  function removeBackdrop() {
    if (window.bootstrap?.Modal) return;
    const bd = [...document.querySelectorAll('.modal-backdrop')].pop();
    if (bd) bd.remove();
    _backdrops = Math.max(0,_backdrops-1);
  }
  function wrapModal(el) {
    return {
      show() {
        ensureBackdrop();
        el.style.display='block'; el.classList.add('show'); document.body.classList.add('modal-open');
        el.querySelectorAll('[data-bs-dismiss="modal"], .btn-close, [data-role="close-modal"]').forEach(b => {
          b.addEventListener('click', this.hide, { once:true });
        });
        const onKey = (ev)=>{ if(ev.key==='Escape') this.hide(); };
        el._escHandler=onKey; document.addEventListener('keydown', onKey);
      },
      hide() {
        el.classList.remove('show'); el.style.display='none'; removeBackdrop();
        if (_backdrops===0) document.body.classList.remove('modal-open');
        if (el._escHandler) { document.removeEventListener('keydown', el._escHandler); el._escHandler=null; }
      }
    };
  }
  const getModal = (el) => (window.bootstrap?.Modal ? bootstrap.Modal.getOrCreateInstance(el) : wrapModal(el));
  const getTab   = (el) => (window.bootstrap?.Tab   ? bootstrap.Tab.getOrCreateInstance(el)   : { show(){
    const target = el.getAttribute('data-bs-target'); if (!target) return;
    const pane = document.querySelector(target); if (!pane) return;
    el.closest('.nav')?.querySelectorAll('.nav-link').forEach(n => n.classList.remove('active'));
    el.classList.add('active');
    pane.parentElement.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('show','active'));
    pane.classList.add('show','active');
  }});

  function openModal(which, { title = '', body = '', footer = '', size = 'lg', staticBackdrop = false } = {}) {
    const el  = which === 'sub' ? modalSubEl : modalMainEl;
    const mdl = getModal(el);
    el.querySelector('.modal-title').innerHTML  = title || '';
    el.querySelector('.modal-body').innerHTML   = body  || '';
    el.querySelector('.modal-footer').innerHTML = footer|| '';
    const dlg = el.querySelector('.modal-dialog');
    dlg.className = 'modal-dialog modal-dialog-scrollable modal-' + (size || 'lg');
    if (window.bootstrap?.Modal) {
      el.setAttribute('data-bs-backdrop', staticBackdrop ? 'static' : 'true');
      el.setAttribute('data-bs-keyboard', staticBackdrop ? 'false' : 'true');
    }
    el.querySelectorAll('[data-bs-dismiss="modal"], .btn-close, [data-role="close-modal"]').forEach(b=>{
      b.addEventListener('click', ()=> getModal(el).hide(), { once:true });
    });
    mdl.show();
    setTimeout(() => { el.querySelector('input,select,textarea,button')?.focus(); }, 80);
    return el;
  }

  async function askConfirm({ title='Confermi?', body='Procedere?', okText='Conferma', cancelText='Annulla', size='sm', danger=false }={}) {
    return new Promise(resolve => {
      const footer = `
        <button class="btn btn-secondary" data-role="close-modal">${cancelText}</button>
        <button class="btn ${danger?'btn-danger':'btn-primary'}" data-role="ok">${okText}</button>`;
      const el = openModal('sub', { title, body: `<p class="mb-0">${body}</p>`, footer, size, staticBackdrop: true });
      const mdl = getModal(el);
      el.querySelector('[data-role="ok"]').addEventListener('click', ()=>{ mdl.hide(); resolve(true); }, { once:true });
      el.querySelector('[data-role="close-modal"]').addEventListener('click', ()=>resolve(false), { once:true });
    });
  }

  /* ===================== AJAX helper ===================== */
  async function fetchJSON(url, data = null, method = 'POST') {
    const opts = { method, headers: {} };
    if (data instanceof FormData) {
      opts.body = data;
    } else if (data) {
      opts.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
      opts.body = new URLSearchParams(data).toString();
    }
    const full = url + (url.includes('?') ? '&' : '?') + 'ajax=1';
    let r, text;
    try { r = await fetch(full, opts); text = await r.text(); }
    catch { showToast('Connessione non disponibile', 'error'); return { ok:false, msg:'network' }; }
    try { return JSON.parse(text); }
    catch { return { ok:false, msg:(text||'').trim() || `HTTP ${r?.status ?? ''}` }; }
  }

  /* ===================== Helpers pagina ===================== */
  const urlParams  = new URLSearchParams(location.search);
  const id_cliente = parseInt(urlParams.get('id_cliente') || '0', 10);

  function getOpenCausaIds() {
    return qsa('.cause-detail-row').filter(tr => !tr.classList.contains('d-none'))
      .map(tr => parseInt(tr.id.replace('row-detail-',''), 10)).filter(Boolean);
  }

  async function reloadPage(preserveOpen = true) {
    const out = qs('#mainArea');
    const openIds = preserveOpen ? getOpenCausaIds() : [];
    out.innerHTML = `<div class="text-center text-muted py-5"><div class="spinner-border"></div><div>Caricamento…</div></div>`;
    const j = await fetchJSON('?action=load_page&id_cliente=' + id_cliente, null, 'GET');
    out.innerHTML = j.ok ? j.html : `<div class="alert alert-danger">${esc(j.msg || 'Errore')}</div>`;
    if (j.ok && openIds.length) {
      for (const id of openIds) {
        const btn = qs(`.btn-toggle[data-id="${id}"]`);
        const row = qs(`#row-detail-${id}`);
        if (btn && row) {
          row.classList.remove('d-none');
          btn.innerHTML = '<i class="bi bi-chevron-up"></i>';
          await reloadCausaBox(id);
        }
      }
    }
  }

  function findCausaIdFor(el) {
    const box = el.closest?.('.cause-box');
    if (!box) return null;
    const m = box.id.match(/causa-box-(\d+)/);
    return m ? parseInt(m[1], 10) : null;
  }

  async function reloadCausaBox(id) {
    const box = document.getElementById('causa-box-' + id);
    if (!box) return;
    box.innerHTML = `<div class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm"></div></div>`;
    const j = await fetchJSON('?action=load_causa_box&id_causa=' + id, null, 'GET');
    if (!j.ok) { box.innerHTML = `<div class="alert alert-danger">${esc(j.msg)}</div>`; return; }
    box.innerHTML = j.html;

    // Tab handler (anche fallback)
    box.querySelectorAll('.nav-link[data-bs-toggle="tab"]').forEach(link => {
      link.addEventListener('click', (ev) => { ev.preventDefault(); getTab(link).show(); });
    });
    const cron = box.querySelector(`.nav-link i.bi-clock-history, .nav-link[data-bs-target*="tab-crono-"]`);
    if (cron) getTab(cron.closest('.nav-link')).show();
  }

  /* ===================== Avvio ===================== */
  document.addEventListener('DOMContentLoaded', () => { if (id_cliente > 0) reloadPage(false); });

  /* ===================== CLICK (delegato) ===================== */
  document.addEventListener('click', async (e) => {
    const t = e.target.closest('button, a'); if (!t) return;

    // Tabs (fallback)
    if (t.matches('.nav-link[data-bs-toggle="tab"]')) { e.preventDefault(); getTab(t).show(); return; }

    // Espandi/chiudi box causa
    if (t.classList.contains('btn-toggle')) {
      const id = t.dataset.id;
      const row = qs('#row-detail-' + id);
      if (row.classList.contains('d-none')) {
        await reloadCausaBox(id); row.classList.remove('d-none'); t.innerHTML = '<i class="bi bi-chevron-up"></i>';
      } else {
        row.classList.add('d-none'); t.innerHTML = '<i class="bi bi-chevron-down"></i>';
      }
      return;
    }

    /* -------------------- CAUSE -------------------- */
    if (t.id === 'btnShowNewCausa') {
      const body = `
        <form id="form-add-causa" class="row g-3">
          <input type="hidden" name="action" value="add_causa">
          <input type="hidden" name="id_cliente" value="${id_cliente}">
          <div class="col-md-12"><label class="form-label">Titolo</label><input class="form-control" name="titolo" required></div>
          <div class="col-md-12"><label class="form-label">Autorità</label>
            <input class="form-control" name="autorita" list="datalistAutorita" placeholder="Es. Tribunale — Sez. Civile">
          </div>
          <div class="col-md-6"><label class="form-label">Stato</label>
            <select name="status" class="form-select">
              <option value="da_aprire">Da aprire</option>
              <option value="aperte">Aperta</option>
              <option value="sospese">Sospesa</option>
              <option value="chiuse">Chiusa</option>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Esito (se chiusa)</label>
            <select name="esito" class="form-select" disabled>
              <option value="">—</option>
              <option value="vinta">Vinta</option>
              <option value="persa">Persa</option>
              <option value="pareggiata">Pareggiata</option>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Numero RG</label><input class="form-control" name="numero_rg"></div>
          <div class="col-md-6"><label class="form-label">Data inizio</label><input type="date" class="form-control" name="data_inizio"></div>
        </form>`;

      const footer = `<button class="btn btn-secondary" data-role="close-modal">Chiudi</button>
                      <button class="btn btn-primary" id="btnAddCausaSave"><i class="bi bi-check2"></i> Salva</button>`;

      const el = openModal('main', { title:'Nuova causa', body, footer, size:'lg' });

      // ---> abilita/disabilita "esito" in base allo stato
      const selStatus = qs('[name="status"]', el);
      const selEsito  = qs('[name="esito"]',  el);
      const toggleEsito = () => {
        const closed = selStatus?.value === 'chiuse';
        selEsito.disabled = !closed;
        if (!closed) selEsito.value = '';
      };
      selStatus?.addEventListener('change', toggleEsito);
      toggleEsito();
      // <---

      qs('#btnAddCausaSave', el).addEventListener('click', async ()=>{
        const data = Object.fromEntries(new FormData(qs('#form-add-causa', el)).entries());
        const j = await fetchJSON('?action=add_causa', data);
        if (!j.ok) return showToast(j.msg || 'Errore', 'error');
        getModal(el).hide(); showToast('Causa creata', 'success'); await reloadPage(false);
      });
      return;
    }


    if (t.classList.contains('btn-edit-causa')) {
      const id = t.dataset.id;
      const j = await fetchJSON(`?action=get_causa&id_causa=${id}`, null, 'GET');
      if (!j.ok) return showToast(j.msg || 'Errore', 'error');
      const c = j.row || {};
      const body = `
        <form id="form-edit-causa" class="row g-3">
          <input type="hidden" name="action" value="update_causa">
          <input type="hidden" name="id_causa" value="${id}">
          <div class="col-md-12"><label class="form-label">Titolo</label><input class="form-control" name="titolo" value="${esc(c.titolo||'')}" required></div>
          <div class="col-md-12"><label class="form-label">Autorità</label>
            <input class="form-control" name="autorita" list="datalistAutorita" value="${esc(c.autorita||'')}">
          </div>
          <div class="col-md-6"><label class="form-label">Stato</label>
            <select name="status" class="form-select">
              ${['da_aprire','aperte','sospese','chiuse'].map(s=>`<option value="${s}" ${c.status===s?'selected':''}>${s.replace('_',' ')}</option>`).join('')}
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Esito (se chiusa)</label>
            <select name="esito" class="form-select">
              <option value="" ${!c.esito?'selected':''}>—</option>
              <option value="vinta" ${c.esito==='vinta'?'selected':''}>Vinta</option>
              <option value="persa" ${c.esito==='persa'?'selected':''}>Persa</option>
              <option value="pareggiata" ${c.esito==='pareggiata'?'selected':''}>Pareggiata</option>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Numero RG</label><input class="form-control" name="numero_rg" value="${esc(c.numero_rg||'')}"></div>
          <div class="col-md-6"><label class="form-label">Data inizio</label><input type="date" class="form-control" name="data_inizio" value="${(c.data_inizio||'').substring(0,10)}"></div>
        </form>`;
      const footer = `<button class="btn btn-secondary" data-role="close-modal">Chiudi</button>
                <button class="btn btn-primary" id="btnEditCausaSave"><i class="bi bi-check2"></i> Salva</button>`;
      const el = openModal('main', { title:`Modifica causa #${id}`, body, footer });

      // abilita/disabilita "esito" in base allo status
      const selStatus = qs('[name="status"]', el);
      const selEsito  = qs('[name="esito"]',  el);

      function toggleEsito() {
        if (!selEsito || !selStatus) return;
        const closed = selStatus.value === 'chiuse';
        selEsito.disabled = !closed;
        if (!closed) selEsito.value = ''; // svuota se non chiusa
      }

      selStatus?.addEventListener('change', toggleEsito);
      toggleEsito(); // stato iniziale


      qs('#btnEditCausaSave', el).addEventListener('click', async ()=>{
        const data = Object.fromEntries(new FormData(qs('#form-edit-causa', el)).entries());
        const j2 = await fetchJSON('?action=update_causa', data);
        if (!j2.ok) return showToast(j2.msg || 'Errore', 'error');
        getModal(el).hide(); showToast('Causa aggiornata', 'success'); await reloadPage();
      });
      return;
    }

    if (t.classList.contains('btn-del-causa')) {
      const id = t.dataset.id;
      const ok = await askConfirm({ title:'Elimina causa', body:'Confermi l’eliminazione?', okText:'Elimina', danger:true });
      if (!ok) return;
      const j = await fetchJSON('?action=delete_causa', { action:'delete_causa', id_causa:id });
      if (!j.ok) return showToast(j.msg || 'Errore', 'error');
      showToast('Causa eliminata', 'success'); await reloadPage(false);
      return;
    }

    /* -------------------- NOTE -------------------- */
    if (t.classList.contains('btn-note-edit-modal')) {
      const id = t.dataset.id;
      const current = (qs(`#notesView-${id}`)?.textContent || '').trim();
      const body = `
        <form id="form-notes">
          <input type="hidden" name="action" value="save_desc">
          <input type="hidden" name="id_causa" value="${id}">
          <label class="form-label">Note</label>
          <textarea class="form-control" name="descrizione" rows="8">${esc(current)}</textarea>
        </form>`;
      const footer = `<button class="btn btn-secondary" data-role="close-modal">Chiudi</button>
                      <button class="btn btn-primary" id="btnNotesSave"><i class="bi bi-check2"></i> Salva</button>`;
      const el = openModal('main', { title:'Modifica note', body, footer, size:'lg' });
      qs('#btnNotesSave', el).addEventListener('click', async ()=>{
        const data = Object.fromEntries(new FormData(qs('#form-notes', el)).entries());
        const j = await fetchJSON('?action=save_desc', data);
        if (!j.ok) return showToast(j.msg || 'Errore', 'error');
        getModal(el).hide(); showToast('Note salvate', 'success'); await reloadCausaBox(id);
      });
      return;
    }

    /* -------------------- PARTI AVVERSE -------------------- */
    if (t.classList.contains('btn-open-add-cp')) {
      const id_causa = t.dataset.causa || findCausaIdFor(t);
      const body = `
        <form id="form-add-cp" class="row g-3">
          <input type="hidden" name="action" value="add_controparte">
          <input type="hidden" name="id_causa" value="${id_causa}">
          <div class="col-md-6"><label class="form-label">Nome</label><input name="nome" class="form-control"></div>
          <div class="col-md-6"><label class="form-label">Cognome</label><input name="cognome" class="form-control"></div>
          <div class="col-md-12"><label class="form-label">Ragione sociale (se azienda)</label><input name="ragione_sociale" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">CF/P.IVA</label><input name="cf_piva" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">PEC</label><input name="pec" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Email</label><input name="email" type="email" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Telefono</label><input name="telefono" class="form-control"></div>
          <div class="col-md-8"><label class="form-label">Indirizzo</label><input name="indirizzo" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Città</label><input name="citta" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">CAP</label><input name="cap" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Provincia</label><input name="provincia" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Nazione</label><input name="nazione" class="form-control"></div>
          <div class="col-12"><hr class="my-1"></div>
          <div class="col-md-4"><label class="form-label">Avv. Nome</label><input name="avv_nome" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Avv. Cognome</label><input name="avv_cognome" class="form-control"></div>
          <div class="col-md-4"><label class="form-label">Avv. Telefono</label><input name="avv_telefono" class="form-control"></div>
          <div class="col-md-6"><label class="form-label">Avv. PEC</label><input name="avv_pec" class="form-control"></div>
          <div class="col-md-6"><label class="form-label">Avv. Email</label><input name="avv_email" type="email" class="form-control"></div>
          <div class="col-12"><label class="form-label">Note</label><textarea name="note" rows="3" class="form-control"></textarea></div>
        </form>`;
      const footer = `<button class="btn btn-secondary" data-role="close-modal">Chiudi</button>
                      <button class="btn btn-primary" id="btnSaveCP"><i class="bi bi-check2"></i> Salva</button>`;
      const el = openModal('main', { title:'Aggiungi parte avversa', body, footer, size:'lg' });
      qs('#btnSaveCP', el).addEventListener('click', async ()=>{
        const data = Object.fromEntries(new FormData(qs('#form-add-cp', el)).entries());
        const j = await fetchJSON('?action=add_controparte', data);
        if (!j.ok) return showToast(j.msg || 'Errore', 'error');
        getModal(el).hide(); showToast('Parte avversa aggiunta', 'success'); await reloadCausaBox(id_causa);
      });
      return;
    }

    if (t.classList.contains('btn-view-cp')) {
      const id = t.dataset.id;
      const j = await fetchJSON(`?action=get_controparte&id=${id}`, null, 'GET');
      if (!j.ok) return showToast(j.msg || 'Errore', 'error');
      const cp = j.row; const id_causa = cp.id_causa;
      const body = `
        <form id="form-edit-cp" class="row g-3">
          <input type="hidden" name="action" value="update_controparte">
          <input type="hidden" name="id" value="${cp.id}">
          <div class="col-md-6"><label class="form-label">Nome</label><input name="nome" class="form-control" value="${esc(cp.nome||'')}"></div>
          <div class="col-md-6"><label class="form-label">Cognome</label><input name="cognome" class="form-control" value="${esc(cp.cognome||'')}"></div>
          <div class="col-md-12"><label class="form-label">Ragione sociale</label><input name="ragione_sociale" class="form-control" value="${esc(cp.ragione_sociale||'')}"></div>
          <div class="col-md-4"><label class="form-label">CF/P.IVA</label><input name="cf_piva" class="form-control" value="${esc(cp.cf_piva||'')}"></div>
          <div class="col-md-4"><label class="form-label">PEC</label><input name="pec" class="form-control" value="${esc(cp.pec||'')}"></div>
          <div class="col-md-4"><label class="form-label">Email</label><input name="email" type="email" class="form-control" value="${esc(cp.email||'')}"></div>
          <div class="col-md-4"><label class="form-label">Telefono</label><input name="telefono" class="form-control" value="${esc(cp.telefono||'')}"></div>
          <div class="col-md-8"><label class="form-label">Indirizzo</label><input name="indirizzo" class="form-control" value="${esc(cp.indirizzo||'')}"></div>
          <div class="col-md-4"><label class="form-label">Città</label><input name="citta" class="form-control" value="${esc(cp.citta||'')}"></div>
          <div class="col-md-4"><label class="form-label">CAP</label><input name="cap" class="form-control" value="${esc(cp.cap||'')}"></div>
          <div class="col-md-4"><label class="form-label">Provincia</label><input name="provincia" class="form-control" value="${esc(cp.provincia||'')}"></div>
          <div class="col-md-4"><label class="form-label">Nazione</label><input name="nazione" class="form-control" value="${esc(cp.nazione||'')}"></div>
          <div class="col-12"><hr class="my-1"></div>
          <div class="col-md-4"><label class="form-label">Avv. Nome</label><input name="avv_nome" class="form-control" value="${esc(cp.avv_nome||'')}"></div>
          <div class="col-md-4"><label class="form-label">Avv. Cognome</label><input name="avv_cognome" class="form-control" value="${esc(cp.avv_cognome||'')}"></div>
          <div class="col-md-4"><label class="form-label">Avv. Telefono</label><input name="avv_telefono" class="form-control" value="${esc(cp.avv_telefono||'')}"></div>
          <div class="col-md-6"><label class="form-label">Avv. PEC</label><input name="avv_pec" class="form-control" value="${esc(cp.avv_pec||'')}"></div>
          <div class="col-md-6"><label class="form-label">Avv. Email</label><input name="avv_email" type="email" class="form-control" value="${esc(cp.avv_email||'')}"></div>
          <div class="col-12"><label class="form-label">Note</label><textarea name="note" rows="3" class="form-control">${esc(cp.note||'')}</textarea></div>
        </form>`;
      const footer = `
        <button class="btn btn-outline-danger me-auto" id="btnDelCP"><i class="bi bi-trash"></i> Elimina</button>
        <button class="btn btn-secondary" data-role="close-modal">Chiudi</button>
        <button class="btn btn-primary" id="btnUpdateCP"><i class="bi bi-check2"></i> Salva</button>`;
      const el = openModal('main', { title:`Parte avversa #${cp.id}`, body, footer, size:'lg' });
      qs('#btnUpdateCP', el).addEventListener('click', async ()=>{
        const data = Object.fromEntries(new FormData(qs('#form-edit-cp', el)).entries());
        const j2 = await fetchJSON('?action=update_controparte', data);
        if (!j2.ok) return showToast(j2.msg || 'Errore', 'error');
        getModal(el).hide(); showToast('Parte avversa aggiornata', 'success'); await reloadCausaBox(id_causa);
      });
      qs('#btnDelCP', el).addEventListener('click', async ()=>{
        const ok = await askConfirm({ title:'Elimina parte', body:'Confermi?', okText:'Elimina', danger:true });
        if (!ok) return;
        const j3 = await fetchJSON('?action=delete_controparte', { action:'delete_controparte', id:cp.id });
        if (!j3.ok) return showToast(j3.msg || 'Errore', 'error');
        getModal(el).hide(); showToast('Parte avversa eliminata', 'success'); await reloadCausaBox(id_causa);
      });
      return;
    }

    if (t.classList.contains('btn-del-cp')) {
      const id = t.dataset.id;
      const id_causa = findCausaIdFor(t);
      const ok = await askConfirm({ title:'Elimina parte', body:'Confermi?', okText:'Elimina', danger:true });
      if (!ok) return;
      const j = await fetchJSON('?action=delete_controparte', { action:'delete_controparte', id });
      if (!j.ok) return showToast(j.msg || 'Errore', 'error');
      showToast('Parte avversa eliminata', 'success'); await reloadCausaBox(id_causa);
      return;
    }

    /* -------------------- PREVENTIVI (voci correnti) -------------------- */
    if (t.classList.contains('btn-prev-open-add')) {
      const id_causa = t.dataset.causa || findCausaIdFor(t);
      const body = `
        <form id="form-add-prev-item" class="row g-3">
          <input type="hidden" name="action" value="add_prev_item">
          <input type="hidden" name="id_causa" value="${id_causa}">
          <div class="col-md-6"><label class="form-label">Voce</label><input name="voce" class="form-control" required></div>
          <div class="col-md-2"><label class="form-label">Q.tà</label><input name="qty" type="number" step="0.01" value="1" class="form-control"></div>
          <div class="col-md-2"><label class="form-label">Prezzo</label><input name="prezzo" type="number" step="0.01" value="0" class="form-control"></div>
          <div class="col-md-2"><label class="form-label">IVA %</label><input name="iva_perc" type="number" step="0.01" value="22" class="form-control"></div>
        </form>`;
      const footer = `
        <button class="btn btn-outline-primary me-auto" id="btnGenPDF"><i class="bi bi-filetype-pdf"></i> Genera PDF</button>
        <button class="btn btn-secondary" data-role="close-modal">Chiudi</button>
        <button class="btn btn-primary" id="btnAddPrevItem"><i class="bi bi-plus-circle"></i> Aggiungi voce</button>`;
      const el = openModal('main', { title:'Preventivo – Aggiungi voci', body, footer, size:'lg' });

      qs('#btnAddPrevItem', el).addEventListener('click', async ()=>{
        const data = Object.fromEntries(new FormData(qs('#form-add-prev-item', el)).entries());
        if (!data.voce?.trim()) return showToast('Inserisci una voce', 'warn');
        const j = await fetchJSON('?action=add_prev_item', data);
        if (!j.ok) return showToast(j.msg || 'Errore', 'error');
        qs('#form-add-prev-item', el).reset(); qs('[name="qty"]', el).value='1'; qs('[name="iva_perc"]', el).value='22';
        showToast('Voce aggiunta', 'success'); await reloadCausaBox(id_causa);
      });

      qs('#btnGenPDF', el).addEventListener('click', async ()=>{
        const j = await fetchJSON('?action=gen_prev_pdf', { action:'gen_prev_pdf', id_causa });
        if (!j.ok) return showToast(j.msg || 'Errore', 'error');
        showToast('PDF generato', 'success'); await reloadCausaBox(id_causa);
      });
      return;
    }

    /* -------------------- PREVENTIVO DOC (dettagli PDF) -------------------- */
    if (t.classList.contains('btn-prevdoc-detail')) {
      const id_doc = t.dataset.iddoc;
      const j = await fetchJSON(`?action=get_prev_doc&id_doc=${id_doc}`, null, 'GET');
      if (!j.ok) return showToast(j.msg || 'Errore', 'error');
      const d  = j.doc; const id_causa = findCausaIdFor(t);

      function docForm(dd){
        return `
        <form id="form-prevdoc" class="row g-3 mb-3">
          <input type="hidden" name="action" value="update_prev_doc">
          <input type="hidden" name="id_doc" value="${dd.id}">
          <div class="col-md-3"><label class="form-label">Numero</label><input class="form-control" value="${dd.numero}" disabled></div>
          <div class="col-md-3"><label class="form-label">Data</label><input type="date" class="form-control" name="data" value="${(dd.data||'').substring(0,10)}"></div>
          <div class="col-md-3"><label class="form-label">Totale</label><input type="number" step="0.01" class="form-control" name="totale" value="${dd.totale ?? ''}"></div>
          <div class="col-md-3"><label class="form-label">Status</label>
            <select name="status" class="form-select">
              ${[['accettato','accettato'],['attesa','attesa'],['non_accettato','non_accettato']].map(([v,l])=>`<option value="${v}" ${dd.status===v?'selected':''}>${l}</option>`).join('')}
            </select>
          </div>
        </form>`;
      }
      function itemsTable(items){
        return `
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr><th>#</th><th>Voce</th><th class="text-end">Q.tà</th><th class="text-end">Prezzo</th><th class="text-end">IVA%</th><th class="text-end">Subtot.</th><th class="text-end"></th></tr>
            </thead>
            <tbody>
              ${items.map((r,i)=>`
                <tr>
                  <td>${i+1}</td>
                  <td>${esc(r.voce)}</td>
                  <td class="text-end">${r.qty}</td>
                  <td class="text-end">€ ${Number(r.prezzo).toFixed(2)}</td>
                  <td class="text-end">${r.iva_perc}</td>
                  <td class="text-end">€ ${Number(r.subtot).toFixed(2)}</td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm">
                      <button class="btn btn-outline-secondary btn-prevdoc-item-edit" data-id="${r.id}"><i class="bi bi-pencil"></i></button>
                      <button class="btn btn-outline-danger btn-prevdoc-item-del" data-id="${r.id}"><i class="bi bi-trash"></i></button>
                    </div>
                  </td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>`;
      }

      const body = `${docForm(d)}
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="fw-semibold">Voci</div>
          <button class="btn btn-sm btn-outline-primary" id="btnAddDocItem"><i class="bi bi-plus-circle"></i> Aggiungi voce</button>
        </div>
        <div id="docItemsWrap">${itemsTable(j.items||[])}</div>`;
      const footer = `<button class="btn btn-secondary" data-role="close-modal">Chiudi</button>
                      <button class="btn btn-primary" id="btnSaveDoc"><i class="bi bi-check2"></i> Salva</button>`;
      const el = openModal('main', { title:`Dettagli preventivo #${d.id}`, body, footer, size:'lg' });

      async function refreshDoc(){
        const j2 = await fetchJSON(`?action=get_prev_doc&id_doc=${id_doc}`, null, 'GET');
        if (!j2.ok) return showToast(j2.msg || 'Errore', 'error');
        qs('#form-prevdoc', el).outerHTML = docForm(j2.doc);
        qs('#docItemsWrap', el).innerHTML = itemsTable(j2.items||[]);
      }

      qs('#btnSaveDoc', el).addEventListener('click', async ()=>{
        const data = Object.fromEntries(new FormData(qs('#form-prevdoc', el)).entries());
        const j2 = await fetchJSON('?action=update_prev_doc', data);
        if (!j2.ok) return showToast(j2.msg || 'Errore', 'error');
        showToast('Documento aggiornato', 'success'); await reloadCausaBox(id_causa);
      });

      // Aggiungi voce
      qs('#btnAddDocItem', el).addEventListener('click', ()=>{
        const b = `
          <form id="form-add-doc-item" class="row g-3">
            <input type="hidden" name="action" value="add_prevdoc_item">
            <input type="hidden" name="id_doc" value="${d.id}">
            <div class="col-md-7"><label class="form-label">Voce</label><input name="voce" class="form-control" required></div>
            <div class="col-md-2"><label class="form-label">Q.tà</label><input name="qty" type="number" step="0.01" value="1" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Prezzo</label><input name="prezzo" type="number" step="0.01" value="0" class="form-control"></div>
            <div class="col-md-1"><label class="form-label">IVA</label><input name="iva_perc" type="number" step="0.01" value="22" class="form-control"></div>
          </form>`;
        const f = `<button class="btn btn-secondary" data-role="close-modal">Chiudi</button>
                   <button class="btn btn-primary" id="btnSaveNewItem"><i class="bi bi-check2"></i> Aggiungi</button>`;
        const sub = openModal('sub', { title:'Nuova voce', body:b, footer:f, size:'lg' });
        qs('#btnSaveNewItem', sub).addEventListener('click', async ()=>{
          const data = Object.fromEntries(new FormData(qs('#form-add-doc-item', sub)).entries());
          const j3 = await fetchJSON('?action=add_prevdoc_item', data);
          if (!j3.ok) return showToast(j3.msg || 'Errore', 'error');
          getModal(sub).hide(); showToast('Voce aggiunta', 'success'); await refreshDoc(); await reloadCausaBox(id_causa);
        });
      });

      // Edit/Del voci (delegato)
      el.addEventListener('click', async (ev)=>{
        const b = ev.target.closest('button'); if (!b) return;
        if (b.classList.contains('btn-prevdoc-item-del')) {
          const ok = await askConfirm({ title:'Elimina voce', body:'Confermi?', okText:'Elimina', danger:true });
          if (!ok) return;
          const j3 = await fetchJSON('?action=delete_prevdoc_item', { action:'delete_prevdoc_item', id:b.dataset.id });
          if (!j3.ok) return showToast(j3.msg || 'Errore', 'error');
          showToast('Voce eliminata', 'success'); await refreshDoc(); await reloadCausaBox(id_causa);
        }
        if (b.classList.contains('btn-prevdoc-item-edit')) {
          const tr = b.closest('tr');
          const values = {
            id: b.dataset.id,
            voce: tr.children[1].textContent.trim(),
            qty:  tr.children[2].textContent.trim(),
            prezzo: (tr.children[3].textContent.replace(/[€\s]/g,'')||'0'),
            iva_perc: tr.children[4].textContent.trim()
          };
          const bb = `
            <form id="form-edit-doc-item" class="row g-3">
              <input type="hidden" name="action" value="update_prevdoc_item">
              <input type="hidden" name="id" value="${values.id}">
              <div class="col-md-7"><label class="form-label">Voce</label><input name="voce" class="form-control" value="${esc(values.voce)}" required></div>
              <div class="col-md-2"><label class="form-label">Q.tà</label><input name="qty" type="number" step="0.01" value="${esc(values.qty)}" class="form-control"></div>
              <div class="col-md-2"><label class="form-label">Prezzo</label><input name="prezzo" type="number" step="0.01" value="${esc(values.prezzo)}" class="form-control"></div>
              <div class="col-md-1"><label class="form-label">IVA</label><input name="iva_perc" type="number" step="0.01" value="${esc(values.iva_perc)}" class="form-control"></div>
            </form>`;
          const ff = `<button class="btn btn-secondary" data-role="close-modal">Chiudi</button>
                      <button class="btn btn-primary" id="btnUpdItem"><i class="bi bi-check2"></i> Salva</button>`;
          const sub = openModal('sub', { title:'Modifica voce', body:bb, footer:ff, size:'lg' });
          qs('#btnUpdItem', sub).addEventListener('click', async ()=>{
            const data = Object.fromEntries(new FormData(qs('#form-edit-doc-item', sub)).entries());
            const j4 = await fetchJSON('?action=update_prevdoc_item', data);
            if (!j4.ok) return showToast(j4.msg || 'Errore', 'error');
            getModal(sub).hide(); showToast('Voce aggiornata', 'success'); await refreshDoc(); await reloadCausaBox(id_causa);
          });
        }
      });
      return;
    }

    if (t.classList.contains('btn-prevdoc-del')) {
      const id_doc = t.dataset.iddoc;
      const id_causa = findCausaIdFor(t);
      const ok = await askConfirm({ title:'Elimina preventivo', body:'Confermi?', okText:'Elimina', danger:true });
      if (!ok) return;
      const j = await fetchJSON('?action=delete_prev_doc', { action:'delete_prev_doc', id_doc });
      if (!j.ok) return showToast(j.msg || 'Errore', 'error');
      showToast('Preventivo eliminato', 'success'); await reloadCausaBox(id_causa);
      return;
    }

    /* -------------------- AGENDA -------------------- */
    if (t.classList.contains('btn-open-add-evt')) {
      const id_causa = t.dataset.causa || findCausaIdFor(t);
      const now = new Date(); const iso = new Date(now.getTime()-now.getTimezoneOffset()*60000).toISOString().slice(0,16);
      const body = `
        <form id="form-add-evt" class="row g-3">
          <input type="hidden" name="action" value="add_evt">
          <input type="hidden" name="id_causa" value="${id_causa}">
          <div class="col-md-6"><label class="form-label">Data/ora</label><input type="datetime-local" class="form-control" name="start_dt" value="${iso}"></div>
          <div class="col-md-6"><label class="form-label">Tipo</label><input class="form-control" name="tipo" placeholder="Udienza, Scadenza, Appuntamento…"></div>
          <div class="col-md-12"><label class="form-label">Luogo</label><input class="form-control" name="luogo"></div>
          <div class="col-12"><label class="form-label">Note</label><textarea name="note" rows="3" class="form-control"></textarea></div>
        </form>`;
      const footer = `<button class="btn btn-secondary" data-role="close-modal">Chiudi</button>
                      <button class="btn btn-primary" id="btnAddEvtSave"><i class="bi bi-check2"></i> Salva</button>`;
      const el = openModal('main', { title:'Nuovo evento', body, footer, size:'lg' });
      qs('#btnAddEvtSave', el).addEventListener('click', async ()=>{
        const data = Object.fromEntries(new FormData(qs('#form-add-evt', el)).entries());
        const j = await fetchJSON('?action=add_evt', data);
        if (!j.ok) return showToast(j.msg || 'Errore', 'error');
        getModal(el).hide(); showToast('Evento aggiunto', 'success'); await reloadCausaBox(id_causa);
      });
      return;
    }

    if (t.classList.contains('btn-edit-evt')) {
      const id = t.dataset.id;
      const j = await fetchJSON(`?action=get_evt&id=${id}`, null, 'GET');
      if (!j.ok) return showToast(j.msg || 'Errore', 'error');
      const ev = j.row; const id_causa = ev.id_causa;
      const body = `
        <form id="form-edit-evt" class="row g-3">
          <input type="hidden" name="action" value="update_evt">
          <input type="hidden" name="id" value="${ev.id}">
          <div class="col-md-6"><label class="form-label">Data/ora</label><input type="datetime-local" class="form-control" name="start_dt" value="${(ev.start_dt||'').replace(' ','T').slice(0,16)}"></div>
          <div class="col-md-6"><label class="form-label">Tipo</label><input class="form-control" name="tipo" value="${esc(ev.tipo||'')}"></div>
          <div class="col-md-12"><label class="form-label">Luogo</label><input class="form-control" name="luogo" value="${esc(ev.luogo||'')}"></div>
          <div class="col-12"><label class="form-label">Note</label><textarea name="note" rows="3" class="form-control">${esc(ev.note||'')}</textarea></div>
        </form>`;
      const footer = `<button class="btn btn-outline-danger me-auto" id="btnDelEvt"><i class="bi bi-trash"></i> Elimina</button>
                      <button class="btn btn-secondary" data-role="close-modal">Chiudi</button>
                      <button class="btn btn-primary" id="btnEditEvtSave"><i class="bi bi-check2"></i> Salva</button>`;
      const el = openModal('main', { title:'Modifica evento', body, footer, size:'lg' });

      qs('#btnEditEvtSave', el).addEventListener('click', async ()=>{
        const data = Object.fromEntries(new FormData(qs('#form-edit-evt', el)).entries());
        const j2 = await fetchJSON('?action=update_evt', data);
        if (!j2.ok) return showToast(j2.msg || 'Errore', 'error');
        getModal(el).hide(); showToast('Evento aggiornato', 'success'); await reloadCausaBox(id_causa);
      });
      qs('#btnDelEvt', el).addEventListener('click', async ()=>{
        const ok = await askConfirm({ title:'Elimina evento', body:'Confermi?', okText:'Elimina', danger:true });
        if (!ok) return;
        const j3 = await fetchJSON('?action=delete_evt', { action:'delete_evt', id: ev.id });
        if (!j3.ok) return showToast(j3.msg || 'Errore', 'error');
        getModal(el).hide(); showToast('Evento eliminato', 'success'); await reloadCausaBox(id_causa);
      });
      return;
    }

    if (t.classList.contains('btn-del-evt')) {
      const id = t.dataset.id;
      const id_causa = findCausaIdFor(t);
      const ok = await askConfirm({ title:'Elimina evento', body:'Confermi?', okText:'Elimina', danger:true });
      if (!ok) return;
      const j = await fetchJSON('?action=delete_evt', { action:'delete_evt', id });
      if (!j.ok) { showToast(j.msg || 'Errore', 'error'); return; }
      showToast('Evento eliminato', 'success');
      await reloadCausaBox(id_causa);
      return;
    }

    /* -------------------- DOCUMENTI: modifica/elimina -------------------- */
    if (t.classList.contains('btn-edit-file')) {
      const id = t.dataset.id;
      const id_causa = findCausaIdFor(t);
      const j = await fetchJSON(`?action=get_file&id=${id}`, null, 'GET');
      if (!j.ok) { showToast(j.msg || 'Errore', 'error'); return; }
      const f = j.row || {};
      const body = `
        <form id="form-edit-doc" class="row g-3">
          <input type="hidden" name="action" value="update_file">
          <input type="hidden" name="id" value="${id}">
          <div class="col-md-4">
            <label class="form-label">Tipo</label>
            <select name="tipo" class="form-select">
              <option value="cliente"  ${f.tipo==='cliente'?'selected':''}>Cliente</option>
              <option value="inviato"  ${f.tipo==='inviato'?'selected':''}>Inviato</option>
              <option value="ricevuto" ${f.tipo==='ricevuto'?'selected':''}>Ricevuto</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Data documento</label>
            <input type="date" name="doc_date" class="form-control" value="${(f.doc_date||'').substring(0,10)}">
          </div>
          <div class="col-12">
            <label class="form-label">Nota</label>
            <textarea name="nota" rows="3" class="form-control">${esc(f.nota||'')}</textarea>
          </div>
        </form>`;
      const footer = `
        <button class="btn btn-secondary" data-role="close-modal">Chiudi</button>
        <button class="btn btn-primary" id="btnSaveDoc"><i class="bi bi-check2"></i> Salva</button>`;
      const el = openModal('main', { title:`Modifica documento #${id}`, body, footer, size:'lg' });

      qs('#btnSaveDoc', el).addEventListener('click', async ()=>{
        const data = Object.fromEntries(new FormData(qs('#form-edit-doc', el)).entries());
        const j2 = await fetchJSON('?action=update_file', data);
        if (!j2.ok) { showToast(j2.msg || 'Errore', 'error'); return; }
        getModal(el).hide(); showToast('Documento aggiornato', 'success'); await reloadCausaBox(id_causa);
      });
      return;
    }

    if (t.classList.contains('btn-del-file')) {
      const id = t.dataset.id;
      const id_causa = findCausaIdFor(t);
      const ok = await askConfirm({ title:'Elimina documento', body:'Confermi?', okText:'Elimina', danger:true });
      if (!ok) return;
      const j = await fetchJSON('?action=delete_file', { action:'delete_file', id });
      if (!j.ok) { showToast(j.msg || 'Errore', 'error'); return; }
      showToast('Documento eliminato', 'success'); await reloadCausaBox(id_causa);
      return;
    }
  });

  /* ===================== UPLOAD DOCUMENTI — SUBMIT ===================== */
  document.addEventListener('submit', async (e) => {
    const f = e.target;
    if (!f.matches('form.form-upload-doc')) return;
    e.preventDefault();

    const btn = f.querySelector('button[type="submit"], button:not([type])');
    const fileInput = f.querySelector('input[type="file"][name="file"]');

    const hiddenId = f.querySelector('[name="id_causa"]')?.value;
    const id_causa = hiddenId || (function findId(el){
      const box = el.closest('.cause-box'); const m = box?.id?.match(/causa-box-(\d+)/); return m ? m[1] : null;
    })(f);

    if (!id_causa)  { showToast('Causa non trovata', 'error'); return; }
    if (!fileInput || !fileInput.files?.length) { showToast('Seleziona un file', 'warn'); return; }

    const fd = new FormData(f);
    fd.set('action', 'upload_doc');
    fd.set('id_causa', id_causa);
    if (!fd.get('tipo')) fd.set('tipo', 'cliente');
    // token anti-duplicati (match con req_uid UNIQUE del server)
    fd.set('req_uid', Date.now().toString(36) + Math.random().toString(36).slice(2));

    btn?.setAttribute('disabled', 'disabled');

    try {
      const j = await fetchJSON('?action=upload_doc', fd);
      if (!j.ok) {
        showToast(j.msg || 'Errore durante il caricamento', 'error');
      } else {
        showToast('File caricato', 'success');
        f.reset();                               // azzera input
        await reloadCausaBox(parseInt(id_causa,10)); // ricarica Documenti
      }
    } catch {
      showToast('Connessione non disponibile', 'error');
    } finally {
      btn?.removeAttribute('disabled');
    }
  });
})();
</script>







</body>
</html>

