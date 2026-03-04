<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';
$u = current_user();

$ok = null;
$err = null;

// Cargar sello actual desde DB (para mostrar)
$st = $pdo->prepare("SELECT sello_path FROM users WHERE id=? LIMIT 1");
$st->execute([$u['id']]);
$currentPath = (string)($st->fetchColumn() ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!isset($_FILES['sello']) || $_FILES['sello']['error'] !== UPLOAD_ERR_OK) {
    $err = 'Sube un archivo válido (PNG o JPG).';
  } else {
    $f = $_FILES['sello'];

    // Validación tamaño (3MB)
    if (($f['size'] ?? 0) > 3 * 1024 * 1024) {
      $err = 'El archivo es muy grande. Máximo 3MB.';
    } else {
      $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
        $err = 'Formato inválido. Usa PNG o JPG.';
      } else {
        // Normalizar extensión
        $ext = ($ext === 'jpeg') ? 'jpg' : $ext;

        // Crear carpeta si no existe
        $dirAbs = __DIR__ . '/../uploads/signatures';
        if (!is_dir($dirAbs)) {
          if (!mkdir($dirAbs, 0775, true)) {
            $err = 'No se pudo crear la carpeta de firmas.';
          }
        }

        if (!$err) {
          // Nombre único por usuario (se sobreescribe)
          $destRel = "uploads/signatures/user_{$u['id']}.{$ext}";
          $destAbs = __DIR__ . '/../' . $destRel;

          // (Opcional) borrar firma vieja si era otra extensión
          if ($currentPath && $currentPath !== $destRel) {
            $oldAbs = __DIR__ . '/../' . $currentPath;
            if (is_file($oldAbs)) @unlink($oldAbs);
          }

          if (!move_uploaded_file($f['tmp_name'], $destAbs)) {
            $err = 'No se pudo guardar el archivo. Revisa permisos de /uploads/signatures.';
          } else {
            // Guardar en DB
            $up = $pdo->prepare("UPDATE users SET sello_path=? WHERE id=?");
            $up->execute([$destRel, $u['id']]);

            // Actualizar sesión
            $_SESSION['user']['sello_path'] = $destRel;

            $currentPath = $destRel;
            $ok = 'Sello/rúbrica actualizado correctamente.';
          }
        }
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Mi sello | Evo/Prx</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="container py-4" style="max-width: 860px;">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="mb-0">Mi sello / rúbrica</h4>
      <div class="text-muted small">Este sello se imprimirá junto a tus evoluciones y prescripciones.</div>
    </div>
  </div>

  <?php include __DIR__ . '/../partials/flash.php'; ?>

  <?php if ($ok): ?>
    <div class="alert alert-success"><?= e($ok) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-md-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="fw-semibold mb-2">Sello actual</div>

          <?php if ($currentPath): ?>
            <div class="border rounded p-3 bg-white text-center">
              <img src="<?= e($BASE) ?>/<?= e($currentPath) ?>" alt="sello"
                   style="max-height: 170px; max-width: 100%;">
            </div>
            <div class="text-muted small mt-2">
              Recomendado: PNG con fondo transparente.
            </div>
          <?php else: ?>
            <div class="alert alert-warning mb-0">
              Aún no has subido tu sello/rúbrica.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <div class="fw-semibold mb-2">Subir / actualizar</div>

          <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
              <label class="form-label">Archivo (PNG/JPG) - máx 3MB</label>
              <input type="file" class="form-control" name="sello" accept="image/png,image/jpeg" required>
              <div class="form-text">
                Tip: si es rúbrica, usa PNG transparente para que se vea más limpio al imprimir.
              </div>
            </div>

            <button class="btn btn-primary">Guardar sello</button>
          </form>

          <hr>

          <div class="text-muted small">
            <b>Nota:</b> Si en impresión no se ve, revisa que tu servidor permita mostrar imágenes desde
            <code>/uploads/signatures</code> y que los permisos sean correctos (775).
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>