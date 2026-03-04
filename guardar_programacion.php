<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_role(['admin']);
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';

$q = trim((string)($_GET['q'] ?? ''));

$params = [];
$sql = "SELECT id, username, rol, nombre, apellido, sello_path, created_at
        FROM users
        WHERE 1=1 ";

if ($q !== '') {
  $sql .= " AND (
      username LIKE ?
      OR nombre LIKE ?
      OR apellido LIKE ?
    ) ";
  $like = '%' . $q . '%';
  $params = [$like, $like, $like];
}

$sql .= " ORDER BY id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Usuarios | Evo/Prx</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="container py-4" style="max-width: 1100px;">
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
    <div>
      <h4 class="mb-0">Usuarios</h4>
      <div class="text-muted small">Administración de usuarios (solo admin)</div>
    </div>

    <div class="d-flex gap-2">
      <a class="btn btn-success" href="<?= e($BASE) ?>/users/create.php">+ Crear usuario</a>
    </div>
  </div>

  <?php include __DIR__ . '/../partials/flash.php'; ?>

  <form class="row g-2 mb-3" method="get">
    <div class="col-md-8">
      <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Buscar por usuario, nombre o apellido...">
    </div>
    <div class="col-md-4 d-flex gap-2">
      <button class="btn btn-outline-primary w-100">Buscar</button>
      <a class="btn btn-outline-secondary w-100" href="<?= e($BASE) ?>/users/list.php">Limpiar</a>
    </div>
  </form>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-hover mb-0 align-middle">
          <thead class="table-dark">
            <tr>
              <th>ID</th>
              <th>Usuario</th>
              <th>Nombre</th>
              <th>Rol</th>
              <th>Sello</th>
              <th>Creado</th>
              <th style="width:160px;">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="7" class="text-center text-muted py-4">Sin resultados.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php
                  $name = trim(($r['apellido'] ?? '') . ' ' . ($r['nombre'] ?? ''));
                  if ($name === '') $name = '—';
                ?>
                <tr>
                  <td><?= (int)$r['id'] ?></td>
                  <td><?= e($r['username'] ?? '') ?></td>
                  <td><?= e($name) ?></td>
                  <td><span class="badge text-bg-secondary"><?= e($r['rol'] ?? '') ?></span></td>
                  <td>
                    <?php if (!empty($r['sello_path'])): ?>
                      <span class="badge text-bg-success">Cargado</span>
                    <?php else: ?>
                      <span class="badge text-bg-warning">Falta</span>
                    <?php endif; ?>
                  </td>
                  <td class="small text-muted"><?= e($r['created_at'] ?? '') ?></td>
                  <td>
                    <div class="d-flex gap-2">
                      <a class="btn btn-sm btn-outline-primary" href="<?= e($BASE) ?>/users/edit.php?id=<?= (int)$r['id'] ?>">Editar</a>
                      <a class="btn btn-sm btn-outline-danger" href="<?= e($BASE) ?>/users/delete.php?id=<?= (int)$r['id'] ?>">Eliminar</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>
