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
    echo json_encode(["status" => "error", "message" => "Falta el id del cliente"]);
    exit();
}

$id = (int)$input['id'];

$db = new Database();
$conn = $db->getConnection();

try {
    $stmtDelete = $conn->prepare("DELETE FROM clientes WHERE id = ?");
    $stmtDelete->execute([$id]);

    if ($stmtDelete->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Cliente no encontrado"]);
        exit();
    }

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Cliente eliminado correctamente"
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al eliminar cliente: " . $e->getMessage()]);
}