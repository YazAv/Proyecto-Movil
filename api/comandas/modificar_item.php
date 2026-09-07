<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['orden_detalle_id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El campo 'orden_detalle_id' es obligatorio"]);
    exit();
}

$detalle_id = (int)$input['orden_detalle_id'];
$nuevas_notas = array_key_exists('notas_cocina', $input) ? trim($input['notas_cocina']) : null;
$nueva_cantidad = isset($input['cantidad']) ? (int)$input['cantidad'] : null;

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    $stmt = $conn->prepare("SELECT * FROM orden_detalles WHERE id = ? FOR UPDATE");
    $stmt->execute([$detalle_id]);
    $item = $stmt->fetch();

    if (!$item) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "El ítem de comanda no existe."]);
        exit();
    }

    if ($item['estado_kds'] !== 'pendiente') {
        $conn->rollBack();
        http_response_code(409);
        echo json_encode([
            "status" => "error", 
            "message" => "No se puede editar directamente: el platillo ya está '{$item['estado_kds']}'. Use el flujo de cancelación administrativa."
        ]);
        exit();
    }

    $notas_final = $nuevas_notas !== null ? $nuevas_notas : $item['notas_cocina'];
    $cantidad_final = $nueva_cantidad !== null ? $nueva_cantidad : (int)$item['cantidad'];

    if ($cantidad_final <= 0) {
        $conn->rollBack();
        http_response_code(422);
        echo json_encode(["status" => "error", "message" => "La cantidad debe ser mayor a 0. Para anular use cancelar_item.php."]);
        exit();
    }

    $nuevo_subtotal = round($cantidad_final * (float)$item['precio_unitario'], 2);

    $stmtUpdate = $conn->prepare("
        UPDATE orden_detalles 
        SET notas_cocina = ?, cantidad = ?, subtotal = ? 
        WHERE id = ?
    ");
    $stmtUpdate->execute([$notas_final, $cantidad_final, $nuevo_subtotal, $detalle_id]);

    // Recalcular el total general de la orden
    $stmtRecalc = $conn->prepare("
        UPDATE ordenes 
        SET total = (SELECT COALESCE(SUM(subtotal), 0) FROM orden_detalles WHERE orden_id = ? AND estado_kds != 'cancelado')
        WHERE id = ?
    ");
    $stmtRecalc->execute([$item['orden_id'], $item['orden_id']]);

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Ítem modificado correctamente",
        "data" => [
            "orden_detalle_id" => $detalle_id,
            "cantidad" => $cantidad_final,
            "subtotal" => $nuevo_subtotal,
            "notas_cocina" => $notas_final
        ]
    ]);
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al actualizar ítem: " . $e->getMessage()]);
}