<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once '../../config/conexion.php';

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    echo json_encode(["status" => "error", "message" => "Datos inválidos"]);
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    $conn->beginTransaction();

    $id_orden = isset($data['id_orden']) ? intval($data['id_orden']) : null;
    $total = floatval($data['total']);
    $productos = $data['productos'] ?? [];

    if ($id_orden) {
        // Actualizar orden de mesa existente a PAGADA y liberar mesa
        $stmt = $conn->prepare("UPDATE ordenes SET estado = 'PAGADA', total = :total, cerrado_en = CURRENT_TIMESTAMP WHERE id_orden = :id_orden");
        $stmt->execute([':total' => $total, ':id_orden' => $id_orden]);

        $stmtMesa = $conn->prepare("UPDATE mesas SET estado = 'disponible' WHERE id_mesa = (SELECT id_mesa FROM ordenes WHERE id_orden = :id_orden)");
        $stmtMesa->execute([':id_orden' => $id_orden]);
    } else {
        // Venta rápida general: Folio corto de máximo 15 caracteres (Ej: V-1006123045-89)
        $folio = 'V-' . date('mdHis') . '-' . mt_rand(10, 99);
        
        $stmt = $conn->prepare("INSERT INTO ordenes (folio, id_usuario, subtotal, total, estado, creado_en, cerrado_en) VALUES (:folio, 1, :subtotal, :total, 'PAGADA', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) RETURNING id_orden");
        $stmt->execute([':folio' => $folio, ':subtotal' => $total, ':total' => $total]);
        $id_orden = $stmt->fetchColumn();

        foreach ($productos as $prod) {
            $stmtDet = $conn->prepare("INSERT INTO detalle_orden (orden_id, producto_id, nombre_producto, cantidad, precio_unitario, subtotal) VALUES (:orden_id, :producto_id, :nombre_producto, :cantidad, :precio_unitario, :subtotal)");
            $stmtDet->execute([
                ':orden_id' => $id_orden,
                ':producto_id' => $prod['id'],
                ':nombre_producto' => $prod['nombre'],
                ':cantidad' => $prod['cantidad'],
                ':precio_unitario' => $prod['precio'],
                ':subtotal' => $prod['precio'] * $prod['cantidad']
            ]);
        }
    }

    $conn->commit();
    echo json_encode(["status" => "success", "message" => "Cobro realizado con éxito"]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>