<?php
session_start();          // serve per avere accesso alla sessione attiva
$_SESSION = [];           // svuota tutte le variabili

// elimina anche il cookie della sessione, se esiste
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

session_destroy();        // distrugge la sessione lato server

// redirect
header("Location: index.php");
exit;
