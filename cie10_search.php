<?php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

/**
 * ver_programacion.php – versión unificada /evoprx
 *
 * ✅ Usa auth.php (carga config + helpers + $pdo)
 * ✅ Tablas con prefijo hosp_
 * ✅ Mantiene: semáforo, ocultar columnas, imprimir, captura, CSV, copiar, WhatsApp Web
 * ✅ Enlaza programacion_quirofano ↔ ingresos (por pq.ingreso_id o i.programacion_id)
 */

date_default_timezone_set('America/Guayaquil');

global $pdo;

// Rol unificado (nuevo esquema)
$u = function_exists('current_user') ? current_user() : null;
$role = (string)($_SESSION['role'] ?? ($_SESSION['rol'] ?? ($u['rol'] ?? ($u['role'] ?? ''))));

// Permisos
$can_edit = in_array($role, ['admin', 'editor'], true);

// =====================
// Prefijo + tablas
// =====================
$TP   = 'hosp_';
$T_PQ = $TP . 'programacion_quirofano';
$T_C  = $TP . 'cirujanos';
$T_I  = $TP . 'ingresos';

// ✅ WhatsApp default (si está definido en config.php)
$WA_DEFAULT_PHONE = defined('WA_DEFAULT_PHONE') ? WA_DEFAULT_PHONE : (getenv('WA_DEFAULT_PHONE') ?: '');

// Helpers
function h($s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// =====================================================
// Endpoint JSON para obtener última cirugía
// Uso: ver_programacion.php?ajax=last_surgery
// =====================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'last_surgery') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    global $pdo, $T_PQ, $T_C;

    $sql = "SELECT pq.id, pq.paciente, pq.fecha, pq.h_cirugia, pq.procedimiento, c.nombre AS cirujano
            FROM {$T_PQ} pq
            LEFT JOIN {$T_C} c ON pq.cirujano_id = c.id
            ORDER BY pq.fecha DESC, pq.id DESC
            LIMIT 1";
    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      echo json_encode(['ok' => false, 'error' => 'No hay cirugías']);
      exit;
    }

    $fecha = $row['fecha'] ? date('d-m-Y', strtotime($row['fecha'])) : '';
    $hora  = $row['h_cirugia'] ? date('H:i', strtotime($row['h_cirugia'])) : '';

    echo json_encode([
      'ok' => true,
      'data' => [
        'id' => (int)$row['id'],
        'paciente' => (string)$row['paciente'],
        'fecha' => $fecha,
        'hora' => $hora,
        'procedimiento' => (string)$row['procedimiento'],
        'cirujano' => (string)($row['cirujano'] ?: '—'),
      ]
    ]);
    exit;

  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
  }
}

// ---------- Filtros ----------
$fechaInicio = (isset($_GET['fecha_inicio']) && $_GET['fecha_inicio'] !== '') ? $_GET['fecha_inicio'] : null;
$fechaFin    = (isset($_GET['fecha_fin'])    && $_GET['fecha_fin']    !== '') ? $_GET['fecha_fin']    : null;

$columnas = [
  "Paciente", "Edad", "Ingreso", "Cirugía", "Procedimiento",
  "Q1", "Q2", "Cirujano", "Anestesiólogo", "Habitación",
  "CC", "MT", "Laboratorio", "AC", "Acciones"
];
$colspan = count($columnas);

// ---------- Query principal (con ingresos) ----------
// Nota: hosp_ingresos usa nombre1/nombre2/apellido1/apellido2
$sql = "SELECT
          pq.*,
          c.nombre AS nombre_cirujano,
          i.id AS ingreso_ref_id,
          i.nombre1 AS ingreso_nombre1,
          i.nombre2 AS ingreso_nombre2,
          i.apellido1 AS ingreso_apellido1,
          i.apellido2 AS ingreso_apellido2
        FROM {$T_PQ} pq
        LEFT JOIN {$T_C} c ON pq.cirujano_id = c.id
        LEFT JOIN {$T_I} i
          ON (i.id = pq.ingreso_id OR i.programacion_id = pq.id)";

