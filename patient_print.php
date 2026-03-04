<?php
// print/patient_sheet.php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';
$u = current_user();

$patient_id = (int)($_GET['id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));

if ($patient_id <= 0) {
  flash_set('error', 'Paciente inválido.');
  redirect($BASE . '/patients/list.php');
}

// ====== Cargar paciente ======
$stP = $pdo->prepare("SELECT * FROM patients WHERE id=? LIMIT 1");
$stP->execute([$patient_id]);
$p = $stP->fetch();

if (!$p) {
  flash_set('error', 'Paciente no encontrado.');
  redirect($BASE . '/patients/list.php');
}

// ====== Permisos (admin, tratante, o residente asignado) ======
if (($u['rol'] ?? '') !== 'admin') {
  if (($u['rol'] ?? '') === 'especialista') {
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

// ====== Utilidades ======
function age_from_date($ymd) {
  if (!$ymd) return '';
  try {
    $dob = new DateTime($ymd);
    $now = new DateTime();
    return (string)$dob->diff($now)->y;
  } catch (Throwable $e) {
    return '';
  }
}

$fullName = trim(($p['apellidos'] ?? '') . ' ' . ($p['nombres'] ?? ''));
$establecimiento = (string)($p['establecimiento'] ?? '');
$sexo = (string)($p['sexo'] ?? '');
$edad = age_from_date($p['fecha_nac'] ?? null);
$hc = (string)($p['hc'] ?? '');

$rowsPerPage = 28;
$offset = ($page - 1) * $rowsPerPage;

function fmt_date($dt) {
  if (!$dt) return '';
  try { return (new DateTime($dt))->format('d/m/Y'); } catch(Throwable $e) { return ''; }
}
function fmt_time($dt) {
  if (!$dt) return '';
  try { return (new DateTime($dt))->format('H:i'); } catch(Throwable $e) { return ''; }
}

// ====== Total de páginas (X de Y) ======
$cntE = (int)$pdo->prepare("SELECT COUNT(*) FROM evolutions WHERE patient_id=?")
                 ->execute([$patient_id]); // <-- PDO execute returns bool; hacemos bien abajo
$stCntE = $pdo->prepare("SELECT COUNT(*) FROM evolutions WHERE patient_id=?");
$stCntE->execute([$patient_id]);
$totalE = (int)$stCntE->fetchColumn();

$stCntP = $pdo->prepare("SELECT COUNT(*) FROM prescriptions WHERE patient_id=?");
$stCntP->execute([$patient_id]);
$totalP = (int)$stCntP->fetchColumn();

$totalPagesE = max(1, (int)ceil($totalE / $rowsPerPage));
$totalPagesP = max(1, (int)ceil($totalP / $rowsPerPage));
$totalPages  = max($totalPagesE, $totalPagesP);
if ($page > $totalPages) $page = $totalPages; // por si llega un page grande

$offset = ($page - 1) * $rowsPerPage;

// ====== Evoluciones (paginadas) ======
$stE = $pdo->prepare("
  SELECT e.id, e.contenido, e.created_at,
         u.nombre, u.apellido, u.sello_path, u.rol
  FROM evolutions e
  INNER JOIN users u ON u.id = e.author_user_id
  WHERE e.patient_id = ?
  ORDER BY e.created_at ASC, e.id ASC
  LIMIT $rowsPerPage OFFSET $offset
");
$stE->execute([$patient_id]);
$evols = $stE->fetchAll();

// ====== Prescripciones (paginadas) ======
$stR = $pdo->prepare("
  SELECT p.id, p.contenido, p.created_at, p.evolution_id,
         u.nombre, u.apellido, u.sello_path, u.rol
  FROM prescriptions p
  INNER JOIN users u ON u.id = p.author_user_id
  WHERE p.patient_id = ?
  ORDER BY p.created_at ASC, p.id ASC
  LIMIT $rowsPerPage OFFSET $offset
");
$stR->execute([$patient_id]);
$prescs = $stR->fetchAll();

// Logo (visible también en impresión)
$logoUrl = 'https://realmedic.com.ec/pqx/uploads/logo.png';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Hoja Evo/Prx | <?= e($fullName) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    /* ===== Layout de hoja ===== */
    @page { margin: 10mm; }
    .sheet-wrap{ max-width: 980px; margin: 0 auto; }
    .sheet{ background:#fff; border:1px solid #111; padding:0; }

    /* Header con logo (SI imprime) */
   .print-header{
  display:flex;
  flex-direction:column;
  justify-content:center;
  align-items:center;
  gap:6px;                 /* esto reemplaza el <br> */
  padding:10px 0 6px;
  border-bottom:1px solid #111;
}
.print-title{
  font-weight:800;
  font-size:14px;
  letter-spacing:.5px;
  text-transform:uppercase;
}
    .print-header img{
      max-height:70px;
      max-width:260px;
      object-fit:contain;
      display:block;
    }

    .top-grid{
      width:100%;
      border-collapse:collapse;
      table-layout:fixed;
      font-size:11px;
    }
    .top-grid td, .top-grid th{
      border:1px solid #111;
      padding:3px 6px;
      vertical-align:middle;
    }
    .top-grid th{
      background:#c6efce; /* verde suave */
      font-weight:700;
      text-transform:uppercase;
      font-size:10px;
    }

    .cols{
      display:flex;
      gap:0;
      border-top:1px solid #111;
    }
    .colbox{
      width:50%;
      border-right:1px solid #111;
    }
    .colbox:last-child{ border-right:0; }

    .section-title{
      background:#d9e1f2; /* azul/gris suave como el formato */
      border-bottom:1px solid #111;
      font-weight:800;
      padding:5px 8px;
      font-size:12px;
      text-transform:uppercase;
      display:flex;
      align-items:center;
      gap:8px;
    }
    .section-title .n{
      background:#111;
      color:#fff;
      padding:2px 6px;
      border-radius:2px;
      font-size:11px;
      font-weight:800;
    }

    .grid{
      width:100%;
      border-collapse:collapse;
      table-layout:fixed;
      font-size:11px;
    }
    .grid th, .grid td{
      border:1px solid #111;
      padding:3px 5px;
      vertical-align:top;
    }
    .grid thead th{
      background:#c6efce;
      font-weight:800;
      text-transform:uppercase;
      font-size:10px;
    }

    /* filas tipo “cuaderno” */
    .rowline td{
      min-height:18px;
      overflow:hidden;
    }

    /* Saltos de línea OK (sin scroll) */
    .cell-note{
      white-space: pre-wrap;
      overflow-wrap: anywhere;
      word-break: break-word;
      line-height: 1.12;
      max-height: 360px;  /* ajusta si quieres */
      overflow: hidden;   /* NO scroll */
    }

    .sig{
      display:flex;
      flex-direction:column;
      align-items:flex-start;
      gap:2px;
    }
    .sig img{
      max-height:28px;
      max-width:100%;
      object-fit:contain;
    }
    .sig .name{
      font-size:10px;
      line-height:1.05;
      font-weight:700;
    }
    .sig .role{
      font-size:9px;
      color:#444;
    }

    .foot{
      display:flex;
      justify-content:space-between;
      font-size:10px;
      padding:6px 8px;
      border-top:1px solid #111;
    }

    /* la columna hora no se rompe */
    .grid td:nth-child(2), .grid th:nth-child(2){
      white-space: nowrap;
    }

    /* ===== Print ===== */
    @media print{
      .no-print{ display:none !important; }
      body{ background:#fff !important; }
      .sheet-wrap{ max-width:none; }
      .sheet{ border:0; }
      @page { size: A4 portrait; margin: 10mm; }
    }
  </style>
</head>
<body>

<?php include __DIR__ . '/../partials/navbar.php'; ?>

<div class="container py-3 no-print">
  <?php include __DIR__ . '/../partials/flash.php'; ?>

  <div class="d-flex flex-wrap gap-2 align-items-center">
    <a class="btn btn-outline-secondary" href="<?= e($BASE) ?>/patients/view.php?id=<?= (int)$patient_id ?>">Volver</a>
    <a class="btn btn-success" href="<?= e($BASE) ?>/clinical/encounter.php?patient_id=<?= (int)$patient_id ?>">
      + Evolución + Prescripción
    </a>

    <button class="btn btn-dark" onclick="window.print()">Imprimir</button>

    <div class="ms-auto d-flex gap-2">
      <?php if ($page > 1): ?>
        <a class="btn btn-outline-primary" href="<?= e($BASE) ?>/print/patient_sheet.php?id=<?= (int)$patient_id ?>&page=<?= (int)($page-1) ?>">◀ Hoja <?= (int)($page-1) ?></a>
      <?php endif; ?>

      <?php if ($page < $totalPages): ?>
        <a class="btn btn-outline-primary" href="<?= e($BASE) ?>/print/patient_sheet.php?id=<?= (int)$patient_id ?>&page=<?= (int)($page+1) ?>">Hoja <?= (int)($page+1) ?> ▶</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="sheet-wrap">
  <div class="sheet">

    <!-- LOGO CENTRADO (visible y en impresión) -->
  <div class="print-header">
  <img src="<?= e($logoUrl) ?>" alt="Logo Realmedic">
  <div class="print-title">EVOLUCIÓN Y PRESCRIPCIÓN</div>
</div>

    <!-- Encabezado superior -->
    <table class="top-grid">
      <colgroup>
        <col style="width:22%">
        <col style="width:24%">
        <col style="width:24%">
        <col style="width:14%">
        <col style="width:16%">
      </colgroup>
      <tr>
        <th>Establecimiento</th>
        <th>Nombre</th>
        <th>Apellido</th>
        <th>Sexo / Edad</th>
        <th>N° Historia Clínica</th>
      </tr>
      <tr>
        <td><?= e($establecimiento) ?></td>
        <td><?= e((string)($p['nombres'] ?? '')) ?></td>
        <td><?= e((string)($p['apellidos'] ?? '')) ?></td>
        <td><?= e($sexo) ?><?= $edad!=='' ? ' / '.e($edad) : '' ?></td>
        <td><?= e($hc) ?></td>
      </tr>
    </table>

    <div class="cols">

      <!-- 1 EVOLUCION (4 COLUMNAS) -->
      <div class="colbox">
        <div class="section-title"><span class="n">1</span> Evolución</div>

        <table class="grid">
          <colgroup>
            <col style="width:18%">
            <col style="width:12%">
            <col style="width:52%">
            <col style="width:18%">
          </colgroup>
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Hora</th>
              <th>Nota de evolución</th>
              <th>Nombre y firma</th>
            </tr>
          </thead>
          <tbody>
          <?php
            $i=0;
            foreach ($evols as $r):
              $i++;
              $sigPath = (string)($r['sello_path'] ?? '');
              $author = trim(($r['apellido'] ?? '').' '.($r['nombre'] ?? ''));
              $role = (string)($r['rol'] ?? '');
          ?>
            <tr class="rowline">
              <td><?= e(fmt_date($r['created_at'] ?? '')) ?></td>
              <td><?= e(fmt_time($r['created_at'] ?? '')) ?></td>
              <td><div class="cell-note"><?= e((string)$r['contenido']) ?></div></td>
              <td>
                <div class="sig">
                  <div class="name"><?= e($author) ?></div>
                  <?php if ($sigPath): ?>
                    <img src="<?= e($BASE) ?>/<?= e($sigPath) ?>" alt="sello">
                  <?php endif; ?>
                  <div class="role"><?= e($role) ?></div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php for ($k=$i; $k<$rowsPerPage; $k++): ?>
            <tr class="rowline">
              <td>&nbsp;</td>
              <td>&nbsp;</td>
              <td>&nbsp;</td>
              <td>&nbsp;</td>
            </tr>
          <?php endfor; ?>
          </tbody>
        </table>
      </div>

      <!-- 2 PRESCRIPCIONES -->
      <div class="colbox">
        <div class="section-title"><span class="n">2</span> Prescripciones</div>

        <table class="grid">
          <colgroup>
            <col style="width:18%">
            <col style="width:12%">
            <col style="width:52%">
            <col style="width:18%">
          </colgroup>
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Hora</th>
              <th>Farmacoterapia y cuidados / Indicaciones</th>
              <th>Nombre y firma</th>
            </tr>
          </thead>
          <tbody>
          <?php
            $j=0;
            foreach ($prescs as $r):
              $j++;
              $sigPath = (string)($r['sello_path'] ?? '');
              $author = trim(($r['apellido'] ?? '').' '.($r['nombre'] ?? ''));
              $role = (string)($r['rol'] ?? '');
          ?>
            <tr class="rowline">
              <td><?= e(fmt_date($r['created_at'] ?? '')) ?></td>
              <td><?= e(fmt_time($r['created_at'] ?? '')) ?></td>
              <td><div class="cell-note"><?= e((string)$r['contenido']) ?></div></td>
              <td>
                <div class="sig">
                  <div class="name"><?= e($author) ?></div>
                  <?php if ($sigPath): ?>
                    <img src="<?= e($BASE) ?>/<?= e($sigPath) ?>" alt="sello">
                  <?php endif; ?>
                  <div class="role"><?= e($role) ?></div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php for ($k=$j; $k<$rowsPerPage; $k++): ?>
            <tr class="rowline">
              <td>&nbsp;</td>
              <td>&nbsp;</td>
              <td>&nbsp;</td>
              <td>&nbsp;</td>
            </tr>
          <?php endfor; ?>
          </tbody>
        </table>
      </div>

    </div>

    <div class="foot">
      <div>SNS-MSP / HCU-form.005 / 2008</div>
      <div><b>EVOLUCIÓN Y PRESCRIPCIONES</b></div>
      <div><b>Hoja <?= (int)$page ?> de <?= (int)$totalPages ?></b></div>
    </div>

  </div>
</div>

</body>
</html>
