<?php
// api/estaciones_kds/reabrir_orden.php
header('Content-Type: application/json');

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
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) {
        $data = $_POST;
    }

    $orden_id = intval($data['orden_id'] ?? ($data['id_orden'] ?? 0));
    $detalle_id = intval($data['detalle_id'] ?? ($data['id_detalle'] ?? 0));

    if ($detalle_id > 0) {
        $stmt = $conn->prepare("
            UPDATE orden_detalles 
            SET estado_preparacion = 'pendiente', estado_kds = 'PENDIENTE', actualizado_en = CURRENT_TIMESTAMP 
            WHERE id_detalle = ?
        ");
        $stmt->execute([$detalle_id]);
    } elseif ($orden_id > 0) {
        $stmt = $conn->prepare("
            UPDATE orden_detalles 
            SET estado_preparacion = 'pendiente', estado_kds = 'PENDIENTE', actualizado_en = CURRENT_TIMESTAMP 
            WHERE orden_id = ? AND estado_kds != 'CANCELADO'
        ");
        $stmt->execute([$orden_id]);
    } else {
        throw new Exception("No se especificó la orden o platillo a reabrir.");
    }

    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => 'Comanda devuelta a preparación activa.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>