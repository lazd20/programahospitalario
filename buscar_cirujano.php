<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_role(['admin']);
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';

$err = null;

$values = [
  'username' => '',
  'nombre' => '',
  'apellido' => '',
  'rol' => 'residente'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $values['username'] = strtolower(trim((string)($_POST['username'] ?? '')));
  $values['nombre']   = trim((string)($_POST['nombre'] ?? ''));
  $values['apellido'] = trim((string)($_POST['apellido'] ?? ''));
  $values['rol']      = trim((string)($_POST['rol'] ?? 'residente'));
  $password           = (string)($_POST['password'] ?? '');
  $password2          = (string)($_POST['password2'] ?? '');

  // Validaciones
  if ($values['username'] === '' || $values['nombre'] === '' || $values['apellido'] === '') {
    $err = 'Usuario, nombre y apellido son obligatorios.';
  } elseif (!preg_match('/^[a-z0-9._-]{3,50}$/', $values['username'])) {
    $err = 'El usuario debe tener 3-50 caracteres y solo puede incluir letras/números y . _ -';
  } elseif (!in_array($values['rol'], ['admin','residente','especialista'], true)) {
    $err = 'Rol inválido.';
  } elseif (strlen($password) < 6) {
    $err = 'La contraseña debe tener al menos 6 caracteres.';
  } elseif ($password !== $password2) {
    $err = 'Las contraseñas no coinciden.';
  } else {
    // Username único
    $st = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $st->execute([$values['username']]);
    if ($st->fetchColumn()) {
      $err = 'Ese usuario ya existe.';
    } else {
      $hash = password_hash($password, PASSWORD_BCRYPT);

      $ins = $pdo->prepare("
        INSERT INTO users (username, password_hash, nombre, apellido, rol)
        VALUES (?, ?, ?, ?, ?)
      ");
      $ins->execute([
        $values['username'],
        $hash,
        $values['nombre'],
        $values['apellido'],
        $values['rol']
      ]);

      flash_set('success', 'Usuario creado correctamente.');
      redirect($BASE . '/users/list.php');
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Nuevo usuario | Evo/Prx</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="container py-4" style="max-width: 900px;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Crear usuario (médico)</h4>
    <a class="btn btn-outline-secondary" href="<?= e($BASE) ?>/users/list.php">Volver</a>
  </div>

  <?php include __DIR__ . '/../partials/flash.php'; ?>

  <?php if ($err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
  <?php endif; ?>

  <form method="post" class="card shadow-sm">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Usuario</label>
          <input class="form-control" name="username" required value="<?= e($values['username']) ?>" placeholder="ej: dr.perez">
          <div class="form-text">Solo: letras/números y . _ -</div>
        </div>

        <div class="col-md-4">
          <label class="form-label">Nombre</label>
          <input class="form-control" name="nombre" required value="<?= e($values['nombre']) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Apellido</label>
          <input class="form-control" name="apellido" required value="<?= e($values['apellido']) ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label">Rol</label>
          <select class="form-select" name="rol" required>
            <option value="residente"   <?= $values['rol']==='residente'?'selected':'' ?>>Residente</option>
            <option value="especialista"<?= $values['rol']==='especialista'?'selected':'' ?>>Especialista</option>
            <option value="admin"       <?= $values['rol']==='admin'?'selected':'' ?>>Admin</option>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Contraseña</label>
          <input type="password" class="form-control" name="password" required>
        </div>

        <div class="col-md-4">
          <label class="form-label">Repetir contraseña</label>
          <input type="password" class="form-control" name="password2" required>
        </div>

        <div class="col-12">
          <div class="alert alert-info mb-0">
            El sello/rúbrica se carga desde <b>“Mi sello”</b> cuando el médico inicia sesión.
          </div>
        </div>
      </div>
    </div>

    <div class="card-footer d-flex gap-2">
      <button class="btn btn-primary">Guardar</button>
      <a class="btn btn-outline-secondary" href="<?= e($BASE) ?>/users/list.php">Cancelar</a>
    </div>
  </form>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>