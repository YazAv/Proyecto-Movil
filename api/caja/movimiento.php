<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['caja_sesion_id']) || empty($input['usuario_id']) || empty($input['tipo']) || !isset($input['monto']) || empty($input['concepto'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Todos los campos son obligatorios: caja_sesion_id, usuario_id, tipo, monto, concepto"]);
    exit();
}

$caja_sesion_id = (int)$input['caja_sesion_id'];
$usuario_id = (int)$input['usuario_id'];
$tipo = strtolower(trim($input['tipo']));
$monto = (float)$input['monto'];
$concepto = trim($input['concepto']);

if (!in_array($tipo, ['entrada', 'salida'], true) || $monto <= 0) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "El 'tipo' debe ser 'entrada' o 'salida' y el 'monto' mayor a 0"]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // 1. Validar que la caja exista y esté abierta
    $stmtCaja = $conn->prepare("SELECT id, monto_inicial FROM cajas_sesiones WHERE id = ? AND estado = 'abierta' FOR UPDATE");
    $stmtCaja->execute([$caja_sesion_id]);
    $caja = $stmtCaja->fetch(PDO::FETCH_ASSOC);

    if (!$caja) {
        $conn->rollBack();
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "La sesión de caja no existe o ya fue cerrada."]);
        exit();
    }

    // 2. Si es una salida, validar que haya suficiente efectivo en caja
    if ($tipo === 'salida') {
        // Efectivo acumulado por cobros de comanda
        $stmtVentas = $conn->prepare("SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE caja_sesion_id = ? AND metodo_pago = 'efectivo'");
        $stmtVentas->execute([$caja_sesion_id]);
        $ventas_efectivo = (float)$stmtVentas->fetchColumn();

        // Entradas y salidas previas
        $stmtMovs = $conn->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN monto ELSE 0 END), 0) AS total_entradas,
                COALESCE(SUM(CASE WHEN tipo = 'salida' THEN monto ELSE 0 END), 0) AS total_salidas
            FROM movimientos_caja 
            WHERE caja_sesion_id = ?
        ");
        $stmtMovs->execute([$caja_sesion_id]);
        $movs = $stmtMovs->fetch(PDO::FETCH_ASSOC);

        $efectivo_disponible = (float)$caja['monto_inicial'] + $ventas_efectivo + (float)$movs['total_entradas'] - (float)$movs['total_salidas'];

        if ($monto > $efectivo_disponible) {
            $conn->rollBack();
            http_response_code(422);
            echo json_encode([
                "status" => "error",
                "message" => "Fondos insuficientes en caja. Efectivo disponible: $" . number_format($efectivo_disponible, 2)
            ]);
            exit();
        }
    }

    // 3. Registrar el movimiento en la tabla movimientos_caja
    $stmtInsert = $conn->prepare("
        INSERT INTO movimientos_caja (caja_sesion_id, usuario_id, tipo, monto, concepto, fecha) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmtInsert->execute([$caja_sesion_id, $usuario_id, $tipo, $monto, $concepto]);
    $movimiento_id = (int)$conn->lastInsertId();

    $conn->commit();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "Movimiento de $tipo registrado correctamente",
        "data" => [
            "id" => $movimiento_id,
            "caja_sesion_id" => $caja_sesion_id,
            "usuario_id" => $usuario_id,
            "tipo" => $tipo,
            "monto" => $monto,
            "concepto" => $concepto
        ]
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al registrar movimiento: " . $e->getMessage()]);
}