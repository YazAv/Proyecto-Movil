<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (
    !isset($input['orden_id']) || 
    !isset($input['metodo_pago']) || 
    !isset($input['monto_recibido'])
) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios (orden_id, metodo_pago, monto_recibido)"]);
    exit();
}

$orden_id = (int)$input['orden_id'];
$metodo_pago = trim($input['metodo_pago']);
$monto_recibido = (float)$input['monto_recibido'];
$propina = isset($input['propina']) ? max(0, (float)$input['propina']) : 0.00;
$referencia = !empty($input['referencia']) ? trim($input['referencia']) : null;

// Porcentaje o monto fijo de descuento
$descuento_porcentaje = isset($input['descuento_porcentaje']) ? max(0, min(100, (float)$input['descuento_porcentaje'])) : null;
$descuento_fijo = isset($input['descuento']) ? max(0, (float)$input['descuento']) : 0.00;

$metodos_validos = ['efectivo', 'tarjeta_debito', 'tarjeta_credito', 'transferencia'];
if (!in_array($metodo_pago, $metodos_validos)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Método de pago no válido"]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // 1. Obtener la orden activa
    $stmtOrden = $conn->prepare("SELECT id, mesa_id, subtotal, total, estado FROM ordenes WHERE id = ? FOR UPDATE");
    $stmtOrden->execute([$orden_id]);
    $orden = $stmtOrden->fetch();

    if (!$orden) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "La orden no existe."]);
        exit();
    }
    if ($orden['estado'] === 'pagada') {
        $conn->rollBack();
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "Esta orden ya fue liquidada previamente."]);
        exit();
    }

    // Asegurar lectura numérica del subtotal
    $subtotal = isset($orden['subtotal']) ? (float)$orden['subtotal'] : 0.00;

    // Si por alguna razón la comanda guardó subtotal en 0, calcularlo en vivo desde los detalles
    if ($subtotal <= 0) {
        $stmtSub = $conn->prepare("SELECT COALESCE(SUM(subtotal), 0) AS subtotal_calculado FROM orden_detalles WHERE orden_id = ?");
        $stmtSub->execute([$orden_id]);
        $subtotal = (float)$stmtSub->fetch()['subtotal_calculado'];
    }

    // 2. Calcular descuento
    if ($descuento_porcentaje !== null && $descuento_porcentaje > 0) {
        $descuento_final = round(($subtotal * ($descuento_porcentaje / 100)), 2);
    } else {
        $descuento_final = min($subtotal, $descuento_fijo);
    }

    // 3. Totales
    $base_a_pagar = max(0, $subtotal - $descuento_final);
    $total_a_pagar = $base_a_pagar + $propina;

    if ($monto_recibido < $total_a_pagar && $metodo_pago === 'efectivo') {
        $conn->rollBack();
        http_response_code(400);
        echo json_encode([
            "status" => "error", 
            "message" => "El monto recibido ($" . number_format($monto_recibido, 2) . ") es menor al total ($" . number_format($total_a_pagar, 2) . ")."
        ]);
        exit();
    }

    $cambio = ($metodo_pago === 'efectivo') ? max(0, $monto_recibido - $total_a_pagar) : 0.00;

    // 4. Registrar pago
    $stmtPago = $conn->prepare("
        INSERT INTO pagos (orden_id, metodo_pago, monto, monto_recibido, cambio, referencia) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmtPago->execute([
        $orden_id,
        $metodo_pago,
        $total_a_pagar,
        $monto_recibido,
        $cambio,
        $referencia
    ]);

    // 5. Marcar orden pagada
    $stmtUpdateOrden = $conn->prepare("
        UPDATE ordenes 
        SET estado = 'pagada', 
            subtotal = ?,
            descuento = ?, 
            propina = ?, 
            total = ? 
        WHERE id = ?
    ");
    $stmtUpdateOrden->execute([$subtotal, $descuento_final, $propina, $total_a_pagar, $orden_id]);

    // 6. Liberar mesa
    if (!empty($orden['mesa_id'])) {
        $stmtMesa = $conn->prepare("UPDATE mesas SET estado = 'disponible' WHERE id = ?");
        $stmtMesa->execute([$orden['mesa_id']]);
    }

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Cuenta cobrada y mesa liberada con éxito",
        "data" => [
            "orden_id" => $orden_id,
            "subtotal" => $subtotal,
            "descuento_aplicado" => $descuento_final,
            "propina" => $propina,
            "total_pagado" => $total_a_pagar,
            "monto_recibido" => $monto_recibido,
            "cambio" => $cambio,
            "mesa_liberada" => $orden['mesa_id']
        ]
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}