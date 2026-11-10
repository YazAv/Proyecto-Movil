<?php
// api/asistencia/registrar.php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');

if (file_exists(__DIR__ . '/../../config/conexion.php')) {
    require_once __DIR__ . '/../../config/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
}

if (!isset($conn) && class_exists('Database')) {
    $db = new Database();
    $conn = $db->getConnection();
}

try {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data)) {
        $data = $_POST;
    }

    $codigo = trim($data['codigo_qr'] ?? ($data['qr'] ?? ($data['codigo'] ?? ($data['token'] ?? ($data['pin'] ?? '')))));
    $tipo   = strtoupper(trim($data['tipo'] ?? 'ENTRADA')); 
    $metodo = strtolower(trim($data['metodo'] ?? 'qr'));

    if (empty($codigo)) {
        throw new Exception("No se recibió ningún código QR válido.");
    }

    $idExtraido = -1;
    if (preg_match('/EMP-(\d+)/i', $codigo, $matches)) {
        $idExtraido = intval($matches[1]);
    } elseif (is_numeric($codigo)) {
        $idExtraido = intval($codigo);
    }

    // Sincronizar QR vacío por seguridad
    $conn->exec("UPDATE empleados SET codigo_qr = 'EMP-' || id_empleado WHERE codigo_qr IS NULL OR codigo_qr = ''");

    // BUSCAMOS SOLAMENTE EN LA TABLA EMPLEADOS (Evita cruces con tabla usuarios)
    $stmtEmp = $conn->prepare("
        SELECT id_empleado, nombre, apellido, puesto, salario_por_hora 
        FROM empleados 
        WHERE activo = true 
          AND (
              UPPER(codigo_qr) = UPPER(:cod) 
              OR pin_acceso = :cod 
              OR id_empleado = :id_ext
          )
        LIMIT 1
    ");
    
    $stmtEmp->execute([
        ':cod'    => $codigo,
        ':id_ext' => $idExtraido
    ]);
    
    $empleado = $stmtEmp->fetch(PDO::FETCH_ASSOC);

    if (!$empleado) {
        throw new Exception("Código QR no reconocido o empleado inactivo ($codigo).");
    }

    $id_empleado = intval($empleado['id_empleado']);
    $nombreCompleto = trim($empleado['nombre'] . ' ' . ($empleado['apellido'] !== '-' ? $empleado['apellido'] : ''));

    $conn->beginTransaction();

    // 1. Insertar en la tabla 'asistencias' usando ÚNICAMENTE el id_empleado
   $stmtAsis = $conn->prepare("
        INSERT INTO asistencias (usuario_id, id_empleado, tipo, fecha_hora, metodo, fecha, hora)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, CURRENT_DATE, CURRENT_TIME)
    ");
    $stmtAsis->execute([$id_empleado, $tipo, $metodo]);

    // 2. Registro para Nómina (registros_asistencia)
    $horas_trabajadas = 0.00;
    if ($tipo === 'ENTRADA') {
        $stmtReg = $conn->prepare("
            INSERT INTO registros_asistencia (id_empleado, fecha, hora_entrada, estado)
            VALUES (?, CURRENT_DATE, CURRENT_TIMESTAMP, 'En Turno')
        ");
        $stmtReg->execute([$id_empleado]);
    } else {
        $stmtAbierta = $conn->prepare("
            SELECT id_asistencia, hora_entrada 
            FROM registros_asistencia 
            WHERE id_empleado = ? AND hora_salida IS NULL 
            ORDER BY hora_entrada DESC LIMIT 1
        ");
        $stmtAbierta->execute([$id_empleado]);
        $turnoAbierto = $stmtAbierta->fetch(PDO::FETCH_ASSOC);

        if ($turnoAbierto) {
            $stmtCerrar = $conn->prepare("
                UPDATE registros_asistencia 
                SET hora_salida = CURRENT_TIMESTAMP,
                    horas_trabajadas = ROUND(CAST(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - hora_entrada)) / 3600.0 AS numeric), 2),
                    estado = 'Completado'
                WHERE id_asistencia = ?
                RETURNING horas_trabajadas
            ");
            $stmtCerrar->execute([$turnoAbierto['id_asistencia']]);
            $horas_trabajadas = floatval($stmtCerrar->fetchColumn());
        } else {
            $stmtReg = $conn->prepare("
                INSERT INTO registros_asistencia (id_empleado, fecha, hora_entrada, hora_salida, horas_trabajadas, estado)
                VALUES (?, CURRENT_DATE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 0.00, 'Salida sin entrada')
            ");
            $stmtReg->execute([$id_empleado]);
        }
    }

    $conn->commit();

    echo json_encode([
        'status'           => 'success',
        'success'          => true,
        'tipo'             => $tipo,
        'id_empleado'      => $id_empleado,
        'empleado'         => $nombreCompleto,
        'nombre'           => $nombreCompleto,
        'puesto'           => $empleado['puesto'],
        'hora'             => date('H:i:s'),
        'horas_trabajadas' => $horas_trabajadas
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(400);
    echo json_encode(['status' => 'error', 'success' => false, 'message' => $e->getMessage()]);
}
?>