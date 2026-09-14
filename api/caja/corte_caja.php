<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido. Utiliza POST."]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['caja_sesion_id']) || !isset($input['monto_final_real'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios (caja_sesion_id, monto_final_real)"]);
    exit();
}

$turno_id = (int)$input['caja_sesion_id'];
$efectivo_declarado = (float)$input['monto_final_real'];

$db = new Database();
$conn = $db->getConnection();

try {
    // 1. Obtener información del turno
    $stmtTurno = $conn->prepare("
        SELECT * FROM turnos_caja 
        WHERE id_turno = :turno_id
    ");
    $stmtTurno->execute([':turno_id' => $turno_id]);
    $turno = $stmtTurno->fetch(PDO::FETCH_ASSOC);

    if (!$turno) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Turno de caja no encontrado"]);
        exit();
    }

    if ($turno['estado'] === 'cerrado') {
        http_response_code(422);
        echo json_encode(["status" => "error", "message" => "Este turno de caja ya se encuentra cerrado"]);
        exit();
    }

    // 2. Identificar ID de usuario asignado al turno
    $usuario_id = (int)($turno['id_usuario'] ?? $turno['usuario_id'] ?? 0);

    // 3. Buscar el empleado usando u.id
    $stmtEmpleado = $conn->prepare("
        SELECT 
            e.id_empleado,
            CONCAT(e.nombre, ' ', e.apellido) AS empleado_nombre,
            e.puesto AS empleado_puesto
        FROM usuarios u
        INNER JOIN empleados e ON u.empleado_id = e.id_empleado
        WHERE u.id = :uid
    ");
    $stmtEmpleado->execute([':uid' => $usuario_id]);
    $empleado = $stmtEmpleado->fetch(PDO::FETCH_ASSOC);

    // 4. Sumar pagos registrados en el turno
    $stmtVentas = $conn->prepare("
        SELECT 
            metodo_pago,
            SUM(monto) AS total_metodo
        FROM pagos
        WHERE caja_sesion_id = :turno_id OR id_turno = :turno_id
        GROUP BY metodo_pago
    ");
    $stmtVentas->execute([':turno_id' => $turno_id]);
    $ventas_metodos = $stmtVentas->fetchAll(PDO::FETCH_KEY_PAIR);

    $ventas_efectivo = (float)($ventas_metodos['efectivo'] ?? 0.00);
    $ventas_tarjeta  = (float)($ventas_metodos['tarjeta'] ?? 0.00);
    $total_ventas    = $ventas_efectivo + $ventas_tarjeta;

    // 5. Balance financiero
    $fondo_inicial     = (float)$turno['fondo_inicial'];
    $efectivo_esperado = $fondo_inicial + $ventas_efectivo;
    $diferencia        = $efectivo_declarado - $efectivo_esperado;

    // 6. Registrar cierre
    $stmtCierre = $conn->prepare("
        UPDATE turnos_caja 
        SET fecha_cierre = NOW(),
            efectivo_declarado = :efectivo_declarado,
            diferencia = :diferencia,
            estado = 'cerrado'
        WHERE id_turno = :turno_id
    ");
    $stmtCierre->execute([
        ':efectivo_declarado' => $efectivo_declarado,
        ':diferencia'         => $diferencia,
        ':turno_id'           => $turno_id
    ]);

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Cierre de turno realizado correctamente",
        "resumen_corte" => [
            "turno_id"           => $turno_id,
            "empleado"           => [
                "id_empleado" => (int)($empleado['id_empleado'] ?? 0),
                "nombre"      => $empleado['empleado_nombre'] ?? "Sin Empleado Asignado",
                "puesto"      => $empleado['empleado_puesto'] ?? "Cajero",
                "usuario_id"  => $usuario_id
            ],
            "fondo_inicial"      => $fondo_inicial,
            "ventas_efectivo"    => $ventas_efectivo,
            "ventas_tarjeta"     => $ventas_tarjeta,
            "total_ventas"       => $total_ventas,
            "efectivo_esperado"  => $efectivo_esperado,
            "efectivo_declarado" => $efectivo_declarado,
            "diferencia"         => $diferencia,
            "resultado_balance"  => ($diferencia === 0.0) ? "Caja Cuadrada" : (($diferencia > 0) ? "Sobrante" : "Faltante")
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
}