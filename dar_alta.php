<?php
// /public_html/evoprx/residentes/usuarios.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: text/html; charset=utf-8');

// ✅ Solo admin
// ✅ Solo admin (tolerante: role o rol)
$u = function_exists('current_user') ? current_user() : null;
$role = (string)($_SESSION['role'] ?? ($_SESSION['rol'] ?? ($u['rol'] ?? ($u['role'] ?? ''))));

if ($role !== 'admin') {
    $to = function_exists('base_url') ? base_url('/residentes/panel_ingresos.php') : 'panel_ingresos.php';
    header("Location: {$to}");
    exit;
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    // $pdo debe venir desde helpers.php (igual que en tus otros archivos que ya funcionan)
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception("No existe \$pdo. Revisa helpers.php / conexión.");
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    // ✅ Prefijos
    $T_USUARIOS = 'hosp_usuarios';

    // =========================
    // Crear nuevo usuario
    // =========================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear'])) {
        $nuevoUser = trim($_POST['username'] ?? '');
        $passPlain = (string)($_POST['password'] ?? '');
        $nuevoRol  = trim($_POST['role'] ?? '');

        if ($nuevoUser === '' || $passPlain === '' || !in_array($nuevoRol, ['admin','editor','viewer'], true)) {
            throw new Exception("Datos inválidos para crear usuario.");
        }

        // Evitar duplicados por username
        $chk = $pdo->prepare("SELECT COUNT(*) FROM {$T_USUARIOS} WHERE username = ? LIMIT 1");
        $chk->execute([$nuevoUser]);
        if ((int)$chk->fetchColumn() > 0) {
            throw new Exception("Ya existe un usuario con ese username.");
        }

        $nuevoPass = password_hash($passPlain, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare("INSERT INTO {$T_USUARIOS} (username, password, role) VALUES (?, ?, ?)");
        $stmt->execute([$nuevoUser, $nuevoPass, $nuevoRol]);

        $to = function_exists('base_url') ? base_url('/residentes/usuarios.php?ok=creado') : 'usuarios.php?ok=creado';
        header("Location: {$to}");
        exit;
    }

    // =========================
    // Actualizar usuario
    // =========================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar'])) {
        $id = (int)($_POST['id'] ?? 0);
        $nuevoRol = trim($_POST['role'] ?? '');
        $nuevaClave = trim($_POST['password'] ?? '');

        if ($id <= 0 || !in_array($nuevoRol, ['admin','editor','viewer'], true)) {
            throw new Exception("Datos inválidos para actualizar.");
        }

        if ($nuevaClave !== '') {
            $claveHash = password_hash($nuevaClave, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE {$T_USUARIOS} SET role = ?, password = ? WHERE id = ?");
            $stmt->execute([$nuevoRol, $claveHash, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE {$T_USUARIOS} SET role = ? WHERE id = ?");
            $stmt->execute([$nuevoRol, $id]);
        }

        $to = function_exists('base_url') ? base_url('/residentes/usuarios.php?ok=guardado') : 'usuarios.php?ok=guardado';
        header("Location: {$to}");
        exit;
    }

    // =========================
    // Eliminar usuario
    // =========================
    if (isset($_GET['eliminar'])) {
        $idEliminar = (int)$_GET['eliminar'];
        $miId = (int)($_SESSION['usuario_id'] ?? 0);

        if ($idEliminar <= 0) {
            throw new Exception("ID inválido.");
        }
        if ($idEliminar === $miId) {
            throw new Exception("No puede eliminar su propio usuario.");
        }

        $stmt = $pdo->prepare("DELETE FROM {$T_USUARIOS} WHERE id = ?");
        $stmt->execute([$idEliminar]);

        $to = function_exists('base_url') ? base_url('/residentes/usuarios.php?ok=eliminado') : 'usuarios.php?ok=eliminado';
        header("Location: {$to}");
        exit;
    }

    // Listado
    $usuarios = $pdo->query("SELECT id, username, role FROM {$T_USUARIOS} ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Usuarios</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5 mb-5">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">👥 Gestión de Usuarios</h2>
        <a href="<?= function_exists('base_url') ? h(base_url('/residentes/panel_ingresos.php')) : 'panel_ingresos.php' ?>" class="btn btn-secondary btn-sm">
            ← Volver
        </a>
    </div>

    <?php if (!empty($error ?? '')): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($_GET['ok'])): ?>
        <div class="alert alert-success">
            <?php if ($_GET['ok'] === 'creado') echo '✅ Usuario creado.'; ?>
            <?php if ($_GET['ok'] === 'guardado') echo '✅ Usuario actualizado.'; ?>
            <?php if ($_GET['ok'] === 'eliminado') echo '✅ Usuario eliminado.'; ?>
        </div>
    <?php endif; ?>

    <!-- Crear usuario -->
    <form method="POST" class="row g-2 mb-4">
        <div class="col-md-4">
            <input type="text" name="username" class="form-control" placeholder="Nuevo usuario" required>
        </div>
        <div class="col-md-4">
            <input type="password" name="password" class="form-control" placeholder="Contraseña" required>
        </div>
        <div class="col-md-2">
            <select name="role" class="form-select" required>
                <option value="">Rol</option>
                <option value="admin">Admin</option>
                <option value="editor">Editor</option>
                <option value="viewer">Viewer</option>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" name="crear" class="btn btn-success w-100">+ Crear</button>
        </div>
    </form>

    <!-- Lista editable -->
    <div class="table-responsive">
        <table class="table table-bordered table-hover text-center align-middle">
            <thead class="table-dark">
                <tr>
                    <th style="width:80px;">ID</th>
                    <th>Usuario</th>
                    <th style="width:160px;">Rol</th>
                    <th>Contraseña nueva</th>
                    <th style="width:240px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($usuarios)): ?>
                <tr>
                    <td colspan="5" class="text-muted py-4">No hay usuarios.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($usuarios as $user): ?>
                    <tr>
                        <form method="POST">
                            <td><?= (int)$user['id'] ?></td>
                            <td><?= h($user['username']) ?></td>
                            <td>
                                <select name="role" class="form-select form-select-sm">
                                    <option value="admin"  <?= ($user['role'] === 'admin') ? 'selected' : '' ?>>Admin</option>
                                    <option value="editor" <?= ($user['role'] === 'editor') ? 'selected' : '' ?>>Editor</option>
                                    <option value="viewer" <?= ($user['role'] === 'viewer') ? 'selected' : '' ?>>Viewer</option>
                                </select>
                            </td>
                            <td>
                                <input type="password" name="password" class="form-control form-control-sm" placeholder="(sin cambios)">
                            </td>
                            <td class="d-flex gap-2 justify-content-center">
                                <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
                                <button type="submit" name="guardar" class="btn btn-sm btn-primary">💾 Guardar</button>

                                <?php if ((int)$user['id'] !== (int)($_SESSION['usuario_id'] ?? 0)): ?>
                                    <a class="btn btn-sm btn-danger"
                                       href="?eliminar=<?= (int)$user['id'] ?>"
                                       onclick="return confirm('¿Eliminar este usuario?');">
                                        🗑 Eliminar
                                    </a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled>—</button>
                                <?php endif; ?>
                            </td>
                        </form>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
</body>
</html>