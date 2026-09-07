<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (
    empty($input['categoria_id']) || 
    empty($input['estacion_kds_id']) || 
    empty($input['nombre']) || 
    !isset($input['precio'])
) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios (categoria_id, estacion_kds_id, nombre, precio)"]);
    exit();
}

$categoria_id = (int)$input['categoria_id'];
$estacion_kds_id = (int)$input['estacion_kds_id'];
$nombre = trim($input['nombre']);
$descripcion = !empty($input['descripcion']) ? trim($input['descripcion']) : null;
$precio = (float)$input['precio'];
$costo = isset($input['costo']) ? (float)$input['costo'] : 0.00;
$tiempo_preparacion_min = isset($input['tiempo_preparacion_min']) ? (int)$input['tiempo_preparacion_min'] : 15;
$tiempo_estimado_min = isset($input['tiempo_estimado_min']) ? (int)$input['tiempo_estimado_min'] : $tiempo_preparacion_min;
$es_preparado = isset($input['es_preparado']) ? (int)$input['es_preparado'] : 1;
$disponible = isset($input['disponible']) ? (int)$input['disponible'] : 1;

$db = new Database();
$conn = $db->getConnection();

try {
    // Validar existencia de categoría y estación KDS
    $checkCat = $conn->prepare("SELECT id FROM categorias WHERE id = ?");
    $checkCat->execute([$categoria_id]);
    if (!$checkCat->fetch()) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "La categoría indicada no existe."]);
        exit();
    }

    $checkKds = $conn->prepare("SELECT id FROM kds_estaciones WHERE id = ?");
    $checkKds->execute([$estacion_kds_id]);
    if (!$checkKds->fetch()) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "La estación KDS indicada no existe."]);
        exit();
    }

    $stmt = $conn->prepare("
        INSERT INTO productos 
        (categoria_id, estacion_kds_id, nombre, descripcion, precio, tiempo_preparacion_min, costo, tiempo_estimado_min, es_preparado, disponible) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $categoria_id,
        $estacion_kds_id,
        $nombre,
        $descripcion,
        $precio,
        $tiempo_preparacion_min,
        $costo,
        $tiempo_estimado_min,
        $es_preparado,
        $disponible
    ]);

    $id = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "Producto registrado exitosamente",
        "data" => [
            "id" => $id,
            "nombre" => $nombre,
            "precio" => $precio,
            "tiempo_preparacion_min" => $tiempo_preparacion_min,
            "disponible" => $disponible
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al registrar producto: " . $e->getMessage()]);
}