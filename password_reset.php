<?php include 'header.php';

$token = $_GET['token'] ?? '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!check_csrf($_POST['csrf'] ?? '')) { flash('err','Richiesta non valida.'); header('Location: password_reset.php?token='.urlencode($token)); exit; }
    $token = $_POST['token'] ?? '';
    $pass = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';
    if ($pass!==$pass2) { flash('err','Le password non coincidono.'); header('Location: password_reset.php?token='.urlencode($token)); exit; }

    $tok_esc = mysqli_real_escape_string($connection, $token);
    $row = mysqli_fetch_assoc(mysqli_query($connection, "SELECT id_user FROM reset_tokens WHERE token='$tok_esc' AND used=0 AND created_at >= NOW()-INTERVAL 1 DAY LIMIT 1"));
    if (!$row) { flash('err','Token non valido o scaduto.'); header('Location: forgot_password.php'); exit; }

    $uid = (int)$row['id_user'];
    $hash = mysqli_real_escape_string($connection, password_hash($pass, PASSWORD_DEFAULT));
    mysqli_query($connection, "UPDATE users SET password_hash='$hash' WHERE id=$uid");
    mysqli_query($connection, "UPDATE reset_tokens SET used=1 WHERE token='$tok_esc'");
    flash('ok','Password aggiornata, ora effettua il login.');
    header('Location: login.php'); exit;
}
?>
<h1 class="h5 mb-3">Imposta nuova password</h1>
<form method="post" class="card card-body shadow-sm">
  <input type="hidden" name="csrf" value="<?=e($csrf)?>">
  <input type="hidden" name="token" value="<?=e($token)?>">
  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Nuova password</label>
      <input required type="password" name="password" class="form-control">
    </div>
    <div class="col-md-6">
      <label class="form-label">Conferma</label>
      <input required type="password" name="password2" class="form-control">
    </div>
  </div>
  <button class="btn btn-primary mt-3">Aggiorna</button>
</form>
<?php include 'footer.php'; ?>
