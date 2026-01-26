<?php
// index.php — Home “Avvocati” (nessun dato personale, no link/no button)
include 'header.php';
?>
<style>
/* ====== PALETTE (tua) ====== */
:root{
  --primary:#22946F;   /* verde petrolio */
  --accent:#38B2AC;    /* verde acqua */
  --dark:#1F2937;      /* grigio antracite */
  --page-bg:#F3F5F6;   /* sfondo sito più caldo del bianco */
  --muted:#6B7280;     /* testo secondario */
  --line:#E7EAED;      /* linee/separatori */
}

/* ====== BASE ====== */
html,body{background:var(--page-bg);}
.container-narrow{max-width:1000px}

/* Titoli di sezione con micro-accento */
.section-head{
  display:flex; align-items:center; gap:.6rem;
  margin-bottom: .6rem;
}
.section-dot{
  width:10px; height:10px; border-radius:999px;
  background: linear-gradient(135deg, var(--primary), var(--accent));
  box-shadow: 0 0 0 4px rgba(56,178,172,.15);
}
.section-title{
  text-transform:uppercase; letter-spacing:.06em; font-weight:800; font-size:1rem;
  color:var(--dark); opacity:.95; margin:0;
}

/* ====== HERO (più padding + ombra) ====== */
.sec-hero{
  margin-top:-.5rem;
  padding: 68px 42px;            /* più pieno */
  background:
    radial-gradient(1000px 380px at 85% -40%, rgba(56,178,172,.25), transparent 70%),
    linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
  color:#fff;
  border-radius:22px;
  box-shadow: 0 14px 36px rgba(2,6,23,.15);
}
.kicker{letter-spacing:.08em; text-transform:uppercase; font-weight:800; opacity:.95; margin-bottom:.25rem}
.hero-title{font-weight:900; letter-spacing:.005em; margin-bottom:.4rem}
.hero-text{opacity:.98; max-width: 820px; font-size:1.05rem}

/* ====== MAIN WRAPPER (unico box con ombra) ====== */
.main-card{
  background:#fff;
  border-radius:24px;
  box-shadow: 0 18px 48px rgba(2,6,23,.12);
  padding: 28px 28px 6px;
  margin-top: 28px;
  border: 1px solid #EEF1F3;
}

/* Separatore sezione dentro main-card */
.section{
  padding: 18px 0 22px;
}
.section + .section{
  border-top: 1px solid var(--line);
  margin-top: 8px;
}

/* ====== CARD INTERNE (flat, premium) ====== */
.card-flat{
  background: #FCFDFD;                 /* quasi bianco per stacco */
  border: 1px solid #EFF3F3;
  border-radius:18px;
  padding:16px;
  transition: transform .22s ease, box-shadow .22s ease, background .22s ease;
}
.card-flat:hover{
  transform: translateY(-2px);
  box-shadow: 0 12px 30px rgba(2,6,23,.08);
  background: #FFFFFF;
}

