<?php
// registrazione.php
include ('header.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!check_csrf($_POST['csrf'] ?? '')) {
        flash('err','Richiesta non valida.'); header('Location: registrazione.php'); exit;
    }

    // --- INPUT ---
    $nome    = trim($_POST['nome'] ?? '');
    $cognome = trim($_POST['cognome'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $pwd     = $_POST['password'] ?? '';
    $pwd2    = $_POST['password2'] ?? '';

    // Fatturazione
    $cf      = strtoupper(trim($_POST['codice_fiscale'] ?? ''));
    $piva    = trim($_POST['partita_iva'] ?? '');
    $indir   = trim($_POST['indirizzo'] ?? '');
    $cap     = trim($_POST['cap'] ?? '');
    $citta   = trim($_POST['citta'] ?? '');
    $prov    = strtoupper(trim($_POST['provincia'] ?? ''));
    $nazione = trim($_POST['nazione'] ?? '');
    $pec     = trim($_POST['pec'] ?? '');
    $sdi     = trim($_POST['sdi'] ?? '');

    // --- VALIDAZIONI SERVER ---
    $errors = [];
    if ($nome === '' || $cognome === '') $errors[] = 'Nome e cognome sono obbligatori.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email non valida.';

    $policy = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/';
    if ($pwd !== $pwd2) $errors[] = 'Le password non coincidono.';
    if (!preg_match($policy, $pwd)) $errors[] = 'La password non rispetta i requisiti.';

    // Email univoca (stile richiesto)
    $email_esc = mysqli_real_escape_string($connection, $email);
    $select_email = 'SELECT COUNT(*) AS n FROM utenti WHERE email="'.$email_esc.'" LIMIT 1';
    $ris=mysqli_query($connection, $select_email) or die("<br>Query verifica email fallita");
    while($r = mysqli_fetch_array($ris)){$exists=$r['n'];}
    if (!empty($exists)) $errors[] = 'Email già registrata.';

    if ($errors) { flash('err', implode(' ', $errors)); header('Location: registrazione.php'); exit; }

    // --- INSERT (stile richiesto) ---
    $hash = password_hash($pwd, PASSWORD_DEFAULT);

    $nome_esc  = mysqli_real_escape_string($connection, $nome);
    $cogn_esc  = mysqli_real_escape_string($connection, $cognome);
    $pwd_esc   = mysqli_real_escape_string($connection, $hash);
    $cf_esc    = mysqli_real_escape_string($connection, $cf);
    $piva_esc  = mysqli_real_escape_string($connection, $piva);
    $indir_esc = mysqli_real_escape_string($connection, $indir);
    $cap_esc   = mysqli_real_escape_string($connection, $cap);
    $citta_esc = mysqli_real_escape_string($connection, $citta);
    $prov_esc  = mysqli_real_escape_string($connection, $prov);
    $naz_esc   = mysqli_real_escape_string($connection, $nazione);
    $pec_esc   = mysqli_real_escape_string($connection, $pec);
    $sdi_esc   = mysqli_real_escape_string($connection, $sdi);

    $insert_user = 'INSERT INTO utenti
        (`nome`,`cognome`,`email`,`password`, `status`)
        VALUES
        ("'.$nome_esc.'","'.$cogn_esc.'","'.$email_esc.'","'.$pwd_esc.'", "attesa")';

    $ris=mysqli_query($connection, $insert_user) or die("<br>Query Insert utente fallita<br>".$insert_user);
    // facoltativo: recupero id inserito con stile richiesto
    $select_last='SELECT * FROM utenti ORDER BY id DESC limit 1';
    $ris=mysqli_query($connection, $select_last) or die("<br>Query Select ID utente fallita");
    while ($id_query = mysqli_fetch_array($ris)){$id=$id_query['id'];}

    flash('ok','Registrazione completata. Ora effettua il login.');
    header('Location: login.php'); exit;
}
?>

<h1 class="h4 mb-3">Crea un nuovo account</h1>

<form method="post" class="card card-body shadow-sm" id="formReg" novalidate>
  <input type="hidden" name="csrf" value="<? echo $csrf; ?>">

  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Nome</label>
      <input type="text" name="nome" class="form-control" required>
    </div>
    <div class="col-md-6">
      <label class="form-label">Cognome</label>
      <input type="text" name="cognome" class="form-control" required>
    </div>

    <div class="col-12">
      <label class="form-label">Email</label>
      <input type="email" name="email" class="form-control" required>
    </div>

    <!-- Password -->
    <div class="col-md-6">
      <label for="password" class="form-label">Password</label>
      <div class="input-group">
        <input type="password" class="form-control" id="password" name="password" required>
        <button class="btn btn-outline-secondary" type="button" id="togglePassword" aria-label="Mostra password">
          <i class="bi bi-eye"></i>
        </button>
      </div>
      <div class="form-text">Almeno 8 caratteri, 1 maiuscola, 1 minuscola, 1 numero e 1 simbolo.</div>

      <!-- chips requisiti -->
      <div class="mt-2 d-flex flex-wrap gap-2 small" id="pwdChips">
        <span class="badge rounded-pill text-bg-light border" id="chipLen">Min 8</span>
        <span class="badge rounded-pill text-bg-light border" id="chipMai">A‑Z</span>
        <span class="badge rounded-pill text-bg-light border" id="chipMin">a‑z</span>
        <span class="badge rounded-pill text-bg-light border" id="chipNum">0‑9</span>
        <span class="badge rounded-pill text-bg-light border" id="chipSpec">!@#</span>
      </div>
    </div>

    <!-- Conferma -->
    <div class="col-md-6">
      <label for="password2" class="form-label">Conferma password</label>
      <div class="input-group">
        <input type="password" class="form-control" id="password2" name="password2" required>
        <button class="btn btn-outline-secondary" type="button" id="togglePassword2" aria-label="Mostra conferma">
          <i class="bi bi-eye"></i>
        </button>
      </div>
      <div class="form-text">Corrispondenza</div>
      <div class="mt-2 small">
        <span class="badge rounded-pill text-bg-light border" id="chipMatch">Match</span>
      </div>
    </div>

    <div class="col-12 d-flex gap-2 mt-2">
      <button class="btn btn-primary" id="btnSubmit" disabled>
        <i class="bi bi-person-plus"></i> Crea account
      </button>
      <a class="btn btn-outline-secondary" href="login.php">Annulla</a>
    </div>
  </div>
</form>

<script>
// --- elementi
const pwd  = document.getElementById('password');
const pwd2 = document.getElementById('password2');
const btn  = document.getElementById('btnSubmit');

// (opzionali) campi fatturazione: possono non esserci nella pagina
const cf   = document.getElementById('cf');
const piva = document.getElementById('piva');

// chips
const chipLen  = document.getElementById('chipLen');
const chipMai  = document.getElementById('chipMai');
const chipMin  = document.getElementById('chipMin');
const chipNum  = document.getElementById('chipNum');
const chipSpec = document.getElementById('chipSpec');
const chipMatch= document.getElementById('chipMatch');

// occhietti
document.getElementById('togglePassword').addEventListener('click', (e) => {
  const i = e.currentTarget.querySelector('i');
  pwd.type = (pwd.type === 'password') ? 'text' : 'password';
  i.classList.toggle('bi-eye'); i.classList.toggle('bi-eye-slash');
});
document.getElementById('togglePassword2').addEventListener('click', (e) => {
  const i = e.currentTarget.querySelector('i');
  pwd2.type = (pwd2.type === 'password') ? 'text' : 'password';
  i.classList.toggle('bi-eye'); i.classList.toggle('bi-eye-slash');
});

function setChip(chip, ok){
  chip.classList.remove('text-bg-light','text-bg-danger','text-bg-success');
  chip.classList.add(ok ? 'text-bg-success' : 'text-bg-danger');
}

function fiscalOk() {
  // Se i campi non esistono, considerali OK
  if (!cf && !piva) return true;
  const cfv  = (cf?.value || '').trim();
  const piv  = (piva?.value || '').trim();
  return (cfv !== '' || piv !== '');
}

function checkAll(){
  const v  = pwd.value || '';
  const v2 = pwd2.value || '';

  const okLen  = v.length >= 8;
  const okMai  = /[A-Z]/.test(v);
  const okMin  = /[a-z]/.test(v);
  const okNum  = /\d/.test(v);
  const okSpec = /[^A-Za-z0-9]/.test(v);
  const okMatch= v !== '' && v === v2;

  setChip(chipLen,  okLen);
  setChip(chipMai,  okMai);
  setChip(chipMin,  okMin);
  setChip(chipNum,  okNum);
  setChip(chipSpec, okSpec);
  setChip(chipMatch,okMatch);

  btn.disabled = !(okLen && okMai && okMin && okNum && okSpec && okMatch && fiscalOk());
}

// Aggancia eventi solo agli elementi esistenti
[pwd, pwd2].forEach(el => el.addEventListener('input', checkAll));
if (cf)   cf.addEventListener('input', checkAll);
if (piva) piva.addEventListener('input', checkAll);

document.addEventListener('DOMContentLoaded', checkAll);
</script>


<?php include ('footer.php'); ?>
