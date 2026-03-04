<?php
// clinical/encounter.php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';
$u = current_user();

$patient_id   = (int)($_GET['patient_id'] ?? 0);
$evolution_id = (int)($_GET['evolution_id'] ?? 0); // si viene: modo EDIT

if ($patient_id <= 0) {
  flash_set('error', 'Paciente inválido.');
  redirect($BASE . '/patients/list.php');
}

/* =========================
   Cargar paciente
========================= */
$stP = $pdo->prepare("SELECT * FROM patients WHERE id=? LIMIT 1");
$stP->execute([$patient_id]);
$patient = $stP->fetch();

if (!$patient) {
  flash_set('error', 'Paciente no encontrado.');
  redirect($BASE . '/patients/list.php');
}

$patientName = trim(($patient['apellidos'] ?? '') . ' ' . ($patient['nombres'] ?? ''));

/* =========================
   Permisos de acceso al paciente
========================= */
$allowed = false;
if (($u['rol'] ?? '') === 'admin') {
  $allowed = true;
} elseif (($u['rol'] ?? '') === 'especialista') {
  $chk = $pdo->prepare("SELECT 1 FROM patient_doctors WHERE patient_id=? AND doctor_user_id=? LIMIT 1");
  $chk->execute([$patient_id, (int)($u['id'] ?? 0)]);
  $allowed = (bool)$chk->fetchColumn();
} else { // residente
  $chk = $pdo->prepare("SELECT 1 FROM resident_patients WHERE patient_id=? AND resident_user_id=? LIMIT 1");
  $chk->execute([$patient_id, (int)($u['id'] ?? 0)]);
  $allowed = (bool)$chk->fetchColumn();
}

if (!$allowed) {
  flash_set('error', 'No tienes acceso a este paciente.');
  redirect($BASE . '/patients/list.php');
}

/* =========================
   Modo crear/editar
========================= */
$isEdit = $evolution_id > 0;

$evo = null;
$prx = null;

$evolucion_txt = '';
$prescripcion_txt = '';

if ($isEdit) {
  // Cargar evolución
  $stE = $pdo->prepare("SELECT * FROM evolutions WHERE id=? AND patient_id=? LIMIT 1");
  $stE->execute([$evolution_id, $patient_id]);
  $evo = $stE->fetch();

  if (!$evo) {
    flash_set('error', 'Evolución no encontrada para este paciente.');
    redirect($BASE . '/clinical/encounter.php?patient_id=' . $patient_id);
  }

  // Cargar prescripción asociada (si existe)
  $stR = $pdo->prepare("SELECT * FROM prescriptions WHERE evolution_id=? AND patient_id=? LIMIT 1");
  $stR->execute([$evolution_id, $patient_id]);
  $prx = $stR->fetch();

  $evolucion_txt = (string)$evo['contenido'];
  $prescripcion_txt = (string)($prx['contenido'] ?? '');

  // Permiso de edición: admin o autor de la evolución (y si hay prescripción, autor o admin)
  $canEdit = (($u['rol'] ?? '') === 'admin') || ((int)$evo['author_user_id'] === (int)($u['id'] ?? 0));
  if ($canEdit && $prx && (($u['rol'] ?? '') !== 'admin')) {
    if ((int)$prx['author_user_id'] !== (int)($u['id'] ?? 0)) $canEdit = false;
  }

  if (!$canEdit) {
    flash_set('error', 'No tienes permiso para editar esta evolución/prescripción.');
    redirect($BASE . '/clinical/encounter.php?patient_id=' . $patient_id);
  }
}

$err = null;

