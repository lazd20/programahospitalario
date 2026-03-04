<?php
// audits/list.php
require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

$BASE = rtrim($GLOBALS['BASE_URL'] ?? '', '/');
$u = current_user();

// Solo admin
if (($u['rol'] ?? '') !== 'admin') {
  flash_set('error', 'Acceso restringido.');
  redirect($BASE . '/index.php');
}

/* =========================
   Helpers
========================= */
function hdate($dt){
  if(!$dt) return '';
  try { return (new DateTime($dt))->format('d/m/Y H:i'); } catch(Throwable $e){ return (string)$dt; }
}
function clip($s, $n=120){
  $s = trim((string)$s);
  $s = preg_replace('/\s+/', ' ', $s);
  if (mb_strlen($s) <= $n) return $s;
  return mb_substr($s, 0, $n) . '…';
}

/* =========================
   Filtros
========================= */
$type       = trim((string)($_GET['type'] ?? ''));   // evolution | prescription | ''
$patient_id = (int)($_GET['patient_id'] ?? 0);
$editor_id  = (int)($_GET['editor_id'] ?? 0);
$q          = trim((string)($_GET['q'] ?? ''));
$from       = trim((string)($_GET['from'] ?? ''));   // YYYY-MM-DD
$to         = trim((string)($_GET['to'] ?? ''));     // YYYY-MM-DD
$page       = max(1, (int)($_GET['page'] ?? 1));

$perPage = 50;
$offset  = ($page - 1) * $perPage;

/* =========================
   WHERE dinámico
========================= */
$where = [];
$args  = [];

if ($type === 'evolution' || $type === 'prescription') {
  $where[] = "l.entity_type = ?";
  $args[]  = $type;
}
if ($patient_id > 0) {
  $where[] = "l.patient_id = ?";
  $args[]  = $patient_id;
}
if ($editor_id > 0) {
  $where[] = "l.edited_by = ?";
  $args[]  = $editor_id;
}
if ($from !== '') {
  $where[] = "l.edited_at >= ?";
  $args[]  = $from . " 00:00:00";
}
if ($to !== '') {
  $where[] = "l.edited_at <= ?";
  $args[]  = $to . " 23:59:59";
}
if ($q !== '') {
  $where[] = "(l.old_content LIKE ? OR l.new_content LIKE ? OR pu.nombres LIKE ? OR pu.apellidos LIKE ? OR ed.username LIKE ? OR ed.nombre LIKE ? OR ed.apellido LIKE ?)";
  $like = '%' . $q . '%';
  array_push($args, $like, $like, $like, $like, $like, $like, $like);
}

