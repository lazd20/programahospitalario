<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_role(['admin']);
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';
$me = current_user();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  flash_set('error', 'ID inválido.');
  redirect($BASE . '/users/list.php');
}

if ($me && (int)$me['id'] === $id) {
  flash_set('error', 'No puedes eliminar tu propio usuario.');
  redirect($BASE . '/users/list.php');
}

// Cargar usuario
$st = $pdo->prepare("SELECT id, username, nombre, apellido, rol, sello_path FROM users WHERE id=? LIMIT 1");
$st->execute([$id]);
$user = $st->fetch();

if (!$user) {
  flash_set('error', 'Usuario no encontrado.');
  redirect($BASE . '/users/list.php');
}

$err = null;

// Verificar si tiene registros clínicos
$stEv = $pdo->prepare("SELECT COUNT(*) FROM evolutions WHERE author_user_id=?");
$stEv->execute([$id]);
$cntEv = (int)$stEv->fetchColumn();

$stPr = $pdo->prepare("SELECT COUNT(*) FROM prescriptions WHERE author_user_id=?");
$stPr->execute([$id]);
$cntPr = (int)$stPr->fetchColumn();

$hasClinical = ($cntEv > 0 || $cntPr > 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Confirmación
  $confirm = trim((string)($_POST['confirm'] ?? ''));
  if ($confirm !== 'ELIMINAR') {
    $err = 'Escribe ELIMINAR para confirmar.';
  } elseif ($hasClinical) {
    $err = 'No se puede eliminar: este usuario ya tiene evoluciones/prescripciones registradas.';
  } else {
    try {
      // (Opcional) quitar asignaciones primero (no es obligatorio, pero deja limpio)
      $pdo->prepare("DELETE FROM resident_patients WHERE resident_user_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM patient_doctors WHERE doctor_user_id=?")->execute([$id]);

      // Eliminar usuario
      $del = $pdo->prepare("DELETE FROM users WHERE id=?");
      $del->execute([$id]);

      flash_set('success', 'Usuario eliminado correctamente.');
      redirect($BASE . '/users/list.php');
    } catch (Throwable $e) {
      $err = 'No se pudo eliminar. Revisa restricciones o dependencias.';
    }
  }
}

function full_name($u) {
  return trim(($u['apellido'] ?? '') . ' ' . ($u['nombre'] ?? ''));
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Eliminar usuario | Evo/Prx</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="container py-4" style="max-width: 820px;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Eliminar usuario</h4>
    <a class="btn btn-outline-secondary" href="<?= e($BASE) ?>/users/list.php">Volver</a>
  </div>

  <?php include __DIR__ . '/../partials/flash.php'; ?>

  <?php if ($err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="mb-2">
        Estás a punto de eliminar a:
      </div>

      <div class="p-3 border rounded bg-light">
        <div class="fw-semibold"><?= e(full_name($user)) ?></div>
        <div class="text-muted small">
          Usuario: <?= e($user['username']) ?> · Rol: <?= e($user['rol']) ?> · ID #<?= (int)$user['id'] ?>
        </div>
        <div class="mt-2">
          Sello:
          <?php if (!empty($user['sello_path'])): ?>
            <span class="badge text-bg-success">Sí</span>
          <?php else: ?>
            <span class="badge text-bg-warning">No</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="mt-3">
        <div class="text-muted small">
          Evoluciones registradas: <b><?= (int)$cntEv ?></b> · Prescripciones registradas: <b><?= (int)$cntPr ?></b>
        </div>
      </div>

      <?php if ($hasClinical): ?>
        <div class="alert alert-warning mt-3 mb-0">
          No se puede eliminar porque ya tiene registros clínicos. (Recomendado: desactivar o cambiar rol, pero no borrar.)
        </div>
      <?php else: ?>
        <hr class="my-4">
        <form method="post">
          <div class="mb-2 fw-semibold text-danger">Confirmación</div>
          <p class="text-muted small mb-2">
            Escribe <b>ELIMINAR</b> para confirmar.
          </p>
          <input class="form-control" name="confirm" placeholder="ELIMINAR" required>

          <div class="mt-3 d-flex gap-2">
            <button class="btn btn-danger">Eliminar definitivamente</button>
            <a class="btn btn-outline-secondary" href="<?= e($BASE) ?>/users/list.php">Cancelar</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>