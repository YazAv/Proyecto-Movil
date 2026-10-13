<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);
$orden_id = (int)($input['orden_id'] ?? 0);
$estacion = trim($input['estacion'] ?? 'cocina');

if ($orden_id <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ID de orden no válido."]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    // Marcar como entregados todos los items de esa orden pertenecientes a la estación elegida
    $stmt = $conn->prepare("
        UPDATE orden_detalles od
        SET estado_preparacion = 'entregado'
        FROM productos p
        JOIN categorias c ON c.id = p.categoria_id
        WHERE od.producto_id = p.id
          AND od.orden_id = :orden_id
          AND LOWER(c.estacion) = :estacion
    ");

    $stmt->execute([
        ':orden_id' => $orden_id,
        ':estacion' => strtolower($estacion)
    ]);

    http_response_code(200);
    echo json_encode(["status" => "success", "message" => "Comanda despachada por completo."]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
}