/* =========================
   Guardar (crear o editar)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = (string)($_POST['_csrf'] ?? '');
  if (!csrf_check($token)) {
    $err = 'Sesión inválida. Recarga la página e intenta de nuevo.';
  } else {
    $evo_new = trim((string)($_POST['evolucion'] ?? ''));
    $prx_new = trim((string)($_POST['prescripcion'] ?? ''));

    // Regla: no hay prescripción sin evolución (y aquí: ambas obligatorias)
    if ($evo_new === '') {
      $err = 'La evolución es obligatoria.';
    } elseif ($prx_new === '') {
      $err = 'La prescripción es obligatoria.';
    } else {
      $ip = function_exists('client_ip') ? client_ip() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
      $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

      try {
        $pdo->beginTransaction();

        if (!$isEdit) {
          // Crear evolución
          $insE = $pdo->prepare("
            INSERT INTO evolutions (patient_id, author_user_id, contenido, created_at)
            VALUES (?, ?, ?, NOW())
          ");
          $insE->execute([$patient_id, (int)($u['id'] ?? 0), $evo_new]);
          $newEvolutionId = (int)$pdo->lastInsertId();

          // Crear prescripción asociada
          $insR = $pdo->prepare("
            INSERT INTO prescriptions (patient_id, author_user_id, evolution_id, contenido, created_at)
            VALUES (?, ?, ?, ?, NOW())
          ");
          $insR->execute([$patient_id, (int)($u['id'] ?? 0), $newEvolutionId, $prx_new]);

          $pdo->commit();

          flash_set('success', 'Evolución + Prescripción creadas.');
          redirect($BASE . '/clinical/encounter.php?patient_id=' . $patient_id);

        } else {
          // Editar con auditoría
          $oldE = (string)$evo['contenido'];
          $oldR = (string)($prx['contenido'] ?? '');

          // Evolución
          if ($evo_new !== $oldE) {
            $log = $pdo->prepare("
              INSERT INTO edit_logs (entity_type, entity_id, patient_id, edited_by, edited_at, ip, user_agent, old_content, new_content)
              VALUES ('evolution', ?, ?, ?, NOW(), ?, ?, ?, ?)
            ");
            $log->execute([$evolution_id, $patient_id, (int)($u['id'] ?? 0), $ip, $ua, $oldE, $evo_new]);

            $upE = $pdo->prepare("
              UPDATE evolutions
              SET contenido=?, updated_at=NOW(), updated_by=?
              WHERE id=? AND patient_id=?
              LIMIT 1
            ");
            $upE->execute([$evo_new, (int)($u['id'] ?? 0), $evolution_id, $patient_id]);
          }

          // Prescripción (si no existía, la creamos; si existía, audit + update)
          if ($prx) {
            $presc_id = (int)$prx['id'];
            if ($prx_new !== $oldR) {
              $log = $pdo->prepare("
                INSERT INTO edit_logs (entity_type, entity_id, patient_id, edited_by, edited_at, ip, user_agent, old_content, new_content)
                VALUES ('prescription', ?, ?, ?, NOW(), ?, ?, ?, ?)
              ");
              $log->execute([$presc_id, $patient_id, (int)($u['id'] ?? 0), $ip, $ua, $oldR, $prx_new]);

              $upR = $pdo->prepare("
                UPDATE prescriptions
                SET contenido=?, updated_at=NOW(), updated_by=?
                WHERE id=? AND patient_id=?
                LIMIT 1
              ");
              $upR->execute([$prx_new, (int)($u['id'] ?? 0), $presc_id, $patient_id]);
            }
          } else {
            $insR = $pdo->prepare("
              INSERT INTO prescriptions (patient_id, author_user_id, evolution_id, contenido, created_at)
              VALUES (?, ?, ?, ?, NOW())
            ");
            $insR->execute([$patient_id, (int)($u['id'] ?? 0), $evolution_id, $prx_new]);
          }

          $pdo->commit();

          flash_set('success', 'Cambios guardados. Auditoría registrada.');
          redirect($BASE . '/clinical/encounter.php?patient_id=' . $patient_id);
        }

      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = 'Error al guardar. Revisa base de datos.';
      }

      $evolucion_txt = $evo_new;
      $prescripcion_txt = $prx_new;
    }
  }
}

/* =========================
   Listado de evoluciones (encounters)
========================= */
$stList = $pdo->prepare("
  SELECT
    e.id AS evolution_id,
    e.created_at AS evo_created_at,
    e.updated_at AS evo_updated_at,
    e.author_user_id,
    e.contenido AS evo_text,
    au.username AS a_username,
    au.nombre AS a_nombre,
    au.apellido AS a_apellido,
    au.sello_path AS a_sello,

    pr.id AS prescription_id,
    pr.contenido AS prx_text,
    pr.author_user_id AS prx_author_id,
    pr.updated_at AS prx_updated_at,
    pu.username AS p_username,
    pu.nombre AS p_nombre,
    pu.apellido AS p_apellido,
    pu.sello_path AS p_sello

  FROM evolutions e
  INNER JOIN users au ON au.id = e.author_user_id
  LEFT JOIN prescriptions pr ON pr.evolution_id = e.id AND pr.patient_id = e.patient_id
  LEFT JOIN users pu ON pu.id = pr.author_user_id
  WHERE e.patient_id = ?
  ORDER BY e.created_at DESC, e.id DESC
  LIMIT 200
");
$stList->execute([$patient_id]);
$encounters = $stList->fetchAll();

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Encounter | <?= e($patientName) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .split { display:flex; gap:16px; }
    .split > div { flex:1; }
    @media (max-width: 991px) { .split { flex-direction:column; } }

    /* Historial: respetar saltos de línea SIN afectar print */
    .note-box{
      white-space: pre-wrap;     /* respeta \n */
      overflow-wrap: anywhere;   /* corta palabras largas */
      word-break: break-word;
      max-height: 260px;
      overflow: auto;
      padding: .5rem;
      border: 1px solid #e5e5e5;
      border-radius: .5rem;
      background: #f8f9fa;
      margin: 0;
    }
  </style>
</head>
<body>
<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="container py-4" style="max-width: 1200px;">
  <?php include __DIR__ . '/../partials/flash.php'; ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h5 class="mb-0">Evolución + Prescripción</h5>
      <div class="text-muted small">Paciente: <b><?= e($patientName) ?></b></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="<?= e($BASE) ?>/patients/view.php?id=<?= (int)$patient_id ?>">Volver a ficha</a>
      <a class="btn btn-outline-dark" href="<?= e($BASE) ?>/patient_print.php?id=<?= (int)$patient_id ?>" target="_blank">Imprimir</a>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
  <?php endif; ?>

  <!-- FORM -->
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-semibold">
          <?= $isEdit ? 'Editando encounter' : 'Nuevo encounter' ?>
          <?php if ($isEdit): ?>
            <span class="badge text-bg-primary ms-2">Evolución #<?= (int)$evolution_id ?></span>
          <?php endif; ?>
        </div>

        <?php if ($isEdit): ?>
          <a class="btn btn-sm btn-outline-secondary"
             href="<?= e($BASE) ?>/clinical/encounter.php?patient_id=<?= (int)$patient_id ?>">
            Cancelar edición
          </a>
        <?php endif; ?>
      </div>

      <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

        <div class="split">
          <div>
            <label class="form-label fw-semibold">Evolución (obligatoria)</label>
            <textarea class="form-control" name="evolucion" rows="12" required><?= e($evolucion_txt) ?></textarea>
          </div>

          <div>
            <label class="form-label fw-semibold">Prescripción / Indicaciones (obligatoria)</label>
            <textarea class="form-control" name="prescripcion" rows="12" required><?= e($prescripcion_txt) ?></textarea>
          </div>
        </div>

        <div class="d-flex gap-2 mt-3">
          <button class="btn btn-success"><?= $isEdit ? 'Guardar cambios' : 'Guardar' ?></button>
        </div>

        <div class="text-muted small mt-2">
          * Regla: no existe prescripción sin evolución. Cada edición guarda auditoría (solo admin).
        </div>
      </form>
    </div>
  </div>

  <!-- LISTADO -->
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="fw-semibold">Historial de encounters</div>
    <div class="text-muted small">Últimos 200</div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-hover mb-0 align-middle">
          <thead class="table-dark">
            <tr>
              <th style="width:170px;">Fecha</th>
              <th>Evolución</th>
              <th>Prescripción</th>
              <th style="width:170px;">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$encounters): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">Aún no hay registros.</td></tr>
          <?php else: ?>
            <?php foreach ($encounters as $r): ?>
              <?php
                $evoId = (int)$r['evolution_id'];
                $isAuthor = ((int)$r['author_user_id'] === (int)($u['id'] ?? 0));
                $canEditRow = (($u['rol'] ?? '') === 'admin') || $isAuthor;

                $evoAuthor = trim(($r['a_apellido'] ?? '').' '.($r['a_nombre'] ?? ''));
                if ($evoAuthor === '') $evoAuthor = (string)($r['a_username'] ?? '');

                $prxAuthor = trim(($r['p_apellido'] ?? '').' '.($r['p_nombre'] ?? ''));
                if ($prxAuthor === '') $prxAuthor = (string)($r['p_username'] ?? '');

                $dt = (string)($r['evo_created_at'] ?? '');
              ?>
              <tr>
                <td class="small">
                  <div><b><?= e($dt) ?></b></div>
                  <?php if (!empty($r['evo_updated_at']) || !empty($r['prx_updated_at'])): ?>
                    <div class="text-muted">Actualizado</div>
                  <?php endif; ?>
                </td>

                <td>
                  <div class="small text-muted mb-1">Por: <b><?= e($evoAuthor) ?></b></div>
                  <pre class="note-box"><?= e((string)$r['evo_text']) ?></pre>
                  <?php if (!empty($r['a_sello'])): ?>
                    <div class="mt-2"><span class="badge text-bg-success">Sello</span></div>
                  <?php endif; ?>
                </td>

                <td>
                  <?php if (!empty($r['prx_text'])): ?>
                    <div class="small text-muted mb-1">Por: <b><?= e($prxAuthor) ?></b></div>
                    <pre class="note-box"><?= e((string)$r['prx_text']) ?></pre>
                    <?php if (!empty($r['p_sello'])): ?>
                      <div class="mt-2"><span class="badge text-bg-success">Sello</span></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted">Sin prescripción asociada</span>
                  <?php endif; ?>
                </td>

                <td>
                  <div class="d-flex flex-column gap-2">
                    <?php if ($canEditRow): ?>
                      <a class="btn btn-sm btn-outline-primary"
                         href="<?= e($BASE) ?>/clinical/encounter.php?patient_id=<?= (int)$patient_id ?>&evolution_id=<?= $evoId ?>">
                        Editar
                      </a>
                    <?php else: ?>
                      <button class="btn btn-sm btn-outline-secondary" disabled>Editar</button>
                    <?php endif; ?>

                    <a class="btn btn-sm btn-outline-dark"
                       href="<?= e($BASE) ?>/patient_print.php?id=<?= (int)$patient_id ?>"
                       target="_blank">
                      Imprimir
                    </a>
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