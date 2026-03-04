<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Obtiene PDO compatible con tu proyecto:
 * - Si existe pdo() úsala
 * - Si existe getPDO() úsala
 * - Si no, usa $pdo global (cargado por auth.php/helpers.php)
 */
function db(): PDO {
    if (function_exists('pdo')) {
        /** @var PDO $p */
        $p = pdo();
        return $p;
    }
    if (function_exists('getPDO')) {
        /** @var PDO $p */
        $p = getPDO();
        return $p;
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    throw new RuntimeException('No hay conexión PDO disponible (pdo/getPDO/$pdo).');
}

function jerr(int $code, string $msg, ?string $error = null): void {
    http_response_code($code);
    echo json_encode([
        'ok' => false,
        'msg' => $msg,
        'error' => $error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function jok(string $msg, int $id, string $nombre): void {
    echo json_encode([
        'ok' => true,
        'msg' => $msg,
        'id' => $id,
        'nombre' => $nombre
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// (Opcional) permisos: solo admin/editor pueden crear médicos
if (function_exists('current_user')) {
    $u = current_user();
    $rol = (string)($u['rol'] ?? '');
    if (!in_array($rol, ['admin', 'editor'], true)) {
        jerr(403, 'No tienes permiso para crear médicos.');
    }
}

// Validar input
$nombre = trim((string)($_POST['nombre'] ?? ''));
$especialidad = trim((string)($_POST['especialidad'] ?? ''));

if ($nombre === '' || $especialidad === '') {
    jerr(400, 'Complete nombre y especialidad.');
}

// Normalizar espacios
$nombre_limpio = preg_replace('/\s+/', ' ', $nombre);
$especialidad_limpia = preg_replace('/\s+/', ' ', $especialidad);

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    // Buscar duplicado (case-insensitive)
    $stmt = $pdo->prepare("
        SELECT id, nombre
        FROM hosp_cirujanos
        WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?))
        LIMIT 1
    ");
    $stmt->execute([$nombre_limpio]);

    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        jok('El médico ya existía. Se seleccionará.', (int)$row['id'], (string)$row['nombre']);
    }

    // Insertar
    $ins = $pdo->prepare("
        INSERT INTO hosp_cirujanos (nombre, especialidad)
        VALUES (?, ?)
    ");
    $ins->execute([$nombre_limpio, $especialidad_limpia]);

    jok('Médico creado correctamente.', (int)$pdo->lastInsertId(), $nombre_limpio);

} catch (Throwable $e) {
    jerr(500, 'Error al crear médico.', $e->getMessage());
}