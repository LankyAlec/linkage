<?php

$admin="LankyAlec";
$password="-Alessio89-";
$nome_db="LawerDB";

$connection = mysqli_connect("localhost", $admin, $password, $nome_db);

if (!$connection) 
	{
		echo "Non riesco a connettermi al Database";
	}

$db_selected = mysqli_select_db($connection, $nome_db);

if (!$connection) 
	{
		echo "Errore nella selezione del database:". mysql_error();
	}

?>