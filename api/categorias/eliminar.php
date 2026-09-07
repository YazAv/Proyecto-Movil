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
    echo json_encode(["status" => "error", "message" => "Falta el id de la categoría"]);
    exit();
}

$id = (int)$input['id'];

$db = new Database();
$conn = $db->getConnection();

try {
    // 1. Validar si tiene productos asignados
    $stmtCheck = $conn->prepare("SELECT COUNT(*) AS total FROM productos WHERE categoria_id = ?");
    $stmtCheck->execute([$id]);
    $total_asociados = (int)$stmtCheck->fetch()['total'];

    if ($total_asociados > 0) {
        http_response_code(409);
        echo json_encode([
            "status" => "error",
            "message" => "No se puede eliminar: tiene {$total_asociados} producto(s) asignado(s)."
        ]);
        exit();
    }

    // 2. Eliminar
    $stmtDelete = $conn->prepare("DELETE FROM categorias WHERE id = ?");
    $stmtDelete->execute([$id]);

    if ($stmtDelete->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Categoría no encontrada"]);
        exit();
    }

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Categoría eliminada con éxito"
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al eliminar: " . $e->getMessage()]);
}