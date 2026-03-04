<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';
$u = current_user();

$patient_id = (int)($_GET['patient_id'] ?? 0);
if ($patient_id <= 0) {
  flash_set('error', 'Paciente inválido.');
  redirect($BASE . '/patients/list.php');
}

// ====== Cargar paciente ======
$stP = $pdo->prepare("SELECT id, hc, cedula, nombres, apellidos FROM patients WHERE id=? LIMIT 1");
$stP->execute([$patient_id]);
$p = $stP->fetch();

if (!$p) {
  flash_set('error', 'Paciente no encontrado.');
  redirect($BASE . '/patients/list.php');
}

$patientName = trim(($p['apellidos'] ?? '') . ' ' . ($p['nombres'] ?? ''));

// ====== Permisos (admin, tratante, o residente asignado) ======
if ($u['rol'] !== 'admin') {
  if ($u['rol'] === 'especialista') {
    $chk = $pdo->prepare("SELECT 1 FROM patient_doctors WHERE patient_id=? AND doctor_user_id=? LIMIT 1");
    $chk->execute([$patient_id, $u['id']]);
    if (!$chk->fetchColumn()) {
      flash_set('error', 'No tienes acceso a este paciente.');
      redirect($BASE . '/patients/list.php');
    }
  } else { // residente
    $chk = $pdo->prepare("SELECT 1 FROM resident_patients WHERE patient_id=? AND resident_user_id=? LIMIT 1");
    $chk->execute([$patient_id, $u['id']]);
    if (!$chk->fetchColumn()) {
      flash_set('error', 'No tienes acceso a este paciente.');
      redirect($BASE . '/patients/list.php');
    }
  }
}

// ====== Tratante (solo para mostrar) ======
$docName = '—';
$stD = $pdo->prepare("
  SELECT u.nombre, u.apellido
  FROM patient_doctors pd
  INNER JOIN users u ON u.id = pd.doctor_user_id
  WHERE pd.patient_id = ?
  LIMIT 1
");
$stD->execute([$patient_id]);
$d = $stD->fetch();
if ($d) $docName = trim($d['apellido'].' '.$d['nombre']);

// ====== Listar prescripciones (histórico) ======
$st = $pdo->prepare("
  SELECT p.id, p.contenido, p.created_at, p.evolution_id,
         u.nombre, u.apellido, u.rol, u.sello_path
  FROM prescriptions p
  INNER JOIN users u ON u.id = p.author_user_id
  WHERE p.patient_id = ?
  ORDER BY p.created_at DESC, p.id DESC
");
$st->execute([$patient_id]);
$rows = $st->fetchAll();
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Prescripciones | Evo/Prx</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="container py-4" style="max-width: 1100px;">
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
    <div>
      <h4 class="mb-0">Prescripciones (Histórico)</h4>
      <div class="text-muted small">
        Paciente: <b><?= e($patientName) ?></b>
        <?php if (!empty($p['hc'])): ?> · HC: <b><?= e($p['hc']) ?></b><?php endif; ?>
        <?php if (!empty($p['cedula'])): ?> · Cédula: <b><?= e($p['cedula']) ?></b><?php endif; ?>
        · Tratante: <b><?= e($docName) ?></b>
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-outline-secondary" href="<?= e($BASE) ?>/patients/view.php?id=<?= (int)$patient_id ?>">Ver paciente</a>
      <a class="btn btn-success" href="<?= e($BASE) ?>/clinical/encounter.php?patient_id=<?= (int)$patient_id ?>">
        + Evolución + Prescripción
      </a>
      <a class="btn btn-outline-primary" href="<?= e($BASE) ?>/print/patient_sheet.php?id=<?= (int)$patient_id ?>">Hoja</a>
      <a class="btn btn-outline-dark" target="_blank" href="<?= e($BASE) ?>/print/patient_print.php?id=<?= (int)$patient_id ?>">Imprimir</a>
    </div>
  </div>

  <?php include __DIR__ . '/../partials/flash.php'; ?>

  <div class="alert alert-info">
    Regla: <b>no puede existir prescripción sin evolución</b>. Para registrar una nueva, usa:
    <b>“Evolución + Prescripción”</b>.
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="fw-semibold mb-2">Histórico de prescripciones</div>

      <?php if (!$rows): ?>
        <div class="text-muted">Aún no hay prescripciones registradas.</div>
      <?php else: ?>
        <div class="list-group">
          <?php foreach ($rows as $r): ?>
            <?php
              $author = trim(($r['apellido'] ?? '').' '.($r['nombre'] ?? ''));
              $dt = (string)$r['created_at'];
              $hasSeal = !empty($r['sello_path']);
              $evId = (int)($r['evolution_id'] ?? 0);
            ?>
            <div class="list-group-item">
              <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                  <div class="fw-semibold">
                    <?= e($author) ?>
                    <span class="badge text-bg-light border ms-2"><?= e($r['rol'] ?? '') ?></span>
                    <?php if ($hasSeal): ?>
                      <span class="badge text-bg-success ms-1">Sello</span>
                    <?php else: ?>
                      <span class="badge text-bg-warning ms-1">Sin sello</span>
                    <?php endif; ?>
                  </div>
                  <div class="text-muted small">
                    <?= e($dt) ?>
                    <?php if ($evId > 0): ?>
                      · Asociada a Evolución ID: <b><?= $evId ?></b>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if ($u['rol'] === 'admin'): ?>
                  <a class="btn btn-sm btn-outline-danger"
                     href="<?= e($BASE) ?>/clinical/prescription_delete.php?id=<?= (int)$r['id'] ?>&patient_id=<?= (int)$patient_id ?>">
                    Eliminar
                  </a>
                <?php endif; ?>
              </div>

              <div class="mt-2" style="white-space: pre-wrap;"><?= e($r['contenido']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/footer.php'; ?>
</body>
</html>
