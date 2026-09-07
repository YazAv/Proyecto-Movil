<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Falta el id de la mesa"]);
    exit();
}

$id = (int)$input['id'];

$db = new Database();
$conn = $db->getConnection();

try {
    // 1. Validar si la mesa está actualmente ocupada
    $stmtMesa = $conn->prepare("SELECT estado FROM mesas WHERE id = ?");
    $stmtMesa->execute([$id]);
    $mesa = $stmtMesa->fetch();

    if (!$mesa) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Mesa no encontrada"]);
        exit();
    }

    if ($mesa['estado'] === 'ocupada') {
        http_response_code(409);
        echo json_encode([
            "status" => "error", 
            "message" => "No se puede eliminar una mesa ocupada con servicio en curso."
        ]);
        exit();
    }

    // 2. Validar que no tenga órdenes activas sin pagar
    $stmtOrdenes = $conn->prepare("
        SELECT COUNT(*) AS total 
        FROM ordenes 
        WHERE mesa_id = ? AND estado NOT IN ('pagada', 'cancelada')
    ");
    $stmtOrdenes->execute([$id]);
    $activas = (int)$stmtOrdenes->fetch()['total'];

    if ($activas > 0) {
        http_response_code(409);
        echo json_encode([
            "status" => "error",
            "message" => "No se puede eliminar la mesa porque tiene órdenes pendientes de cobro."
        ]);
        exit();
    }

    // 3. Eliminar
    $stmtDelete = $conn->prepare("DELETE FROM mesas WHERE id = ?");
    $stmtDelete->execute([$id]);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Mesa eliminada correctamente"
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al eliminar mesa: " . $e->getMessage()]);
}