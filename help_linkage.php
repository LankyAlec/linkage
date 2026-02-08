<?php
session_start();
include 'header.php';

// AUTH (coerente con linkage.php)
if (empty($_SESSION['login'])) {
    header('Location: login.php');
    exit;
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>

<style>
/* Stile coerente con linkage */
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
  min-height: 120px;
  display: flex;
  flex-direction: column;
  justify-content: center;
}
.kpi .num{
  font-size: 1.6rem;
  font-weight: 900;
  letter-spacing: -0.03em;
  margin-top: 4px;
}
.section-title{
  font-size: .82rem;
  text-transform: uppercase;
  letter-spacing: .05em;
  color: #6c757d;
}
.mono{
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
}
.anchor-link{
  text-decoration:none;
}
.anchor-link:hover{
  text-decoration:underline;
}
.codebox{
  background:#0b1220;
  color:#e5e7eb;
  border-radius:14px;
  padding:14px;
  font-size:.95rem;
  overflow:auto;
  border:1px solid rgba(255,255,255,.08);
}
.codebox .muted{ color: rgba(229,231,235,.7); }
.badge-soft{
  border: 1px solid rgba(0,0,0,.10);
  background: rgba(255,255,255,.75);
  color:#212529;
}
.hr-soft{
  border-top: 1px solid rgba(0,0,0,.08);
}
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h1 class="h4 mb-1">Guida Linkage <span class="text-muted">(Word → Normattiva + Allegati ZIP)</span></h1>
    <div class="text-muted small">
      Qui trovi istruzioni, regole di matching “intelligente” e best practice per ottenere output puliti e affidabili.
    </div>
  </div>
  <div class="d-none d-md-flex gap-2">
    <a class="btn btn-outline-secondary" href="linkage.php">
      <i class="bi bi-arrow-left"></i> Torna a Linkage
    </a>
  </div>
</div>

<!-- HERO -->
<div class="linkage-hero p-3 p-md-4 mb-4 shadow-sm">
  <div class="row g-3 align-items-center">
    <div class="col-lg-8">
      <div class="d-flex flex-wrap gap-2 mb-2">
        <span class="badge rounded-pill badge-soft"><i class="bi bi-link-45deg me-1"></i> Link Normattiva</span>
        <span class="badge rounded-pill badge-soft"><i class="bi bi-file-earmark-zip me-1"></i> ZIP con più allegati</span>
        <span class="badge rounded-pill badge-soft"><i class="bi bi-shield-check me-1"></i> Anti-ambiguità</span>
        <span class="badge rounded-pill badge-soft"><i class="bi bi-lightbulb me-1"></i> Match intelligente</span>
      </div>

      <div class="fw-semibold mb-2">
        Cosa fa Linkage?
      </div>
      <ul class="mb-0 text-muted">
        <li>Trasforma automaticamente i riferimenti normativi in link a Normattiva.</li>
        <li>Se carichi uno ZIP, ti fa scegliere il DOCX principale se ce ne sono più di uno.</li>
        <li>Nel DOCX principale crea link agli allegati quando nel testo compaiono riferimenti coerenti.</li>
      </ul>
    </div>

    <div class="col-lg-4">
      <div class="row g-2">
        <div class="col-6">
          <div class="kpi shadow-sm">
            <div class="section-title">Input</div>
            <div class="num">.DOCX / .ZIP</div>
            <div class="text-muted small">Word diretto oppure ZIP con Word + allegati</div>
          </div>
        </div>
        <div class="col-6">
          <div class="kpi shadow-sm">
            <div class="section-title">Output</div>
            <div class="num">linked_*.docx</div>
            <div class="text-muted small">Word finale con link Normattiva + allegati</div>
          </div>
        </div>
      </div>
    </div>

  </div>

  <hr class="hr-soft my-3">

  <!-- TOC -->
  <div class="row g-2">
    <div class="col-md-4">
      <div class="section-title mb-2"><i class="bi bi-list-check me-1"></i> Indice</div>
      <div class="d-flex flex-column gap-1">
        <a class="anchor-link" href="#uso"><i class="bi bi-dot"></i> Come si usa</a>
        <a class="anchor-link" href="#norme"><i class="bi bi-dot"></i> Riferimenti Normattiva supportati</a>
        <a class="anchor-link" href="#zip"><i class="bi bi-dot"></i> ZIP con più file</a>
      </div>
    </div>
    <div class="col-md-4">
      <div class="section-title mb-2">&nbsp;</div>
      <div class="d-flex flex-column gap-1">
        <a class="anchor-link" href="#allegati"><i class="bi bi-dot"></i> Link agli allegati: regole</a>
        <a class="anchor-link" href="#antiamb"><i class="bi bi-dot"></i> Regole anti-ambiguità</a>
        <a class="anchor-link" href="#faq"><i class="bi bi-dot"></i> FAQ</a>
      </div>
    </div>
    <div class="col-md-4">
      <div class="section-title mb-2"><i class="bi bi-info-circle me-1"></i> Suggerimento</div>
      <div class="text-muted small">
        Se vuoi massima precisione sugli allegati: usa nomi tipo <span class="mono">Documento 1.pdf</span>, <span class="mono">Allegato 2.pdf</span>
        e scrivi nel testo riferimenti coerenti.
      </div>
    </div>
  </div>
