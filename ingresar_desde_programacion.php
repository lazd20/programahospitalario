<?php
$host = 'localhost';
$dbname = 'sitiosnuevos_hospital';
$username = 'sitiosnuevos_cirtugia';
$password = 'Realmedic2020';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>