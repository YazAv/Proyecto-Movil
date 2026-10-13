<?php
// api/recetas/crear_producto.php
require_once '../../config/conexion.php';

header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);

if (empty($data['nombre']) || !isset($data['precio'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El nombre y precio son obligatorios."]);
    exit();
}

try {
    $stmt = $conn->prepare("
        INSERT INTO productos (nombre, precio, id_categoria, activo, disponible, es_preparado)
        VALUES (:nombre, :precio, :id_categoria, true, 1, 1)
        RETURNING id_producto
    ");
    
    $stmt->execute([
        ':nombre'       => trim($data['nombre']),
        ':precio'       => floatval($data['precio']),
        ':id_categoria' => !empty($data['id_categoria']) ? intval($data['id_categoria']) : null
    ]);

    $nuevo = $stmt->fetch();

    echo json_encode([
        "status" => "success",
        "message" => "Platillo creado exitosamente",
        "id_producto" => $nuevo['id_producto']
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}