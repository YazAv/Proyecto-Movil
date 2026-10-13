<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

// Aceptar variaciones de nombres de variables para el ID de la mesa
$id_mesa = (int)($input['id_mesa'] ?? $input['id'] ?? 0);

if ($id_mesa <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ID de mesa no válido."]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // 1. Eliminar reservas asociadas si existen
    $conn->prepare("DELETE FROM reservas WHERE id_mesa = :id")->execute([':id' => $id_mesa]);

    // 2. Eliminar la mesa de la base de datos
    $stmt = $conn->prepare("DELETE FROM mesas WHERE id_mesa = :id OR id = :id");
    $stmt->execute([':id' => $id_mesa]);

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Mesa eliminada correctamente"
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error BD al eliminar: " . $e->getMessage()]);
}