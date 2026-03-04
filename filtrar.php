<?php
// /public_html/evoprx/programacion/buscar_cirujano.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

header('Content-Type: text/html; charset=UTF-8');

// Usuario/rol con el NUEVO esquema (compatible con varias claves)
$u = function_exists('current_user') ? current_user() : null;
$role = (string)($_SESSION['role'] ?? ($_SESSION['rol'] ?? ($u['rol'] ?? ($u['role'] ?? ''))));

// (Opcional) Si quieres restringir a ciertos roles, descomenta:
// if (!in_array($role, ['admin', 'editor', 'viewer'], true)) { exit; }

$q = trim((string)($_GET['q'] ?? ''));

if ($q === '') {
    exit; // no imprime nada
}

try {
    // Asegurar UTF8 por si acaso (si tu helpers.php ya lo hace, no estorba)
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    $stmt = $pdo->prepare("
        SELECT id, nombre
        FROM hosp_cirujanos
        WHERE nombre LIKE :nombre
        ORDER BY nombre ASC
        LIMIT 10
    ");
    $stmt->execute([':nombre' => '%' . $q . '%']);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo '<a href="#"
                 class="list-group-item list-group-item-action item-cirujano"
                 data-id="' . (int)$row['id'] . '">'
             . htmlspecialchars((string)$row['nombre'], ENT_QUOTES, 'UTF-8')
             . '</a>';
    }
} catch (Throwable $e) {
    http_response_code(500);
    // No exponemos el error real en producción
    echo '<div class="list-group-item text-danger">Error al buscar cirujano</div>';
}