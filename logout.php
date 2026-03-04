<?php
// Conexión a la base de datos
$host = 'localhost';
$dbname = 'sitiosnuevos_hospital';
$username = 'sitiosnuevos_cirtugia';
$password = 'Realmedic2020';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Variables generales
    $dia = $_POST['dia'];
    $fecha = $_POST['fecha'];
    $anestesiologo = $_POST['anestesiologo'];

    // Cirujano: nuevo o existente
    if (!empty($_POST['nuevo_cirujano'])) {
        $cirujano = $_POST['nuevo_cirujano'];
        $stmtInsertCirujano = $pdo->prepare("INSERT INTO cirujanos (nombre, especialidad) VALUES (:nombre, 'General')");
        $stmtInsertCirujano->bindParam(':nombre', $cirujano);
        $stmtInsertCirujano->execute();
        $cirujanoId = $pdo->lastInsertId();
    } else {
        $cirujanoId = $_POST['cirujano'];
    }

    // Arrays múltiples
    $pacientes = $_POST['paciente'];
    $edades = $_POST['edad'];
    $h_ingresos = $_POST['h_ingreso'];
    $h_cirugias = $_POST['h_cirugia'];
    $procedimientos = $_POST['procedimiento'];
    $tipos_cirugia = $_POST['tipo_cirugia'];
    $quirofanos = $_POST['quirófano'];
    $habitaciones = $_POST['habitacion'];
    $casa_comerciales = $_POST['casa_comercial'];
    $mesa_tracciones = $_POST['mesa_traccion'];
    $laboratorios = $_POST['laboratorio'];
    $arcos = $_POST['arco_en_c'];
    $protesis = isset($_POST['protesis']) ? $_POST['protesis'] : [];

    $total = count($pacientes);
    $errores = 0;

    for ($i = 0; $i < $total; $i++) {
        // Validar que tipo_cirugia_id sea numérico
        if (!isset($tipos_cirugia[$i]) || !is_numeric($tipos_cirugia[$i])) {
            error_log("Cirugía #$i omitida: tipo de cirugía no numérico.");
            $errores++;
            continue;
        }

        $tipo_cirugia_id = intval($tipos_cirugia[$i]);

        // Verificar que exista en la tabla tipos_cirugia
        $verifica = $pdo->prepare("SELECT COUNT(*) FROM tipos_cirugia WHERE id = ?");
        $verifica->execute([$tipo_cirugia_id]);
        if ($verifica->fetchColumn() == 0) {
            error_log("Cirugía #$i omitida: tipo_cirugia_id $tipo_cirugia_id no existe en la base.");
            $errores++;
            continue;
        }

        // Quirófanos
        $Q1 = ($quirofanos[$i] === 'Q1') ? 'X' : '';
        $Q2 = ($quirofanos[$i] === 'Q2') ? 'X' : '';

        // Marcar si es cirugía de prótesis
        $es_protesis = isset($protesis[$i]) ? 1 : 0;

        // Preparar SQL
        $sql = "INSERT INTO programacion_quirofano (
                    dia, fecha, paciente, edad, h_ingreso, h_cirugia, procedimiento, tipo_cirugia_id,
                    Q1, Q2, cirujano_id, anestesiologo, habitacion, casa_comercial, mesa_traccion,
                    laboratorio, arco_en_c, es_protesis
                ) VALUES (
                    :dia, :fecha, :paciente, :edad, :h_ingreso, :h_cirugia, :procedimiento, :tipo_cirugia,
                    :Q1, :Q2, :cirujano_id, :anestesiologo, :habitacion, :casa_comercial, :mesa_traccion,
                    :laboratorio, :arco_en_c, :es_protesis
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':dia', $dia);
        $stmt->bindParam(':fecha', $fecha);
        $stmt->bindParam(':paciente', $pacientes[$i]);
        $stmt->bindParam(':edad', $edades[$i]);
        $stmt->bindParam(':h_ingreso', $h_ingresos[$i]);
        $stmt->bindParam(':h_cirugia', $h_cirugias[$i]);
        $stmt->bindParam(':procedimiento', $procedimientos[$i]);
        $stmt->bindParam(':tipo_cirugia', $tipo_cirugia_id);
        $stmt->bindParam(':Q1', $Q1);
        $stmt->bindParam(':Q2', $Q2);
        $stmt->bindParam(':cirujano_id', $cirujanoId);
        $stmt->bindParam(':anestesiologo', $anestesiologo);
        $stmt->bindParam(':habitacion', $habitaciones[$i]);
        $stmt->bindParam(':casa_comercial', $casa_comerciales[$i]);
        $stmt->bindParam(':mesa_traccion', $mesa_tracciones[$i]);
        $stmt->bindParam(':laboratorio', $laboratorios[$i]);
        $stmt->bindParam(':arco_en_c', $arcos[$i]);
        $stmt->bindParam(':es_protesis', $es_protesis, PDO::PARAM_INT);

        try {
            $stmt->execute();
        } catch (PDOException $e) {
            $errores++;
            error_log("Error en cirugía #$i: " . $e->getMessage());
            continue;
        }
    }

    if ($errores === 0) {
        echo "<script>
                alert('Todas las cirugías fueron agendadas exitosamente.');
                window.location.href = 'ver_programacion.php';
              </script>";
    } else {
        echo "<script>
                alert('Se registraron $errores errores al guardar algunas cirugías. Revisa los datos.');
                window.location.href = 'ver_programacion.php';
              </script>";
    }

} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
}
?>