$sqlWhere = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/* =========================
   Total
========================= */
$stCount = $pdo->prepare("
  SELECT COUNT(*)
  FROM edit_logs l
  LEFT JOIN patients pu ON pu.id = l.patient_id
  LEFT JOIN users ed ON ed.id = l.edited_by
  $sqlWhere
");
$stCount->execute($args);
$total = (int)$stCount->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

/* =========================
   Listado
========================= */
$st = $pdo->prepare("
  SELECT
    l.id,
    l.entity_type,
    l.entity_id,
    l.patient_id,
    l.edited_by,
    l.edited_at,
    l.ip,
    l.user_agent,
    l.old_content,
    l.new_content,

    pu.nombres AS p_nombres,
    pu.apellidos AS p_apellidos,
    pu.hc AS p_hc,

    ed.username AS ed_username,
    ed.nombre AS ed_nombre,
    ed.apellido AS ed_apellido,
    ed.rol AS ed_rol

  FROM edit_logs l
  LEFT JOIN patients pu ON pu.id = l.patient_id
  LEFT JOIN users ed ON ed.id = l.edited_by
  $sqlWhere
  ORDER BY l.edited_at DESC, l.id DESC
  LIMIT $perPage OFFSET $offset
");
$st->execute($args);
$rows = $st->fetchAll();

/* =========================
   Editores (para filtro)
========================= */
$stEditors = $pdo->query("
  SELECT DISTINCT u.id, u.username, u.nombre, u.apellido
  FROM edit_logs l
  INNER JOIN users u ON u.id = l.edited_by
  ORDER BY u.username ASC
  LIMIT 200
");
$editors = $stEditors->fetchAll();

include __DIR__ . '/../partials/navbar.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Auditoría de ediciones</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .small2{ font-size: 12px; }
    .cell-pre{
      white-space: pre-wrap;
      overflow-wrap: anywhere;
      word-break: break-word;
      line-height: 1.2;
      max-height: 180px;
      overflow: auto;
      border:1px dashed #ddd;
      padding:8px;
      border-radius:6px;
      background:#fafafa;
    }
  </style>
</head>
<body>

<div class="container py-4">
  <?php include __DIR__ . '/../partials/flash.php'; ?>

  <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3">
    <h3 class="m-0">Auditoría de ediciones</h3>
    <div class="text-muted small">
      Total: <b><?= (int)$total ?></b>
    </div>
  </div>

  <form class="card card-body mb-3" method="get" action="">
    <div class="row g-2">
      <div class="col-md-2">
        <label class="form-label small">Tipo</label>
        <select class="form-select form-select-sm" name="type">
          <option value="">Todos</option>
          <option value="evolution" <?= $type==='evolution'?'selected':''; ?>>Evolución</option>
          <option value="prescription" <?= $type==='prescription'?'selected':''; ?>>Prescripción</option>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label small">Paciente ID</label>
        <input class="form-control form-control-sm" type="number" name="patient_id" value="<?= (int)$patient_id ?>" min="0">
      </div>

      <div class="col-md-3">
        <label class="form-label small">Editor</label>
        <select class="form-select form-select-sm" name="editor_id">
          <option value="0">Todos</option>
          <?php foreach($editors as $ed): ?>
            <?php
              $nm = trim(($ed['apellido'] ?? '').' '.($ed['nombre'] ?? ''));
              $lbl = ($ed['username'] ?? 'user') . ($nm!=='' ? " — $nm" : "");
            ?>
            <option value="<?= (int)$ed['id'] ?>" <?= $editor_id===(int)$ed['id']?'selected':''; ?>>
              <?= e($lbl) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label small">Desde</label>
        <input class="form-control form-control-sm" type="date" name="from" value="<?= e($from) ?>">
      </div>

      <div class="col-md-2">
        <label class="form-label small">Hasta</label>
        <input class="form-control form-control-sm" type="date" name="to" value="<?= e($to) ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label small">Buscar (texto / nombres)</label>
        <input class="form-control form-control-sm" type="text" name="q" value="<?= e($q) ?>" placeholder="Ej: 'amoxicilina', 'dolor', 'zambrano'...">
      </div>

      <div class="col-md-6 d-flex align-items-end gap-2">
        <button class="btn btn-primary btn-sm">Filtrar</button>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e($BASE) ?>/audits/list.php">Limpiar</a>
      </div>
    </div>
  </form>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-sm align-middle m-0">
          <thead class="table-dark">
            <tr>
              <th style="width:120px;">Fecha</th>
              <th style="width:115px;">Tipo</th>
              <th>Paciente</th>
              <th style="width:180px;">Editor</th>
              <th style="width:90px;">IP</th>
              <th>Cambios</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$rows): ?>
            <tr><td colspan="6" class="text-center text-muted p-4">Sin registros con estos filtros.</td></tr>
          <?php else: ?>
            <?php foreach($rows as $r): ?>
              <?php
                $pName = trim(($r['p_apellidos'] ?? '').' '.($r['p_nombres'] ?? ''));
                $pMeta = $pName !== '' ? $pName : ('Paciente #' . (int)$r['patient_id']);
                if (!empty($r['p_hc'])) $pMeta .= ' (HC: ' . $r['p_hc'] . ')';

                $edName = trim(($r['ed_apellido'] ?? '').' '.($r['ed_nombre'] ?? ''));
                $edLbl = ($r['ed_username'] ?? 'usuario') . ($edName!=='' ? " — $edName" : "");
                $entLbl = ($r['entity_type'] ?? '') === 'evolution' ? 'Evolución' : 'Prescripción';

                $old = (string)($r['old_content'] ?? '');
                $new = (string)($r['new_content'] ?? '');
              ?>
              <tr>
                <td class="small2"><?= e(hdate($r['edited_at'] ?? '')) ?></td>
                <td class="small2">
                  <span class="badge text-bg-secondary"><?= e($entLbl) ?></span><br>
                  <span class="text-muted small2">ID: <?= (int)$r['entity_id'] ?></span>
                </td>
                <td class="small2">
                  <a href="<?= e($BASE) ?>/patients/view.php?id=<?= (int)$r['patient_id'] ?>" class="text-decoration-none">
                    <?= e($pMeta) ?>
                  </a>
                </td>
                <td class="small2">
                  <?= e($edLbl) ?><br>
                  <span class="text-muted"><?= e((string)($r['ed_rol'] ?? '')) ?></span>
                </td>
                <td class="small2 mono"><?= e((string)($r['ip'] ?? '')) ?></td>
                <td class="small2">
                  <details>
                    <summary class="small2">
                      <span class="text-muted">Antes:</span> <?= e(clip($old, 90)) ?>
                      &nbsp; <span class="text-muted">→</span> &nbsp;
                      <span class="text-muted">Después:</span> <?= e(clip($new, 90)) ?>
                    </summary>

                    <div class="row g-2 mt-2">
                      <div class="col-md-6">
                        <div class="text-muted small2 mb-1">Antes</div>
                        <div class="cell-pre"><?= e($old) ?></div>
                      </div>
                      <div class="col-md-6">
                        <div class="text-muted small2 mb-1">Después</div>
                        <div class="cell-pre"><?= e($new) ?></div>
                      </div>
                      <div class="col-12 mt-2 text-muted small2">
                        UA: <?= e((string)($r['user_agent'] ?? '')) ?>
                      </div>
                    </div>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Paginación -->
  <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
    <div class="text-muted small">
      Página <b><?= (int)$page ?></b> de <b><?= (int)$totalPages ?></b>
    </div>

    <div class="d-flex gap-2">
      <?php
        $qs = $_GET;

        $qs['page'] = max(1, $page - 1);
        $prevUrl = $BASE . '/audits/list.php?' . http_build_query($qs);

        $qs['page'] = min($totalPages, $page + 1);
        $nextUrl = $BASE . '/audits/list.php?' . http_build_query($qs);
      ?>
      <a class="btn btn-outline-secondary btn-sm <?= $page<=1?'disabled':''; ?>" href="<?= e($prevUrl) ?>">◀ Anterior</a>
      <a class="btn btn-outline-secondary btn-sm <?= $page>=$totalPages?'disabled':''; ?>" href="<?= e($nextUrl) ?>">Siguiente ▶</a>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
