<?php
// api/asistencia/registrar.php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');

// 1. Conexión a Base de Datos
if (file_exists(__DIR__ . '/../../config/conexion.php')) {
    require_once __DIR__ . '/../../config/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
} elseif (file_exists(__DIR__ . '/../../conexion.php')) {
    require_once __DIR__ . '/../../conexion.php';
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

    // Recibir código desde cualquier parámetro
    $codigo = trim($data['codigo_qr'] ?? ($data['qr'] ?? ($data['codigo'] ?? ($data['token'] ?? ($data['pin'] ?? '')))));
    $tipo   = strtoupper(trim($data['tipo'] ?? 'ENTRADA'));
    if (!in_array($tipo, ['ENTRADA', 'SALIDA'])) {
        $tipo = 'ENTRADA';
    }
    $metodo = strtolower(trim($data['metodo'] ?? 'qr'));

    if ($codigo === '') {
        throw new Exception("No se recibió ningún código QR o PIN.");
    }

    // Si el QR contiene una URL o texto como "EMP-7", extraemos también el número ID
    $idExtraido = -1;
    if (preg_match('/EMP-(\d+)/i', $codigo, $m)) {
        $idExtraido = intval($m[1]);
    } elseif (ctype_digit($codigo) && strlen($codigo) <= 3) {
        // Si es un número corto (ej. 5 o 7), puede ser el ID directo del empleado
        $idExtraido = intval($codigo);
    }

    // Asegurar que todos los empleados tengan codigo_qr asignado en la BD
    $conn->exec("UPDATE empleados SET codigo_qr = 'EMP-' || id_empleado WHERE codigo_qr IS NULL OR codigo_qr = ''");


// Buscar empleado EXCLUSIVAMENTE por su Código QR asignado
    $stmtEmp = $conn->prepare("
        SELECT e.id_empleado, e.nombre, e.apellido, e.puesto, 
               COALESCE(e.salario_por_hora, 0) AS salario_por_hora, 
               e.codigo_qr,
               u.id AS usuario_id
        FROM empleados e
        LEFT JOIN usuarios u ON u.empleado_id = e.id_empleado
        WHERE COALESCE(e.activo, true) = true
          AND (
              UPPER(TRIM(COALESCE(e.codigo_qr, ''))) = UPPER(:cod)
              OR UPPER(TRIM(COALESCE(u.token_qr, ''))) = UPPER(:cod)
              OR e.id_empleado = :id_ext
          )
        ORDER BY e.id_empleado ASC
        LIMIT 1
    ");
    $stmtEmp->execute([
        ':cod'    => $codigo,
        ':id_ext' => $idExtraido
    ]);
    $empleado = $stmtEmp->fetch(PDO::FETCH_ASSOC);

    if (!$empleado) {
        throw new Exception("Código o PIN no reconocido en el sistema ($codigo).");
    }

    $id_empleado = intval($empleado['id_empleado']);
    $usuario_id  = !empty($empleado['usuario_id']) ? intval($empleado['usuario_id']) : null;
    $nombreCompleto = trim($empleado['nombre'] . ' ' . ($empleado['apellido'] !== '-' ? $empleado['apellido'] : ''));

    // Sincronizar secuencias de PostgreSQL para que NUNCA falle el INSERT por llave duplicada
    $conn->exec("SELECT setval('asistencias_id_seq', COALESCE((SELECT MAX(id) FROM asistencias), 1), true)");
    $conn->exec("SELECT setval('registros_asistencia_id_asistencia_seq', COALESCE((SELECT MAX(id_asistencia) FROM registros_asistencia), 1), true)");

    $conn->beginTransaction();

    // 1. Guardar en tabla 'asistencias'
    $stmtAsis = $conn->prepare("
        INSERT INTO asistencias (usuario_id, id_empleado, tipo, fecha_hora, metodo, fecha, hora)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, CURRENT_DATE, CURRENT_TIME)
    ");
    $stmtAsis->execute([$usuario_id, $id_empleado, $tipo, $metodo]);

    // 2. Guardar / Actualizar en 'registros_asistencia' (Para Nómina)
    $horas_trabajadas = 0.00;
    if ($tipo === 'ENTRADA') {
        $stmtReg = $conn->prepare("
            INSERT INTO registros_asistencia (id_empleado, fecha, hora_entrada, estado)
            VALUES (?, CURRENT_DATE, CURRENT_TIMESTAMP, 'En Turno')
        ");
        $stmtReg->execute([$id_empleado]);
    } else {
        $stmtAbierta = $conn->prepare("
            SELECT id_asistencia 
            FROM registros_asistencia 
            WHERE id_empleado = ? AND hora_salida IS NULL 
            ORDER BY hora_entrada DESC LIMIT 1
        ");
        $stmtAbierta->execute([$id_empleado]);
        $id_asistencia_abierta = $stmtAbierta->fetchColumn();

        if ($id_asistencia_abierta) {
            $stmtCerrar = $conn->prepare("
                UPDATE registros_asistencia 
                SET hora_salida = CURRENT_TIMESTAMP,
                    horas_trabajadas = GREATEST(0.01, ROUND(CAST(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - hora_entrada)) / 3600.0 AS numeric), 2)),
                    estado = 'Completado'
                WHERE id_asistencia = ?
                RETURNING horas_trabajadas
            ");
            $stmtCerrar->execute([$id_asistencia_abierta]);
            $horas_trabajadas = floatval($stmtCerrar->fetchColumn());
        } else {
            $stmtReg = $conn->prepare("
                INSERT INTO registros_asistencia (id_empleado, fecha, hora_entrada, hora_salida, horas_trabajadas, estado)
                VALUES (?, CURRENT_DATE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 0.00, 'Salida Directa')
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
        'horas_trabajadas' => $horas_trabajadas,
        'message'          => "¡{$tipo} registrada para {$nombreCompleto}!"
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode([
        'status'  => 'error',
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>