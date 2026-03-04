<?php
// Conexión a la base de datos
$host = 'localhost';
$dbname = 'sitiosnuevos_hospital';
$username = 'sitiosnuevos_cirtugia';
$password = 'Realmedic2020';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Obtener los datos del formulario
    $dia = $_POST['dia'];
    $fecha = $_POST['fecha'];
    $paciente = $_POST['paciente'];
    $edad = $_POST['edad'];
    $h_ingreso = $_POST['h_ingreso'];
    $h_cirugia = !empty($_POST['h_cirugia']) ? $_POST['h_cirugia'] : null;
    $procedimiento = $_POST['procedimiento'];
    $tipo_cirugia = $_POST['tipo_cirugia'];
    $anestesiologo = $_POST['anestesiologo'];
    $habitacion = $_POST['habitacion'];
    $casa_comercial = $_POST['casa_comercial'];
    $mesa_traccion = $_POST['mesa_traccion'];
    $laboratorio = $_POST['laboratorio'];
    $arco_en_c = $_POST['arco_en_c'];
    $es_protesis = isset($_POST['protesis']) ? 1 : 0;

    // Determinar cuál quirófano está seleccionado
    $Q1 = ($_POST['quirófano'] === 'Q1') ? 'X' : '';
    $Q2 = ($_POST['quirófano'] === 'Q2') ? 'X' : '';

    // Obtener el cirujano correctamente
    if (!empty($_POST['nuevo_cirujano'])) {
        // Si ingresó un nuevo cirujano, lo guardamos en la base
        $cirujano = $_POST['nuevo_cirujano'];

        $stmtInsertCirujano = $pdo->prepare("INSERT INTO cirujanos (nombre, especialidad) VALUES (:nombre, 'General')");
        $stmtInsertCirujano->bindParam(':nombre', $cirujano);
        $stmtInsertCirujano->execute();

        // Obtener el ID recién insertado
        $cirujanoId = $pdo->lastInsertId();
    } else {
        // Si seleccionó un cirujano de la lista, usar su ID directamente
        $cirujanoId = $_POST['cirujano'];
    }

    // Insertar la programación de la cirugía
    $sql = "INSERT INTO programacion_quirofano (dia, fecha, paciente, edad, h_ingreso, h_cirugia, procedimiento, tipo_cirugia_id, Q1, Q2, cirujano_id, anestesiologo, habitacion, casa_comercial, mesa_traccion, laboratorio, arco_en_c, es_protesis)
            VALUES (:dia, :fecha, :paciente, :edad, :h_ingreso, :h_cirugia, :procedimiento, :tipo_cirugia, :Q1, :Q2, :cirujano_id, :anestesiologo, :habitacion, :casa_comercial, :mesa_traccion, :laboratorio, :arco_en_c, :es_protesis)";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':dia', $dia);
    $stmt->bindParam(':fecha', $fecha);
    $stmt->bindParam(':paciente', $paciente);
    $stmt->bindParam(':edad', $edad);
    $stmt->bindParam(':h_ingreso', $h_ingreso);
    $stmt->bindParam(':h_cirugia', $h_cirugia, PDO::PARAM_STR);
    $stmt->bindParam(':procedimiento', $procedimiento);
    $stmt->bindParam(':tipo_cirugia', $tipo_cirugia);
    $stmt->bindParam(':Q1', $Q1);
    $stmt->bindParam(':Q2', $Q2);
    $stmt->bindParam(':cirujano_id', $cirujanoId); // Ahora siempre se guarda el ID correcto
    $stmt->bindParam(':anestesiologo', $anestesiologo);
    $stmt->bindParam(':habitacion', $habitacion);
    $stmt->bindParam(':casa_comercial', $casa_comercial);
    $stmt->bindParam(':mesa_traccion', $mesa_traccion);
    $stmt->bindParam(':laboratorio', $laboratorio);
    $stmt->bindParam(':arco_en_c', $arco_en_c);
    $stmt->bindParam(':es_protesis', $es_protesis, PDO::PARAM_INT);

   if ($stmt->execute()) {
        $newId = (int)$pdo->lastInsertId();

        // ---- Enviar notificación por correo ----
        require_once __DIR__.'/mailer_notify.php';
        try {
            sendSurgeryNotification($pdo, $newId);
        } catch (Throwable $e) {
            // No bloquear el flujo si falla el correo
            error_log('[Email notify error] '.$e->getMessage());
        }

        // Redirige a la vista
        echo "<script>alert('Cirugía agendada exitosamente.'); window.location.href='ver_programacion.php';</script>";
        exit;
    } else {
        echo "<div class='alert alert-danger'>Error al guardar la programación.</div>";
    }
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Error: ".$e->getMessage()."</div>";
}

// ---------- FIN ----------

?>