<?php
// index.php — Home “Avvocati”
include 'header.php';

$id_user = (int)($_SESSION['id_user'] ?? 0);

// Carico ultime 6 elaborazioni (se la tabella esiste)
$recent = [];
@$rs = mysqli_query($connection, "SELECT created_at, path_rel, last_rc 
                                  FROM linkage_results 
                                  WHERE id_user={$id_user}
                                  ORDER BY created_at DESC
                                  LIMIT 6");
if ($rs) {
    while ($r = mysqli_fetch_assoc($rs)) $recent[] = $r;
}
?>

<style>
/* --- micro-stile solo per questa pagina, look professionale --- */
.section-hero {background: var(--bs-body-bg); border-bottom: 1px solid var(--bs-border-color); margin-top:-.5rem; padding: 1.25rem 0 1.5rem;}
.kicker {letter-spacing:.08em; text-transform:uppercase; font-weight:600; color:var(--bs-secondary-color); font-size:.8rem;}
.feature-card {border:1px solid var(--bs-border-color); border-radius:16px; transition:transform .15s ease, box-shadow .15s ease;}
.feature-card:hover {transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.06);}
.icon-badge {width:40px; height:40px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; background:#f3f4f6; color:#111827; border:1px solid #e5e7eb;}
.card-title{font-weight:600;}
.table-clean th{font-weight:600; font-size:.875rem; color:var(--bs-secondary-color);}
.table-clean td{vertical-align:middle;}
.empty-state{border:1px dashed var(--bs-border-color); border-radius:16px; padding:1rem;}
</style>

<!-- HERO -->
<div class="section-hero">
  <div class="container container-narrow">
    <div class="row align-items-end">
      <div class="col">
        <div class="kicker">Piattaforma</div>
        <h1 class="h2 mb-1">Avvocati — Workspace</h1>
        <p class="text-secondary mb-0">Carica atti in Word, genera collegamenti normativi, gestisci risultati e account.</p>
      </div>
    </div>
  </div>
</div>

<!-- CONTENUTO -->
<div class="container container-narrow mt-4">

  <!-- Funzionalità principali -->
  <div class="row g-3">
    <div class="col-md-4">
      <div class="feature-card h-100 p-3">
        <div class="d-flex align-items-center gap-3 mb-2">
          <span class="icon-badge"><i class="bi bi-link-45deg"></i></span>
          <div class="card-title mb-0">Linkage Normattiva</div>
        </div>
        <p class="mb-3 text-secondary">Ottieni link URN a norme, codici e giurisprudenza di normativa.it.</p>
        <a href="linkage.php" class="btn btn-outline-primary w-100">Nuova elaborazione</a>
      </div>
    </div>

    <div class="col-md-4">
      <div class="feature-card h-100 p-3">
        <div class="d-flex align-items-center gap-3 mb-2">
          <span class="icon-badge"><i class="bi bi-folder2-open"></i></span>
          <div class="card-title mb-0">Risultati</div>
        </div>
        <p class="mb-3 text-secondary">Ogni run è salvato <code>nome file con data/ora</code>. Visualizza, scarica o elimina.</p>
        <a href="linkage.php#risultati" class="btn btn-outline-primary w-100">Apri risultati</a>
      </div>
    </div>

    <div class="col-md-4">
      <div class="feature-card h-100 p-3">
        <div class="d-flex align-items-center gap-3 mb-2">
          <span class="icon-badge"><i class="bi bi-person-gear"></i></span>
          <div class="card-title mb-0">Account</div>
        </div>
        <p class="mb-3 text-secondary">Dati profilo e impostazioni di sicurezza dell’utente.</p>
        <a href="account.php" class="btn btn-outline-primary w-100">Gestisci account</a>
      </div>
    </div>
  </div>

  <!-- Azioni rapide + Ultime elaborazioni -->
  <div class="row g-4 mt-1">
    <div class="col-12">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <h2 class="h6 mb-0">Ultime elaborazioni</h2>
            <a class="small" href="linkage.php#risultati">Tutte</a>
          </div>

          <?php if (!$recent): ?>
            <div class="empty-state text-secondary">
              Nessuna elaborazione recente. Avvia la prima dalla sezione “Linkage”.
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm table-clean mb-0">
                <thead>
                  <tr>
                    <th>Data</th>
                    <th>Percorso</th>
                    <th class="text-center">Stato</th>
                    <th class="text-end">Azioni</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $row): 
                    $rel = $row['path_rel'];
                    $ok = ((int)$row['last_rc'] === 0);
                ?>
                  <tr>
                    <td><?= e(date('d/m/Y H:i', strtotime($row['created_at']))) ?></td>
                    <td class="text-truncate" style="max-width:280px;"><code><?= e($rel) ?></code></td>
                    <td class="text-center">
                      <?php if ($ok): ?>
                        <span class="badge text-bg-success">OK</span>
                      <?php else: ?>
                        <span class="badge text-bg-danger">Errore</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end">
                      <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-secondary" href="<?= e($rel) ?>/input.docx" target="_blank"><i class="bi bi-file-earmark"></i> Input</a>
                        <a class="btn btn-outline-secondary" href="<?= e($rel) ?>/output.docx" target="_blank"><i class="bi bi-filetype-docx"></i> Output</a>
                        <a class="btn btn-outline-secondary" href="<?= e($rel) ?>/exec.log" target="_blank"><i class="bi bi-journal-text"></i> Log</a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>

</div>

<?php include 'footer.php'; ?>
