<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['detalle_id']) || (!isset($input['nueva_cantidad']) && !isset($input['nuevas_notas']))) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Se requiere 'detalle_id' y al menos 'nueva_cantidad' o 'nuevas_notas'"]);
    exit();
}

$detalle_id = (int)$input['detalle_id'];
$nueva_cantidad = isset($input['nueva_cantidad']) ? (int)$input['nueva_cantidad'] : null;
$nuevas_notas = isset($input['nuevas_notas']) ? trim($input['nuevas_notas']) : null;

if ($nueva_cantidad !== null && $nueva_cantidad <= 0) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "La cantidad debe ser mayor a 0 (para eliminar use cancelar_item)"]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // 1. Obtener y bloquear el ítem para actualización
    $stmtItem = $conn->prepare("
        SELECT id, orden_id, precio_unitario, subtotal, estado_kds, notas_cocina 
        FROM orden_detalles 
        WHERE id = ? 
        FOR UPDATE
    ");
    $stmtItem->execute([$detalle_id]);
    $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Artículo no encontrado"]);
        exit();
    }

    if ($item['estado_kds'] === 'cancelado') {
        $conn->rollBack();
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "No se puede modificar un artículo cancelado"]);
        exit();
    }

    // 2. Verificar que la orden principal siga abierta
    $stmtOrden = $conn->prepare("SELECT id, estado FROM ordenes WHERE id = ? FOR UPDATE");
    $stmtOrden->execute([$item['orden_id']]);
    $orden = $stmtOrden->fetch(PDO::FETCH_ASSOC);

    if (!$orden || $orden['estado'] !== 'abierta') {
        $conn->rollBack();
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "La orden no se encuentra abierta"]);
        exit();
    }

    // 3. Recalcular subtotal e integrar notas
    $precio_unitario = (float)$item['precio_unitario'];
    $nuevo_subtotal = $nueva_cantidad !== null ? round($precio_unitario * $nueva_cantidad, 2) : (float)$item['subtotal'];
    $notas_finales = $nuevas_notas !== null ? $nuevas_notas : $item['notas_cocina'];

    $stmtUpdateItem = $conn->prepare("
        UPDATE orden_detalles 
        SET cantidad = COALESCE(?, cantidad),
            subtotal = ?,
            notas_cocina = ?
        WHERE id = ?
    ");
    $stmtUpdateItem->execute([$nueva_cantidad, $nuevo_subtotal, $notas_finales, $detalle_id]);

    // 4. Recalcular de forma atómica el total de la orden excluyendo ítems cancelados
    $stmtRecalcular = $conn->prepare("
        SELECT COALESCE(SUM(subtotal), 0) AS total_calculado 
        FROM orden_detalles 
        WHERE orden_id = ? AND estado_kds != 'cancelado'
    ");
    $stmtRecalcular->execute([$item['orden_id']]);
    $total_orden = (float)$stmtRecalcular->fetchColumn();

    $stmtUpdateTotal = $conn->prepare("UPDATE ordenes SET total = ? WHERE id = ?");
    $stmtUpdateTotal->execute([$total_orden, $item['orden_id']]);

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Artículo actualizado exitosamente",
        "data" => [
            "detalle_id" => $detalle_id,
            "orden_id" => (int)$item['orden_id'],
            "nueva_cantidad" => $nueva_cantidad,
            "nuevo_subtotal" => $nuevo_subtotal,
            "nuevo_total_orden" => $total_orden,
            "notas_cocina" => $notas_finales
        ]
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al modificar artículo: " . $e->getMessage()]);
}