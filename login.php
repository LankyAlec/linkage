<?php
// login.php
include 'header.php'; // deve fornire solo $connection, nessun session_start()

$errore    = '';
$ok        = false;
$rehash    = false;
$old_email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = strtolower(trim($_POST['email'] ?? ''));
    $pass  = $_POST['password'] ?? '';
    $old_email = $email;

    if ($email === '' || $pass === '') {
        $errore = 'Inserisci email e password.';
    } else {
        $email_esc = mysqli_real_escape_string($connection, $email);
        $sql = "SELECT * FROM utenti WHERE email='$email_esc' LIMIT 1";
        $ris = mysqli_query($connection, $sql);

        if (!$ris) {
            $errore = 'Errore DB: ' . mysqli_error($connection);
        } else {
            if ($r = mysqli_fetch_assoc($ris)) {

                if (!empty($r['password']) && password_verify($pass, $r['password'])) {
                    $ok = true;

                    // Rehash se serve
                    if (password_needs_rehash($r['password'], PASSWORD_DEFAULT)) {
                        $newHash     = password_hash($pass, PASSWORD_DEFAULT);
                        $newHash_esc = mysqli_real_escape_string($connection, $newHash);
                        $id          = (int)$r['id'];
                        $update = "UPDATE utenti SET password='$newHash_esc' WHERE id=$id";
                        if (mysqli_query($connection, $update)) {
                            $rehash = true;
                        }
                    }

                    // === Avvio sessione SOLO se login riuscito ===
                    session_start();
                    session_regenerate_id(true);

                    $_SESSION['id_user']      = (int)$r['id'];
                    $_SESSION['nome']         = $r['nome'] ?? '';
                    $_SESSION['cognome']      = $r['cognome'] ?? '';
                    $_SESSION['email']        = $r['email'] ?? '';
                    $_SESSION['docRimanenti'] = $r['docRimanenti'] ?? null;
                    $_SESSION['status']       = $r['status'] ?? null;
                    $_SESSION['login']        = true;

                    header('Location: dashboard.php');
                    exit;

                } else {
                    $errore = 'Credenziali non valide.';
                }
            } else {
                $errore = 'Credenziali non valide.';
            }
            mysqli_free_result($ris);
        }
    }
}
?>

<h1 class="h4 mb-3">Accedi</h1>

<?php
if ($errore !== '') {
    echo "<div class='alert alert-danger'>" . htmlspecialchars($errore, ENT_QUOTES, 'UTF-8') . "</div>";
}
$old_email_safe = htmlspecialchars($old_email, ENT_QUOTES, 'UTF-8');
?>

<form method="post" class="card card-body shadow-sm">
  <div class="mb-3">
    <label class="form-label">Email</label>
    <input required type="email" name="email" class="form-control" value="<?= $old_email_safe ?>">
  </div>
  <div class="mb-3">
    <label class="form-label">Password</label>
    <input required type="password" name="password" class="form-control">
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-box-arrow-in-right"></i> Login</button>
    <a class="btn btn-outline-secondary" href="forgot_password.php">Password dimenticata?</a>
  </div>
</form>

<?php include 'footer.php'; ?>