$where = [];
$params = [];

if (!$fechaInicio && !$fechaFin) {
  $where[] = "pq.fecha >= CURDATE()";
}
if ($fechaInicio) {
  $where[] = "pq.fecha >= ?";
  $params[] = $fechaInicio;
}
if ($fechaFin) {
  $where[] = "pq.fecha <= ?";
  $params[] = $fechaFin;
}

if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY pq.fecha, pq.h_cirugia";

$errorCarga = '';
try {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $rows = [];
  $errorCarga = $e->getMessage();
}

$days = [
  'Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles',
  'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado', 'Sunday' => 'Domingo'
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Programaciones de Quirófano</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root { --brand:#0f4c81; }
    body { background:#f7fafc; color:#0f172a; }
    .fecha-header { background-color: rgba(15,76,129,.08); font-weight:bold; text-align:center; font-size:1.05rem; }
    .table { border:2px solid #000; }
    .table th, .table td { border:2px solid #000; font-weight:600; font-size:1rem; }
    .table thead th { background:#1f2937; color:#fff; position:sticky; top:0; z-index:1; }
    .protesis { color:#d00; }
    .details { display:none; }

    tr.status-lista td { background-color:#d4edda !important; transition: background-color .2s ease; }
    tr.status-retraso td { background-color:#f8d7da !important; transition: background-color .2s ease; }
    tr.status-lista td:first-child { box-shadow: inset 4px 0 0 #28a745; }
    tr.status-retraso td:first-child { box-shadow: inset 4px 0 0 #dc3545; }

    .toolbar .btn { margin-right:.5rem; }

    @media print {
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .no-print { display:none !important; }
      .table thead th { background:#1f2937 !important; color:#fff !important; }
      tr.status-lista td, tr.status-retraso td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
  </style>
</head>
<body>
<div class="container-fluid py-3">
  <h2 class="text-center mb-3">Programaciones de Quirófano</h2>

  <div class="toolbar d-flex flex-wrap align-items-center mb-3 no-print">
    <?php if ($can_edit): ?>
      <a href="programar_cirugia.php" class="btn btn-success">Programar Cirugía</a>
    <?php endif; ?>

    <form method="GET" class="form-inline ml-auto">
      <div class="form-group mb-2 mr-2">
        <label for="fecha_inicio" class="mr-2">Inicio:</label>
        <input type="date" id="fecha_inicio" name="fecha_inicio" class="form-control" value="<?= h($fechaInicio) ?>">
      </div>
      <div class="form-group mb-2 mr-2">
        <label for="fecha_fin" class="mr-2">Fin:</label>
        <input type="date" id="fecha_fin" name="fecha_fin" class="form-control" value="<?= h($fechaFin) ?>">
      </div>
      <button type="submit" class="btn btn-primary mb-2">Filtrar</button>
      <a href="ver_programacion.php" class="btn btn-secondary mb-2 ml-2">Limpiar</a>
    </form>
  </div>

  <div class="no-print mb-2">
    <strong>Mostrar/Ocultar Columnas:</strong><br>
    <?php foreach ($columnas as $i => $col): ?>
      <label class="mr-3">
        <input type="checkbox" class="toggle-col" data-col="<?= (int)$i ?>" checked> <?= h($col) ?>
      </label>
    <?php endforeach; ?>
  </div>

  <div class="no-print mb-2">
    <strong>Semáforo:</strong>
    <span class="badge badge-success">Lista</span>
    <span class="badge badge-danger">Retrasada</span>
    <small class="text-muted ml-2 d-block d-md-inline">
      Click en una fila para marcarla como “Lista”. Si la hora ya pasó y no está marcada, se pintará “Retrasada” automáticamente.
    </small>
  </div>

  <div class="no-print mb-2 d-flex flex-wrap align-items-center">
    <label class="mr-2 mb-0"><strong>WhatsApp:</strong></label>
    <input id="wa-phone" class="form-control mr-2" style="max-width:260px"
           placeholder="Ej: 593987106060" value="<?= h($WA_DEFAULT_PHONE) ?>">
    <small class="text-muted">(con código país, sin +)</small>
  </div>

  <div class="no-print mb-3">
    <button class="btn btn-primary" onclick="window.print()">Imprimir</button>
    <button class="btn btn-secondary" id="btn-captura">Capturar PNG</button>
    <button class="btn btn-outline-dark" id="btn-csv">Exportar CSV</button>
    <button class="btn btn-outline-dark" id="btn-copiar">Copiar Tabla</button>
    <button class="btn btn-success" id="btn-wa-enviar">Enviar a WhatsApp Web (texto + captura)</button>
  </div>

  <?php if (!empty($errorCarga)): ?>
    <div class="alert alert-danger"><?= h($errorCarga) ?></div>
  <?php endif; ?>

  <div id="tabla-wrap">
    <table id="tabla-cx" class="table table-striped table-bordered">
      <thead class="thead-dark">
        <tr>
          <?php foreach ($columnas as $col) echo '<th>' . h($col) . '</th>'; ?>
        </tr>
      </thead>
      <tbody>
      <?php
      $currentDate = null;
      foreach ($rows as $row):

        $ingresoId = (int)($row['ingreso_id'] ?? 0);
        if ($ingresoId <= 0 && !empty($row['ingreso_ref_id'])) $ingresoId = (int)$row['ingreso_ref_id'];

        $ingAp = trim((string)($row['ingreso_apellido1'] ?? '') . ' ' . (string)($row['ingreso_apellido2'] ?? ''));
        $ingNo = trim((string)($row['ingreso_nombre1'] ?? '') . ' ' . (string)($row['ingreso_nombre2'] ?? ''));
        $pacienteMostrar = trim($ingAp . ' ' . $ingNo);
        if ($pacienteMostrar === '') $pacienteMostrar = (string)($row['paciente'] ?? '');

        $isProtesis = ((int)($row['es_protesis'] ?? 0) === 1) ? 'protesis' : '';

        if ($currentDate !== ($row['fecha'] ?? null)) {
          $currentDate = $row['fecha'];
          $diaIng = date('l', strtotime($currentDate));
          $diaEs  = $days[$diaIng] ?? $diaIng;
          $horaActual = date('H:i:s');
          $fechaHoy   = date('d/m/Y');
          $fechaFmt   = date('d-m-Y', strtotime($currentDate));

          echo '<tr><td colspan="'.$colspan.'" class="fecha-header">📅 Fecha: '.h($fechaFmt).' - '.h($diaEs)
             . ' <span style="color:#c00; font-size:.9em; margin-left:20px;">🕒 Última actualización: '
             . h($fechaHoy) . ' - ' . h($horaActual) . '</span></td></tr>';
        }

        $laboratorio = nl2br(h($row['laboratorio'] ?? ''));
        $h_ingreso   = !empty($row['h_ingreso']) ? date('H:i', strtotime($row['h_ingreso'])) : '';
        $h_cirugia   = !empty($row['h_cirugia']) ? date('H:i', strtotime($row['h_cirugia'])) : '';
      ?>
        <tr class="fila-cx <?= h($isProtesis) ?>"
            data-id="<?= (int)$row['id'] ?>"
            data-fecha="<?= h($row['fecha'] ?? '') ?>"
            data-hora="<?= h($row['h_cirugia'] ?? '') ?>">
          <td><?= h($pacienteMostrar) ?></td>
          <td><?= h($row['edad'] ?? '') ?></td>
          <td><?= h($h_ingreso) ?></td>
          <td><?= h($h_cirugia) ?></td>
          <td><?= h($row['procedimiento'] ?? '') ?></td>
          <td><?= (($row['Q1'] ?? '') === 'X' ? 'X' : '') ?></td>
          <td><?= (($row['Q2'] ?? '') === 'X' ? 'X' : '') ?></td>
          <td><?= h($row['nombre_cirujano'] ?? '') ?></td>
          <td><?= h($row['anestesiologo'] ?? '') ?></td>
          <td><?= h($row['habitacion'] ?? '') ?></td>
          <td><?= h($row['casa_comercial'] ?? '') ?></td>
          <td><?= h($row['mesa_traccion'] ?? '') ?></td>
          <td><?= $laboratorio ?></td>
          <td><?= h($row['arco_en_c'] ?? '') ?></td>
          <td>
            <?php if ($ingresoId > 0): ?>
              <a href="/evoprx/residentes/modificar_ingreso.php?id=<?= (int)$ingresoId ?>" class="btn btn-primary btn-sm">Ver/Editar ingreso</a>
            <?php else: ?>
              <a href="/evoprx/residentes/ingresar_desde_programacion.php?id=<?= (int)$row['id'] ?>" class="btn btn-success btn-sm">Ingresar</a>
            <?php endif; ?>

            <button class="btn btn-info btn-sm" onclick="toggleDetails(this)">Detalles</button>

            <?php if ($can_edit): ?>
              <a href="modificar_cirugia.php?id=<?= (int)$row['id'] ?>" class="btn btn-warning btn-sm">Modificar</a>
              <a href="eliminar_cirugia.php?id=<?= (int)$row['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Estás seguro?')">Eliminar</a>
            <?php endif; ?>
          </td>
        </tr>

        <tr class="details">
          <td colspan="<?= (int)$colspan ?>">
            <strong>Detalles Adicionales:</strong><br>
            <strong>Procedimiento:</strong> <?= h($row['procedimiento'] ?? '') ?><br>
            <strong>Quirófano:</strong> <?= (($row['Q1'] ?? '') === 'X') ? 'Q1' : ((($row['Q2'] ?? '') === 'X') ? 'Q2' : 'Sin asignar') ?><br>
            <strong>Cirujano:</strong> <?= h($row['nombre_cirujano'] ?? '') ?><br>
            <strong>Anestesiólogo:</strong> <?= h($row['anestesiologo'] ?? '') ?><br>
            <strong>Habitación:</strong> <?= h($row['habitacion'] ?? '') ?><br>
            <strong>Casa Comercial:</strong> <?= h($row['casa_comercial'] ?? '') ?><br>
            <strong>Mesa de Tracción:</strong> <?= h($row['mesa_traccion'] ?? '') ?><br>
            <strong>Laboratorio:</strong> <?= $laboratorio ?><br>
            <strong>Arco en C:</strong> <?= h($row['arco_en_c'] ?? '') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
function toggleDetails(btn){
  const tr = btn.closest('tr');
  const details = tr.nextElementSibling;
  const show = details.style.display === 'none' || details.style.display === '';
  details.style.display = show ? 'table-row' : 'none';
  btn.textContent = show ? 'Ocultar' : 'Detalles';
}

// ==== Mostrar/Ocultar columnas (persistente) ====
const STORAGE_COLS = 'cxCols:visible';
const checks = document.querySelectorAll('.toggle-col');
const table  = document.getElementById('tabla-cx');

// ON: 0,2,3,4,7,9,12,13,14
// OFF: 1,5,6,8,10,11
function defaultVisibleFor(key) {
  const idx = parseInt(key, 10);
  const onByDefault = new Set([0, 2, 3, 4, 7, 9, 12, 13, 14]);
  return onByDefault.has(idx);
}

function applyCols() {
  const raw = localStorage.getItem(STORAGE_COLS);
  const vis = raw ? JSON.parse(raw) : null;

  checks.forEach(chk => {
    const key = chk.dataset.col;
    const idx = parseInt(key, 10) + 1;

    const visible = (vis && Object.prototype.hasOwnProperty.call(vis, key))
      ? !!vis[key]
      : defaultVisibleFor(key);

    chk.checked = visible;

    table.querySelectorAll(`thead th:nth-child(${idx})`)
         .forEach(th => th.style.display = visible ? '' : 'none');

    table.querySelectorAll('tbody tr').forEach(tr => {
      const tds = tr.querySelectorAll('td');
      if (tds.length >= idx) tds[idx - 1].style.display = visible ? '' : 'none';
    });
  });
}

checks.forEach(chk => chk.addEventListener('change', () => {
  const raw = localStorage.getItem(STORAGE_COLS);
  const vis = raw ? JSON.parse(raw) : {};
  vis[chk.dataset.col] = chk.checked;
  localStorage.setItem(STORAGE_COLS, JSON.stringify(vis));
  applyCols();
}));

// ==== Semáforo por fila ====
const STORAGE_PREFIX = 'cxEstado:';
function parseDateTime(fechaStr, horaStr){
  if(!fechaStr || !horaStr) return null;
  const [y,m,d] = fechaStr.split('-').map(Number);
  const [hh,mm] = horaStr.split(':').map(Number);
  return new Date(y, (m||1)-1, d||1, hh||0, mm||0, 0);
}
function applyStatus(row, status){
  row.classList.remove('status-lista','status-retraso');
  if(status==='lista') row.classList.add('status-lista');
  if(status==='retrasada') row.classList.add('status-retraso');
}
function getStored(row){ return localStorage.getItem(STORAGE_PREFIX + row.dataset.id); }
function setStored(row, status){
  if(!status) localStorage.removeItem(STORAGE_PREFIX + row.dataset.id);
  else localStorage.setItem(STORAGE_PREFIX + row.dataset.id, status);
}
function updateDelays(){
  const now = new Date();
  document.querySelectorAll('tr.fila-cx').forEach(row => {
    const stored = getStored(row);
    if(stored==='lista'){ applyStatus(row,'lista'); return; }
    const dt = parseDateTime(row.dataset.fecha, row.dataset.hora);
    if(dt && now > dt) applyStatus(row,'retrasada'); else applyStatus(row,null);
  });
}
document.addEventListener('click', e => {
  const row = e.target.closest('tr.fila-cx');
  if(!row) return;
  if(e.target.closest('a,button')) return;
  const isLista = row.classList.contains('status-lista');
  if(isLista){ setStored(row,null); updateDelays(); }
  else { applyStatus(row,'lista'); setStored(row,'lista'); }
});

// ==== Captura PNG ====
document.getElementById('btn-captura').addEventListener('click', async () => {
  const node = document.getElementById('tabla-wrap');
  const canvas = await html2canvas(node, { scale: 2, backgroundColor: '#ffffff' });
  const link = document.createElement('a');
  link.download = 'programacion_quirofano.png';
  link.href = canvas.toDataURL('image/png');
  link.click();
});

// ==== Exportar CSV ====
function tableToCSV(tbl){
  const rows = Array.from(tbl.querySelectorAll('tr'));
  return rows.map(tr => Array.from(tr.querySelectorAll('th,td'))
    .filter(td => td.style.display !== 'none')
    .map(td => '"' + (td.innerText||'').replace(/"/g,'""') + '"')
    .join(',')
  ).join('\n');
}
document.getElementById('btn-csv').addEventListener('click', () => {
  const csv = tableToCSV(document.getElementById('tabla-cx'));
  const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = 'programacion_quirofano.csv'; a.click();
  URL.revokeObjectURL(url);
});

// ==== Copiar tabla ====
document.getElementById('btn-copiar').addEventListener('click', async () => {
  try{
    const sel = window.getSelection(); const range = document.createRange();
    range.selectNode(document.getElementById('tabla-wrap'));
    sel.removeAllRanges(); sel.addRange(range);
    const ok = document.execCommand('copy');
    sel.removeAllRanges();
    alert(ok ? '✅ Tabla copiada.' : '⚠️ No se pudo copiar.');
  }catch(e){ alert('⚠️ Tu navegador bloqueó la copia.'); }
});

// ==== WhatsApp Web: texto + captura ====
function normalizePhone(input){
  return (input || '').toString().replace(/[^0-9]/g, '');
}
async function copyImageToClipboard(blob){
  if (!window.isSecureContext) return { ok:false, reason:'El sitio debe estar en HTTPS' };
  if (!navigator.clipboard || !window.ClipboardItem) return { ok:false, reason:'Tu navegador no soporta ClipboardItem' };
  try {
    await navigator.clipboard.write([new ClipboardItem({ [blob.type]: blob })]);
    return { ok:true };
  } catch(e){
    return { ok:false, reason: (e && e.message) ? e.message : 'No se pudo copiar al portapapeles' };
  }
}
document.getElementById('btn-wa-enviar').addEventListener('click', async () => {
  const btn = document.getElementById('btn-wa-enviar');
  const phoneInput = document.getElementById('wa-phone');
  const phone = normalizePhone(phoneInput.value);

  if (!phone) {
    alert('⚠️ Ingresa un número WhatsApp con código país. Ej: 593987106060');
    phoneInput.focus();
    return;
  }

  btn.disabled = true;
  const oldText = btn.textContent;
  btn.textContent = 'Preparando...';

  try {
    const r = await fetch('ver_programacion.php?ajax=last_surgery', { cache: 'no-store' });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'No se pudo leer la última cirugía');
    const d = j.data;

    btn.textContent = 'Capturando...';
    const node = document.getElementById('tabla-wrap');
    const canvas = await html2canvas(node, { scale: 1.5, backgroundColor: '#ffffff' });
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
    if (!blob) throw new Error('No se pudo generar la captura');

    btn.textContent = 'Copiando imagen...';
    const clip = await copyImageToClipboard(blob);

    const url = window.location.href.split('#')[0];
    const msg =
      `*Programación de quirófano*\\n`+
      `🆕 *Última cirugía:* ${d.paciente} | ${d.fecha} ${d.hora}\\n`+
      `🩺 ${d.procedimiento}\\n`+
      `👨‍⚕️ ${d.cirujano}\\n`+
      `\\nVer programación: ${url}`;

    btn.textContent = 'Abriendo WhatsApp Web...';
    const waUrl = `https://web.whatsapp.com/send?phone=${encodeURIComponent(phone)}&text=${encodeURIComponent(msg)}`;
    window.open(waUrl, '_blank');

    if (clip.ok) {
      alert('✅ Listo. Se abrió WhatsApp Web con el texto.\\n\\n📌 La captura ya está copiada: entra al chat y pega con Ctrl+V y envía.');
    } else {
      const link = document.createElement('a');
      link.download = 'programacion_quirofano.png';
      link.href = URL.createObjectURL(blob);
      link.click();
      setTimeout(() => URL.revokeObjectURL(link.href), 1500);

      alert('✅ Se abrió WhatsApp Web con el texto.\\n\\n⚠️ No pude copiar la imagen: ' + (clip.reason || '') +
            '\\nSe descargó la captura. Arrástrala al chat o adjúntala y envía.');
    }

  } catch (e) {
    alert('⚠️ Error: ' + (e && e.message ? e.message : e));
  } finally {
    btn.disabled = false;
    btn.textContent = oldText;
  }
});

// ==== INIT ====
applyCols();
for (const row of document.querySelectorAll('tr.fila-cx')) {
  if (getStored(row) === 'lista') applyStatus(row,'lista');
}
updateDelays();
setInterval(updateDelays, 60000);
</script>
</body>
</html>