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
    echo json_encode(["status" => "error", "message" => "Falta el id de la estación"]);
    exit();
}

$id = (int)$input['id'];

$db = new Database();
$conn = $db->getConnection();

try {
    // 1. Validar si existen productos asignados a esta estación
    $stmtCheck = $conn->prepare("SELECT COUNT(*) AS total FROM productos WHERE estacion_kds_id = ?");
    $stmtCheck->execute([$id]);
    $total_productos = (int)$stmtCheck->fetch()['total'];

    if ($total_productos > 0) {
        http_response_code(409);
        echo json_encode([
            "status" => "error",
            "message" => "No se puede eliminar la estación: tiene $total_productos producto(s) asignado(s). Reasigna los productos primero."
        ]);
        exit();
    }

    // 2. Eliminar
    $stmtDelete = $conn->prepare("DELETE FROM kds_estaciones WHERE id = ?");
    $stmtDelete->execute([$id]);

    if ($stmtDelete->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Estación KDS no encontrada"]);
        exit();
    }

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Estación KDS eliminada correctamente"
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al eliminar estación: " . $e->getMessage()]);
}