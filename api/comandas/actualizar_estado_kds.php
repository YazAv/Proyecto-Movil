<?php
// api/comandas/actualizar_estado_kds.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/conexion.php';

if (!isset($conn) && class_exists('Database')) {
    $db = new Database();
    $conn = $db->getConnection();
}

try {
    $data = json_decode(file_get_contents("php://input"), true) ?: $_POST;
    
    $id_detalle = intval($data['id_detalle'] ?? 0);
    $nuevo_estado = strtoupper(trim($data['estado'] ?? 'PREPARADO'));

    if ($id_detalle <= 0) {
        throw new Exception("ID de detalle no válido.");
    }

    $stmt = $conn->prepare("UPDATE orden_detalles SET estado_kds = ? WHERE id_detalle = ?");
    $stmt->execute([$nuevo_estado, $id_detalle]);

    echo json_encode(['status' => 'success', 'message' => 'Comanda actualizada']);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>