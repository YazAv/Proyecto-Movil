<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['orden_id']) || empty($input['caja_sesion_id']) || empty($input['pagos']) || !is_array($input['pagos'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Parámetros obligatorios: orden_id, caja_sesion_id y un arreglo 'pagos'"]);
    exit();
}

$orden_id = (int)$input['orden_id'];
$caja_sesion_id = (int)$input['caja_sesion_id'];
$lista_pagos = $input['pagos'];

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // 1. Validar que la caja esté abierta
    $stmtCaja = $conn->prepare("SELECT id FROM cajas_sesiones WHERE id = ? AND estado = 'abierta' FOR UPDATE");
    $stmtCaja->execute([$caja_sesion_id]);
    if (!$stmtCaja->fetch()) {
        $conn->rollBack();
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "La sesión de caja no existe o ya fue cerrada."]);
        exit();
    }

    // 2. Validar que la orden exista y esté abierta
    $stmtOrden = $conn->prepare("SELECT id, mesa_id, total, estado FROM ordenes WHERE id = ? FOR UPDATE");
    $stmtOrden->execute([$orden_id]);
    $orden = $stmtOrden->fetch();

    if (!$orden) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Orden no encontrada."]);
        exit();
    }

    if ($orden['estado'] !== 'abierta') {
        $conn->rollBack();
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "La orden no se puede cobrar porque se encuentra en estado '{$orden['estado']}'."]);
        exit();
    }

    // 3. Registrar pagos según la estructura exacta de la tabla pagos
    $total_orden = (float)$orden['total'];
    $suma_liquidada = 0.0;
    $cambio_total = 0.0;

    $stmtInsertPago = $conn->prepare("
        INSERT INTO pagos 
        (orden_id, caja_sesion_id, metodo_pago, monto, monto_recibido, cambio, referencia) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($lista_pagos as $p) {
        $metodo = trim($p['metodo_pago']);
        $monto = (float)$p['monto'];
        $recibido = isset($p['monto_recibido']) ? (float)$p['monto_recibido'] : $monto;
        $referencia = !empty($p['referencia']) ? trim($p['referencia']) : null;
        $cambio = ($metodo === 'efectivo' && $recibido > $monto) ? round($recibido - $monto, 2) : 0.00;

        $stmtInsertPago->execute([$orden_id, $caja_sesion_id, $metodo, $monto, $recibido, $cambio, $referencia]);

        $suma_liquidada += $monto;
        $cambio_total += $cambio;
    }

    if (round($suma_liquidada, 2) < round($total_orden, 2)) {
        $conn->rollBack();
        http_response_code(422);
        echo json_encode([
            "status" => "error",
            "message" => "Monto insuficiente: se abonaron $" . number_format($suma_liquidada, 2) . " de un total de $" . number_format($total_orden, 2)
        ]);
        exit();
    }

    // 4. Cerrar comanda y liberar mesa
    $stmtCerrar = $conn->prepare("UPDATE ordenes SET estado = 'cerrada' WHERE id = ?");
    $stmtCerrar->execute([$orden_id]);

    $stmtMesa = $conn->prepare("UPDATE mesas SET estado = 'disponible' WHERE id = ?");
    $stmtMesa->execute([$orden['mesa_id']]);

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Cuenta cobrada con éxito. Mesa liberada.",
        "data" => [
            "orden_id" => $orden_id,
            "mesa_id" => (int)$orden['mesa_id'],
            "total_orden" => $total_orden,
            "total_pagado" => $suma_liquidada,
            "cambio_devuelto" => $cambio_total,
            "nuevo_estado_mesa" => "disponible"
        ]
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al liquidar orden: " . $e->getMessage()]);
}