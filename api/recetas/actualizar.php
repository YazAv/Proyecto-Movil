<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['id']) || !isset($input['cantidad'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos requeridos (id, cantidad)"]);
    exit();
}

$id = (int)$input['id'];
$cantidad = (float)$input['cantidad'];
$rendimiento_factor = isset($input['rendimiento_factor']) ? (float)$input['rendimiento_factor'] : null;

if ($cantidad <= 0) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "La cantidad debe ser mayor a 0"]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $stmtActual = $conn->prepare("SELECT * FROM recetas WHERE id = ?");
    $stmtActual->execute([$id]);
    $actual = $stmtActual->fetch();

    if (!$actual) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Registro de receta no encontrado"]);
        exit();
    }

    $factor_final = $rendimiento_factor !== null ? $rendimiento_factor : $actual['rendimiento_factor'];

    $stmtUpdate = $conn->prepare("UPDATE recetas SET cantidad = ?, rendimiento_factor = ? WHERE id = ?");
    $stmtUpdate->execute([$cantidad, $factor_final, $id]);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Porción de receta actualizada exitosamente",
        "data" => [
            "id" => $id,
            "cantidad" => $cantidad,
            "rendimiento_factor" => $factor_final
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al actualizar receta: " . $e->getMessage()]);
}