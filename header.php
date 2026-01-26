<?php
// header.php
include('database.php');

// Avvio "lazy": apri la sessione solo se il browser ha già un cookie di sessione
if (session_status() !== PHP_SESSION_ACTIVE && isset($_COOKIE[session_name()])) {
    session_start();
}

$isLogged = !empty($_SESSION['login']) && !empty($_SESSION['id_user']);
$nome     = isset($_SESSION['nome']) ? htmlspecialchars($_SESSION['nome'], ENT_QUOTES, 'UTF-8') : '';
$cognome  = isset($_SESSION['cognome']) ? htmlspecialchars($_SESSION['cognome'], ENT_QUOTES, 'UTF-8') : '';
?>
<!doctype html>
<html lang="it" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Avvocati</title>
  <!-- Bootstrap 5 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .container-narrow{max-width:1100px}
    .file-tile{border:1px solid #eee;border-radius:12px;padding:12px}
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom">
  <div class="container">
    <a class="navbar-brand fw-semibold" href="index.php">Avvocati</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div id="nav" class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if ($isLogged): ?>
          <li class="nav-item"><a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="linkage.php"><i class="bi bi-link-45deg"></i> Linkage</a></li>
          <li class="nav-item"><a class="nav-link" href="clienti.php"><i class="bi bi-people"></i> Clienti</a></li>
          <li class="nav-item"><a class="nav-link" href="statistiche.php"><i class="bi bi-bar-chart-line"></i> Statistiche</a></li>
          <li class="nav-item"><a class="nav-link" href="guadagni.php"><i class="bi bi-currency-euro"></i> Guadagni</a></li>


        <?php endif; ?>
      </ul>

      <ul class="navbar-nav">
        <?php if (!$isLogged): ?>
          <li class="nav-item"><a class="nav-link" href="login.php">Login</a></li>
          <li class="nav-item"><a class="nav-link" href="registrazione.php">Registrati</a></li>
        <?php else: ?>
          <li class="nav-item me-2 d-flex align-items-center">
            <span class="nav-link mb-0">
              <i class="bi bi-person-circle"></i>
              <?= $nome ?> <?= $cognome ?>
            </span>
          </li>
          <li class="nav-item">
            <form action="logout.php" method="post" class="d-inline">
              <button class="btn btn-outline-danger btn-sm">
                <i class="bi bi-box-arrow-right"></i> Logout
              </button>
            </form>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<main class="container container-narrow py-4">
