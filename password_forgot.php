<?php include 'header.php';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!check_csrf($_POST['csrf'] ?? '')) { flash('err','Richiesta non valida.'); header('Location: password_forgot.php'); exit; }
    $email = trim($_POST['email'] ?? '');
    $email_esc = mysqli_real_escape_string($connection, $email);
    $u = mysqli_fetch_assoc(mysqli_query($connection, "SELECT id FROM users WHERE email='$email_esc' LIMIT 1"));
    if ($u) {
        $token = bin2hex(random_bytes(24));
        $tok_esc = mysqli_real_escape_string($connection, $token);
        $uid = (int)$u['id'];
        mysqli_query($connection, "INSERT INTO reset_tokens (id_user, token, created_at, used) VALUES ($uid, '$tok_esc', NOW(), 0)");
        // TODO: invio email vera. Per ora flash con link:
        flash('ok', "Link reset (demo): reset_password.php?token=$token");
    } else {
        // Non rivelare se l'email esiste
        flash('ok','Se l’email è presente, riceverai un link di reset.');
    }
    header('Location: password_forgot.php'); exit;
}
?>
<h1 class="h5 mb-3">Recupero password</h1>
<form method="post" class="card card-body shadow-sm">
  <input type="hidden" name="csrf" value="<?=e($csrf)?>">
  <div class="mb-3">
    <label class="form-label">Email</label>
    <input required type="email" name="email" class="form-control">
  </div>
  <button class="btn btn-primary">Invia link</button>
</form>
<?php include 'footer.php'; ?>