</div>

<!-- CONTENUTO -->
<div class="row g-3">
  <!-- COL SX -->
  <div class="col-lg-8">

    <!-- COME SI USA -->
    <div id="uso" class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="section-title mb-2">Come si usa</div>

        <div class="fw-semibold mb-1"><i class="bi bi-1-circle me-1"></i> Carica un file</div>
        <ul class="text-muted">
          <li><span class="mono">.docx</span> → elabora subito</li>
          <li><span class="mono">.zip</span> → estrae i file; se ci sono più <span class="mono">.docx</span>, ti chiede quale è il principale</li>
        </ul>

        <div class="fw-semibold mb-1"><i class="bi bi-2-circle me-1"></i> Seleziona il DOCX principale (solo ZIP con più DOCX)</div>
        <p class="text-muted mb-2">
          Si apre un modale dove scegli il documento su cui inserire i link Normattiva e dove cercare i riferimenti agli allegati.
        </p>

        <div class="fw-semibold mb-1"><i class="bi bi-3-circle me-1"></i> Scarica l’output</div>
        <p class="text-muted mb-0">
          Il risultato finale è un Word chiamato <span class="mono">linked_*.docx</span>.
          Se l’esito è “Errore”, è disponibile anche <span class="mono">exec.log</span>.
        </p>
      </div>
    </div>

    <!-- NORME SUPPORTATE -->
    <div id="norme" class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="section-title mb-2">Riferimenti Normattiva supportati</div>

        <div class="row g-2">
          <div class="col-md-6">
            <div class="p-3 rounded-3 border" style="border-color: rgba(0,0,0,.08) !important;">
              <div class="fw-semibold mb-2"><i class="bi bi-journal-text me-1"></i> Codici</div>
              <div class="codebox mb-0">
                <div>art. 1720 c.c.</div>
                <div>articolo 15 c.p.</div>
                <div>art. 5 c.p.c.</div>
                <div>art. 3 c.p.p.</div>
                <div class="muted mt-2">→ diventano link Normattiva</div>
              </div>
            </div>
          </div>

          <div class="col-md-6">
            <div class="p-3 rounded-3 border" style="border-color: rgba(0,0,0,.08) !important;">
              <div class="fw-semibold mb-2"><i class="bi bi-bank2 me-1"></i> Costituzione</div>
              <div class="codebox mb-0">
                <div>art. 24 Cost.</div>
                <div>articolo 3 Costituzione</div>
                <div class="muted mt-2">→ link a Costituzione su Normattiva</div>
              </div>
            </div>
          </div>
        </div>

        <div class="text-muted small mt-3">
          Nota: il riconoscimento è case-insensitive e gestisce varianti “art.” / “articolo”.
        </div>
      </div>
    </div>

    <!-- ZIP -->
    <div id="zip" class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="section-title mb-2">ZIP con più file</div>

        <div class="fw-semibold mb-1"><i class="bi bi-file-earmark-zip me-1"></i> Cosa succede se dentro lo ZIP ci sono più DOCX?</div>
        <p class="text-muted">
          Linkage mette il run in stato <span class="badge rounded-pill text-bg-warning"><i class="bi bi-hourglass-split me-1"></i>In attesa</span>
          e apre un modale: scegli il <strong>DOCX principale</strong>.
        </p>

        <div class="fw-semibold mb-1"><i class="bi bi-files me-1"></i> Gli altri file nello ZIP</div>
        <p class="text-muted mb-0">
          Tutti gli altri file (PDF/DOCX/altro) vengono trattati come <strong>allegati linkabili</strong>:
          se nel testo del DOCX principale trovi riferimenti coerenti, vengono trasformati in link che puntano al file.
        </p>
      </div>
    </div>

    <!-- ALLEGATI / MATCH -->
    <div id="allegati" class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="section-title mb-2">Link agli allegati: regole pratiche</div>

        <div class="fw-semibold mb-2">Esempi consigliati (alta precisione)</div>
        <div class="codebox mb-3">
          <div class="muted">Nello ZIP:</div>
          <div>Documento 1.pdf</div>
          <div>Allegato 1.pdf</div>
          <div class="muted mt-3">Nel testo del DOCX principale puoi scrivere:</div>
          <div>Documento 1</div>
          <div>Documento n. 1</div>
          <div>Doc 1</div>
          <div>Doc. 1</div>
          <div class="muted mt-2">e</div>
          <div>Allegato 1</div>
          <div>Allegato n. 1</div>
          <div>All 1</div>
          <div>All. 1</div>
        </div>

        <div class="alert alert-info d-flex align-items-start gap-2 mb-0">
          <i class="bi bi-lightbulb"></i>
          <div>
            <div class="fw-semibold">Suggerimento operativo</div>
            <div class="small">
              Se vuoi che “Doc 1” linki davvero “Documento 1.pdf”, usa nomi numerati e scrivi nel testo una delle varianti sopra.
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ANTI AMBIGUITA -->
    <div id="antiamb" class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="section-title mb-2">Regole anti-ambiguità</div>

        <div class="fw-semibold mb-2"><i class="bi bi-shield-exclamation me-1"></i> Caso tipico: “Doc 1” vs “Documento 1.pdf”</div>
        <p class="text-muted">
          Se nel testo scrivi <span class="mono">Doc 1</span> e nello ZIP hai <span class="mono">Documento 1.pdf</span>,
          il match è consentito solo se non ci sono conflitti e se la variante è “sufficientemente specifica”.
        </p>

        <div class="fw-semibold mb-2"><i class="bi bi-bug me-1"></i> Caso conflitto: “Documento 1” e “Allegato 1”</div>
        <div class="codebox mb-2">
          <div class="muted">Se nello ZIP hai entrambe le famiglie:</div>
          <div>Documento 1.pdf</div>
          <div>Allegato 1.pdf</div>
          <div class="muted mt-2">Allora:</div>
          <div>“Doc 1” punta a Documento 1.pdf</div>
          <div>“All. 1” punta a Allegato 1.pdf</div>
          <div class="muted mt-2">Ma “1” da solo non viene linkato.</div>
        </div>

        <div class="text-muted small">
          Obiettivo: evitare link sbagliati. Se il riferimento è troppo vago o ambiguo, Linkage preferisce non linkare.
        </div>
      </div>
    </div>

  </div>

  <!-- COL DX (FAQ) -->
  <div class="col-lg-4">
    <div id="faq" class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="section-title mb-2">FAQ</div>

        <div class="accordion" id="faqAcc">

          <div class="accordion-item">
            <h2 class="accordion-header" id="q1">
              <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#a1">
                Se carico uno ZIP con più DOCX, cosa succede?
              </button>
            </h2>
            <div id="a1" class="accordion-collapse collapse show" data-bs-parent="#faqAcc">
              <div class="accordion-body text-muted">
                Il run va in <strong>In attesa</strong> e compare un modale per scegliere il DOCX principale.
              </div>
            </div>
          </div>

          <div class="accordion-item">
            <h2 class="accordion-header" id="q2">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a2">
                Perché un riferimento ad allegato non viene linkato?
              </button>
            </h2>
            <div id="a2" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
              <div class="accordion-body text-muted">
                Tipicamente per evitare ambiguità: testo troppo generico, abbreviazione non chiara, oppure più file “simili”.
                Usa nomi numerati e varianti coerenti (Documento 1 / Allegato 1).
              </div>
            </div>
          </div>

          <div class="accordion-item">
            <h2 class="accordion-header" id="q3">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a3">
                Dove vedo gli errori?
              </button>
            </h2>
            <div id="a3" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
              <div class="accordion-body text-muted">
                In caso di errore compare l’icona del log (<span class="mono">exec.log</span>) nel run.
              </div>
            </div>
          </div>

          <div class="accordion-item">
            <h2 class="accordion-header" id="q4">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a4">
                Il colore/link in Word è sempre blu e sottolineato?
              </button>
            </h2>
            <div id="a4" class="accordion-collapse collapse" data-bs-parent="#faqAcc">
              <div class="accordion-body text-muted">
                Sì: Linkage forza lo stile hyperlink (blu + underline) per rendere evidente il collegamento.
              </div>
            </div>
          </div>

        </div>

        <hr class="hr-soft my-3">

        <div class="d-grid gap-2">
          <a class="btn btn-primary" href="linkage.php">
            <i class="bi bi-magic me-1"></i> Apri Linkage
          </a>
          <a class="btn btn-outline-secondary" href="linkage.php#runsTable">
            <i class="bi bi-clock-history me-1"></i> Vai ai risultati
          </a>
        </div>

      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body">
        <div class="section-title mb-2">Best practice</div>
        <ul class="text-muted mb-0">
          <li>Nomina gli allegati con numerazione: <span class="mono">Documento 1.pdf</span>, <span class="mono">Allegato 2.pdf</span></li>
          <li>Nel testo usa forme coerenti: <span class="mono">Doc. 1</span>, <span class="mono">All. 2</span></li>
          <li>Evita riferimenti generici: “documento”, “allegato” senza numero</li>
        </ul>
      </div>
    </div>

  </div>
</div>

<?php include 'footer.php'; ?>