/* Intestazione card */
.card-title{
  font-weight:800; color:var(--dark); margin:0;
}
.icon-badge{
  width:46px; height:46px; border-radius:14px;
  display:inline-flex; align-items:center; justify-content:center;
  color:#fff;
  background: linear-gradient(140deg, var(--accent), #6FE0DB);
  box-shadow: inset 0 0 0 1px rgba(255,255,255,.35), 0 2px 10px rgba(56,178,172,.25);
}
.text-muted-legal{color:var(--muted);}

/* Prezzi */
.price-shell{
  background: #F2F7F6;
  border:1px solid #E1EEEC;
  border-radius:18px;
  padding: 16px;
}
.price-card{
  background:#fff; border:1px solid #EAF1F0; border-radius:16px; padding:16px;
  transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
}
.price-card:hover{ transform: translateY(-2px); box-shadow: 0 10px 26px rgba(2,6,23,.08); border-color: rgba(56,178,172,.35); }
.price-name{font-weight:900; color:var(--primary)}
.price-note{color:var(--muted)}

/* Liste puntate */
.list-clean{margin:0; padding-left:1.1rem}
.list-clean li{margin:.35rem 0; color:var(--muted)}

/* Micro rifiniture */
.small-muted{font-size:.95rem; color:var(--muted)}
.pad-slim{padding-top:6px}
</style>

<div class="container container-narrow">

  <!-- HERO (solo testo, no link/no button) -->
  <section class="sec-hero mt-3">
    <div class="kicker">Piattaforma</div>
    <h1 class="h2 hero-title">Avvocati — Workspace</h1>
    <p class="hero-text">
      Carica atti in Word e ottieni automaticamente <strong>link URN</strong> a norme, codici e giurisprudenza.
      Mantieni coerenza citazionale, conserva lo stile del documento e traccia ogni elaborazione.
    </p>
  </section>

  <!-- MAIN CARD che contiene TUTTO il resto -->
  <div class="main-card">

    <!-- PREZZI -->
    <section class="section">
      <div class="section-head">
        <span class="section-dot"></span><h2 class="section-title">Prezzi</h2>
      </div>
      <p class="small-muted">Struttura tariffaria in definizione. Piani flessibili per studi individuali e realtà strutturate.</p>
      <div class="price-shell mb-2">
        <div class="row g-3 text-center">
          <div class="col-md-4">
            <div class="price-card h-100">
              <div class="price-name mb-1">Piano Base</div>
              <div class="price-note">In definizione</div>
              <div class="badge bg-secondary mt-2">Prossimamente</div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="price-card h-100">
              <div class="price-name mb-1">Piano Professionale</div>
              <div class="price-note">In definizione</div>
              <div class="badge bg-secondary mt-2">Prossimamente</div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="price-card h-100">
              <div class="price-name mb-1">Piano Enterprise</div>
              <div class="price-note">In definizione</div>
              <div class="badge bg-secondary mt-2">Prossimamente</div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- COSA PUOI FARE -->
    <section class="section">
      <div class="section-head">
        <span class="section-dot"></span><h2 class="section-title">Cosa puoi fare</h2>
      </div>
      <div class="row g-3">
        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="d-flex align-items-center gap-3 mb-2">
              <span class="icon-badge"><i class="bi bi-link-45deg"></i></span>
              <h5 class="card-title">Link URN Normattiva</h5>
            </div>
            <p class="text-muted-legal mb-0">Riconoscimento dei riferimenti (es. <em>art. 1720 c.c.</em>, <em>D.M. 55/2014</em>) e creazione di link URN standardizzati.</p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="d-flex align-items-center gap-3 mb-2">
              <span class="icon-badge"><i class="bi bi-filetype-docx"></i></span>
              <h5 class="card-title">Output fedele</h5>
            </div>
            <p class="text-muted-legal mb-0">Mantiene lo stile originale (grassetto, corsivo, colori, evidenziazione): si aggiunge solo l’hyperlink.</p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="d-flex align-items-center gap-3 mb-2">
              <span class="icon-badge"><i class="bi bi-gear-wide-connected"></i></span>
              <h5 class="card-title">Tracciamento & log</h5>
            </div>
            <p class="text-muted-legal mb-0">Motore Python dedicato, directory per run e log consultabili per controllo e audit.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- SERVIZI PER LO STUDIO -->
    <section class="section">
      <div class="section-head">
        <span class="section-dot"></span><h2 class="section-title">Servizi per lo studio</h2>
      </div>
      <div class="row g-3">
        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="d-flex align-items-center gap-3 mb-2">
              <span class="icon-badge"><i class="bi bi-people"></i></span>
              <h5 class="card-title">Archivio clienti & documenti</h5>
            </div>
            <p class="text-muted-legal mb-2">Schede cliente, pratiche, deleghe e raccolta documenti per fascicolo.</p>
            <ul class="list-clean pad-slim">
              <li>Cartelle per pratica e stati avanzamento</li>
              <li>Versioning file e anteprime</li>
            </ul>
          </div>
        </div>

        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="d-flex align-items-center gap-3 mb-2">
              <span class="icon-badge"><i class="bi bi-calendar2-event"></i></span>
              <h5 class="card-title">Calendario udienze</h5>
            </div>
            <p class="text-muted-legal mb-2">Udienze, termini e appuntamenti con scadenziario e promemoria.</p>
            <ul class="list-clean pad-slim">
              <li>Import/Export iCal e viste condivise</li>
              <li>Promemoria email</li>
            </ul>
          </div>
        </div>

        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="d-flex align-items-center gap-3 mb-2">
              <span class="icon-badge"><i class="bi bi-alarm"></i></span>
              <h5 class="card-title">Scadenze & promemoria</h5>
            </div>
            <p class="text-muted-legal mb-2">Deadlines per depositi, memorie e termini perentori con livelli di priorità.</p>
            <ul class="list-clean pad-slim">
              <li>Promemoria ricorrenti</li>
              <li>Vista per pratica o per data</li>
            </ul>
          </div>
        </div>

        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="d-flex align-items-center gap-3 mb-2">
              <span class="icon-badge"><i class="bi bi-search"></i></span>
              <h5 class="card-title">Ricerca full-text</h5>
            </div>
            <p class="text-muted-legal mb-2">Trova atti e allegati per titolo, note e contenuto indicizzato.</p>
            <ul class="list-clean pad-slim">
              <li>Filtri per pratica/cliente</li>
              <li>Tag e categorie personalizzate</li>
            </ul>
          </div>
        </div>

        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="d-flex align-items-center gap-3 mb-2">
              <span class="icon-badge"><i class="bi bi-shield-lock"></i></span>
              <h5 class="card-title">Condivisione sicura</h5>
            </div>
            <p class="text-muted-legal mb-2">Link protetti con scadenza; controllo permessi e revoche; log accessi.</p>
            <ul class="list-clean pad-slim">
              <li>Permessi granulari</li>
              <li>Download tracciati</li>
            </ul>
          </div>
        </div>

        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="d-flex align-items-center gap-3 mb-2">
              <span class="icon-badge"><i class="bi bi-files"></i></span>
              <h5 class="card-title">Modelli & template</h5>
            </div>
            <p class="text-muted-legal mb-2">Libreria di modelli Word con segnaposto e compilazione assistita.</p>
            <ul class="list-clean pad-slim">
              <li>Variabili di studio e cliente</li>
              <li>Versioni approvate dal team</li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <!-- COME FUNZIONA -->
    <section class="section">
      <div class="section-head">
        <span class="section-dot"></span><h2 class="section-title">Come funziona</h2>
      </div>
      <div class="row g-3">
        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="d-flex align-items-center mb-1">
              <div class="icon-badge me-2">1</div><div class="fw-semibold">Carica il .docx</div>
            </div>
            <p class="text-muted-legal mb-0">Seleziona il documento Word da elaborare.</p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="d-flex align-items-center mb-1">
              <div class="icon-badge me-2">2</div><div class="fw-semibold">Parsing & linking</div>
            </div>
            <p class="text-muted-legal mb-0">Il motore individua i riferimenti e genera gli URN.</p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="d-flex align-items-center mb-1">
              <div class="icon-badge me-2">3</div><div class="fw-semibold">Scarica l’output</div>
            </div>
            <p class="text-muted-legal mb-0">Ottieni il .docx con link attivi e il log di esecuzione.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- VANTAGGI & SICUREZZA -->
    <section class="section">
      <div class="section-head">
        <span class="section-dot"></span><h2 class="section-title">Perché usarlo / Sicurezza</h2>
      </div>
      <div class="row g-3">
        <div class="col-md-6">
          <div class="card-flat h-100">
            <ul class="list-clean pad-slim mb-0">
              <li>Uniformità citazionale e riduzione degli errori</li>
              <li>Rispetto dello stile originale del documento</li>
              <li>Output e log versionati per audit</li>
              <li>Workflow rapido: nessun plugin Word richiesto</li>
            </ul>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card-flat h-100">
            <ul class="list-clean pad-slim mb-0">
              <li>Cartelle per utente e run con timestamp</li>
              <li>Tracciamento esiti e consultazione log</li>
              <li>Permessi e autenticazione applicativa</li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <!-- FAQ -->
    <section class="section">
      <div class="section-head">
        <span class="section-dot"></span><h2 class="section-title">FAQ rapide</h2>
      </div>
      <div class="row g-3">
        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="fw-semibold mb-1">Quali formati supporta?</div>
            <p class="text-muted-legal mb-0">Documento <code>.docx</code>. L’output è sempre un <code>.docx</code> con i link inseriti.</p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="fw-semibold mb-1">Cosa succede allo stile?</div>
            <p class="text-muted-legal mb-0">Viene mantenuto: si aggiunge solo l’hyperlink sul testo della norma.</p>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card-flat h-100">
            <div class="fw-semibold mb-1">Dove trovo i file?</div>
            <p class="text-muted-legal mb-0">Ogni run contiene <code>input.docx</code>, <code>output.docx</code> e <code>exec.log</code>.</p>
          </div>
        </div>
      </div>
    </section>

  </div><!-- /main-card -->
</div><!-- /container -->

<?php include 'footer.php'; ?>
