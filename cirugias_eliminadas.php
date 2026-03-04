<?php
// /public_html/evoprx/programacion/guardar_reagendada.php

require_once __DIR__ . '/../auth.php';
require_login();
require_once __DIR__ . '/../helpers.php';

$BASE = $GLOBALS['BASE_URL'] ?? '';
$u = current_user();

// Permisos: admin o editor
$rol = (string)($u['rol'] ?? '');
if (!in_array($rol, ['admin', 'editor'], true)) {
  echo "<script>alert('No tienes permiso para realizar esta acción.'); window.location.href='ver_programacion.php';</script>";
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<script>alert('Acceso no permitido.'); window.location.href='cirugias_eliminadas.php';</script>";
    exit;
  }

  // CSRF (si tu form lo envía)
  $token = (string)($_POST['_csrf'] ?? '');
  if ($token !== '' && !csrf_check($token)) {
    echo "<script>alert('Sesión inválida. Recarga e intenta de nuevo.'); window.location.href='cirugias_eliminadas.php';</script>";
    exit;
  }

  // Datos
  $id_eliminada  = (int)($_POST['id_eliminada'] ?? 0);
  $fecha         = (string)($_POST['fecha'] ?? '');
  $dia           = (string)($_POST['dia'] ?? '');
  $paciente      = trim((string)($_POST['paciente'] ?? ''));
  $edad          = ($_POST['edad'] ?? null);
  $h_ingreso     = (string)($_POST['h_ingreso'] ?? '');
  $h_cirugia     = (string)($_POST['h_cirugia'] ?? '');
  $procedimiento = trim((string)($_POST['procedimiento'] ?? ''));

  $quirofano = (string)($_POST['quirófano'] ?? '');
  $q1 = ($quirofano === 'Q1') ? 'X' : '';
  $q2 = ($quirofano === 'Q2') ? 'X' : '';

  $cirujano_id   = isset($_POST['cirujano_id']) && $_POST['cirujano_id'] !== '' ? (int)$_POST['cirujano_id'] : null;
  $anestesiologo = trim((string)($_POST['anestesiologo'] ?? ''));
  $habitacion    = (string)($_POST['habitacion'] ?? '');
  $casa_comercial= (string)($_POST['casa_comercial'] ?? '');
  $mesa_traccion = (string)($_POST['mesa_traccion'] ?? '');
  $laboratorio   = (string)($_POST['laboratorio'] ?? '');
  $arco_en_c     = (string)($_POST['arco_en_c'] ?? '');
  $es_protesis   = isset($_POST['es_protesis']) ? 1 : 0;

  if ($id_eliminada <= 0 || $fecha === '' || $dia === '' || $paciente === '' || $h_ingreso === '' || $procedimiento === '') {
    throw new Exception('Faltan datos obligatorios para reagendar.');
  }

  // Importante: hacerlo atómico
  $pdo->beginTransaction();

  // 1) Insertar nuevamente en hosp_programacion_quirofano
  $insert = $pdo->prepare("
    INSERT INTO hosp_programacion_quirofano
      (fecha, dia, paciente, edad, h_ingreso, h_cirugia, procedimiento, Q1, Q2, cirujano_id, anestesiologo, habitacion, casa_comercial, mesa_traccion, laboratorio, arco_en_c, es_protesis)
    VALUES
      (:fecha, :dia, :paciente, :edad, :h_ingreso, :h_cirugia, :procedimiento, :Q1, :Q2, :cirujano_id, :anestesiologo, :habitacion, :casa_comercial, :mesa_traccion, :laboratorio, :arco_en_c, :es_protesis)
  ");

  $insert->execute([
    ':fecha'         => $fecha,
    ':dia'           => $dia,
    ':paciente'      => $paciente,
    ':edad'          => $edad,
    ':h_ingreso'     => $h_ingreso,
    ':h_cirugia'     => ($h_cirugia !== '' ? $h_cirugia : null),
    ':procedimiento' => $procedimiento,
    ':Q1'            => $q1,
    ':Q2'            => $q2,
    ':cirujano_id'   => $cirujano_id,
    ':anestesiologo' => $anestesiologo,
    ':habitacion'    => $habitacion,
    ':casa_comercial'=> $casa_comercial,
    ':mesa_traccion' => $mesa_traccion,
    ':laboratorio'   => $laboratorio,
    ':arco_en_c'     => $arco_en_c,
    ':es_protesis'   => $es_protesis
  ]);

  // 2) Eliminar de hosp_registro_cirugias_eliminadas
  $delete = $pdo->prepare("DELETE FROM hosp_registro_cirugias_eliminadas WHERE id = :id LIMIT 1");
  $delete->execute([':id' => $id_eliminada]);

  $pdo->commit();

  echo "<script>alert('Cirugía reagendada correctamente.'); window.location.href='ver_programacion.php';</script>";
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo "<div class='alert alert-danger m-3'>Error: " . e($e->getMessage()) . "</div>";
}