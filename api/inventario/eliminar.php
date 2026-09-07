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
    echo json_encode(["status" => "error", "message" => "Falta el id del insumo"]);
    exit();
}

$id = (int)$input['id'];

$db = new Database();
$conn = $db->getConnection();

try {
    // Validar si el insumo está siendo usado en alguna receta
    $stmtRecetas = $conn->prepare("SELECT COUNT(*) AS total FROM recetas WHERE insumo_id = ?");
    $stmtRecetas->execute([$id]);
    $usos = (int)$stmtRecetas->fetch()['total'];

    if ($usos > 0) {
        http_response_code(409);
        echo json_encode([
            "status" => "error",
            "message" => "No se puede eliminar el insumo porque forma parte de $usos receta(s) activa(s)."
        ]);
        exit();
    }

    $stmtDelete = $conn->prepare("DELETE FROM insumos WHERE id = ?");
    $stmtDelete->execute([$id]);

    if ($stmtDelete->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Insumo no encontrado"]);
        exit();
    }

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Insumo eliminado del almacén"
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al eliminar: " . $e->getMessage()]);
}