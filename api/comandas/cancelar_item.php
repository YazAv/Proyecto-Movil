<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['detalle_id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El parámetro 'detalle_id' es obligatorio"]);
    exit();
}

$detalle_id = (int)$input['detalle_id'];
$motivo = !empty($input['motivo']) ? trim($input['motivo']) : 'Cancelado por el usuario';

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // 1. Obtener el detalle del producto con bloqueo pesimista
    $stmtItem = $conn->prepare("
        SELECT id, orden_id, subtotal, estado_kds 
        FROM orden_detalles 
        WHERE id = ? 
        FOR UPDATE
    ");
    $stmtItem->execute([$detalle_id]);
    $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "El artículo solicitado no existe"]);
        exit();
    }

    if ($item['estado_kds'] === 'cancelado') {
        $conn->rollBack();
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "Este artículo ya se encuentra cancelado"]);
        exit();
    }

    // 2. Validar que la orden siga abierta
    $stmtOrden = $conn->prepare("SELECT id, estado, total FROM ordenes WHERE id = ? FOR UPDATE");
    $stmtOrden->execute([$item['orden_id']]);
    $orden = $stmtOrden->fetch(PDO::FETCH_ASSOC);

    if (!$orden || $orden['estado'] !== 'abierta') {
        $conn->rollBack();
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "No se puede cancelar el artículo porque la orden no está abierta"]);
        exit();
    }

    // 3. Marcar el artículo como 'cancelado' y anexar el motivo en notas_cocina
    $stmtUpdateItem = $conn->prepare("
        UPDATE orden_detalles 
        SET estado_kds = 'cancelado',
            notas_cocina = CONCAT(COALESCE(notas_cocina, ''), ' [CANCELADO: ', ?, ']')
        WHERE id = ?
    ");
    $stmtUpdateItem->execute([$motivo, $detalle_id]);

    // 4. Recalcular el total de la orden sumando solo los productos activos
    $stmtRecalcular = $conn->prepare("
        SELECT COALESCE(SUM(subtotal), 0) AS nuevo_total 
        FROM orden_detalles 
        WHERE orden_id = ? AND estado_kds != 'cancelado'
    ");
    $stmtRecalcular->execute([$item['orden_id']]);
    $nuevo_total = (float)$stmtRecalcular->fetchColumn();

    // 5. Actualizar el total general en la orden
    $stmtUpdateOrden = $conn->prepare("UPDATE ordenes SET total = ? WHERE id = ?");
    $stmtUpdateOrden->execute([$nuevo_total, $item['orden_id']]);

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Artículo cancelado y total recalculado exitosamente",
        "data" => [
            "detalle_id" => $detalle_id,
            "orden_id" => (int)$item['orden_id'],
            "subtotal_descontado" => (float)$item['subtotal'],
            "nuevo_total_orden" => $nuevo_total,
            "motivo" => $motivo
        ]
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al cancelar artículo: " . $e->getMessage()]);
}