<?php
// /public_html/evoprx/programacion/crear_usuario.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';
$u = current_user();

// Solo admin
if (($u['rol'] ?? '') !== 'admin') {
  http_response_code(403);
  echo "<div class='alert alert-danger m-3'>No tienes permiso para acceder a esta página.</div>";
  exit;
}

$mensaje = '';
$tipoMensaje = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = (string)($_POST['_csrf'] ?? '');
  if (!csrf_check($token)) {
    $mensaje = 'Sesión inválida. Recarga la página e intenta de nuevo.';
    $tipoMensaje = 'danger';
  } else {
    $nuevoUsername = trim((string)($_POST['username'] ?? ''));
    $nuevoPassword = (string)($_POST['password'] ?? '');
    $nuevoRole     = trim((string)($_POST['role'] ?? 'viewer'));

    // Validaciones básicas
    if ($nuevoUsername === '' || $nuevoPassword === '') {
      $mensaje = 'Usuario y contraseña son obligatorios.';
      $tipoMensaje = 'danger';
    } else {
      // Roles permitidos (según tu sistema nuevo)
      $rolesPermitidos = ['admin', 'editor', 'viewer', 'especialista', 'residente'];
      if (!in_array($nuevoRole, $rolesPermitidos, true)) {
        $nuevoRole = 'viewer';
      }

      try {
        // Verificar si ya existe username
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->execute([$nuevoUsername]);
        $exists = $chk->fetchColumn();

        if ($exists) {
          $mensaje = 'Ese usuario ya existe. Usa otro username.';
          $tipoMensaje = 'warning';
        } else {
          // Hash de contraseña
          $nuevoPasswordHasheado = password_hash($nuevoPassword, PASSWORD_DEFAULT);

          // Insert en tabla users (sistema nuevo)
          $ins = $pdo->prepare("
            INSERT INTO users (username, password, rol, nombre, apellido, created_at)
            VALUES (?, ?, ?, NULL, NULL, NOW())
          ");
          $ins->execute([$nuevoUsername, $nuevoPasswordHasheado, $nuevoRole]);

          $mensaje = 'Usuario creado exitosamente.';
          $tipoMensaje = 'success';
        }
      } catch (Throwable $e) {
        $mensaje = 'Error al crear usuario. Revisa la estructura de la tabla users.';
        $tipoMensaje = 'danger';
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Crear Usuario</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<?php
// Si tienes navbar global del sistema nuevo, úsalo:
$navbarPath = __DIR__ . '/../partials/navbar.php';
if (file_exists($navbarPath)) include $navbarPath;
?>

<div class="container mt-4" style="max-width: 720px;">
  <h2 class="text-center mb-4">Crear Usuario</h2>

  <?php if ($mensaje): ?>
    <div class="alert alert-<?= e($tipoMensaje) ?>"><?= e($mensaje) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form method="POST" action="">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

        <div class="mb-3">
          <label for="username" class="form-label">Usuario</label>
          <input type="text" class="form-control" id="username" name="username" required autocomplete="off">
        </div>

        <div class="mb-3">
          <label for="password" class="form-label">Contraseña</label>
          <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password">
        </div>

        <div class="mb-3">
          <label for="role" class="form-label">Rol</label>
          <select class="form-select" id="role" name="role">
            <option value="admin">Admin</option>
            <option value="editor">Editor</option>
            <option value="viewer" selected>Viewer</option>
            <option value="especialista">Especialista</option>
            <option value="residente">Residente</option>
          </select>
          <div class="form-text">Se guarda en el campo <b>rol</b> de la tabla <b>users</b>.</div>
        </div>

        <button type="submit" class="btn btn-primary w-100">Crear Usuario</button>

        <div class="d-flex justify-content-between mt-3">
          <a href="index.php" class="btn btn-outline-secondary">Regresar</a>
          <a href="<?= e($BASE) ?>/auth/logout.php" class="btn btn-outline-danger">Cerrar sesión</a>
        </div>

      </form>
    </div>
  </div>
</div>

</body>
</html>