<?php include 'header.php'; ?>
<h1 class="h4 mb-3">Il tuo account</h1>
<div class="card shadow-sm">
  <div class="card-body">
    <p class="mb-1">
      <strong>Email:</strong> <?= htmlspecialchars($_SESSION['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>
    </p>
    <p class="text-muted mb-0">
      ID utente: <?= htmlspecialchars((string)($_SESSION['id_user'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
    </p>
  </div>
</div>
<?php include 'footer.php'; ?>
