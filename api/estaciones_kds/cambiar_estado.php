<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);
$detalle_id   = (int)($input['detalle_id'] ?? 0);
$nuevo_estado = trim($input['estado'] ?? '');

$estados_validos = ['pendiente', 'en_preparacion', 'listo', 'entregado'];

if ($detalle_id <= 0 || !in_array($nuevo_estado, $estados_validos)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Datos de actualización no válidos."]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("UPDATE orden_detalles SET estado_preparacion = :estado WHERE id = :id");
    $stmt->execute([
        ':estado' => $nuevo_estado,
        ':id'     => $detalle_id
    ]);

    http_response_code(200);
    echo json_encode(["status" => "success", "message" => "Estado de ítem actualizado."]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
}