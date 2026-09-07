<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['producto_id']) || !isset($input['insumo_id']) || !isset($input['cantidad'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios (producto_id, insumo_id, cantidad)"]);
    exit();
}

$producto_id = (int)$input['producto_id'];
$insumo_id = (int)$input['insumo_id'];
$cantidad = (float)$input['cantidad'];
$rendimiento_factor = isset($input['rendimiento_factor']) ? (float)$input['rendimiento_factor'] : 1.00;

if ($cantidad <= 0) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "La cantidad debe ser mayor a 0"]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    // Validar existencia de producto e insumo
    $checkProd = $conn->prepare("SELECT id FROM productos WHERE id = ?");
    $checkProd->execute([$producto_id]);
    if (!$checkProd->fetch()) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "El producto no existe."]);
        exit();
    }

    $checkIns = $conn->prepare("SELECT id FROM insumos WHERE id = ?");
    $checkIns->execute([$insumo_id]);
    if (!$checkIns->fetch()) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "El insumo no existe."]);
        exit();
    }

    // Evitar duplicar el mismo ingrediente en la misma receta
    $checkDup = $conn->prepare("SELECT id FROM recetas WHERE producto_id = ? AND insumo_id = ?");
    $checkDup->execute([$producto_id, $insumo_id]);
    if ($checkDup->fetch()) {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "Este insumo ya forma parte de la receta. Usa actualizar para modificar su porción."]);
        exit();
    }

    $stmt = $conn->prepare("
        INSERT INTO recetas (producto_id, insumo_id, cantidad, rendimiento_factor) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$producto_id, $insumo_id, $cantidad, $rendimiento_factor]);
    $id = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "Ingrediente agregado a la receta correctamente",
        "data" => [
            "id" => $id,
            "producto_id" => $producto_id,
            "insumo_id" => $insumo_id,
            "cantidad" => $cantidad,
            "rendimiento_factor" => $rendimiento_factor
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al registrar ingrediente: " . $e->getMessage()]);
}