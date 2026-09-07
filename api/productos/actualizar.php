<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El campo 'id' del producto es obligatorio"]);
    exit();
}

$id = (int)$input['id'];

$db = new Database();
$conn = $db->getConnection();

try {
    $stmtProd = $conn->prepare("SELECT * FROM productos WHERE id = ?");
    $stmtProd->execute([$id]);
    $prodActual = $stmtProd->fetch();

    if (!$prodActual) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Producto no encontrado."]);
        exit();
    }

    $categoria_id = isset($input['categoria_id']) ? (int)$input['categoria_id'] : $prodActual['categoria_id'];
    $estacion_kds_id = isset($input['estacion_kds_id']) ? (int)$input['estacion_kds_id'] : $prodActual['estacion_kds_id'];
    $nombre = isset($input['nombre']) ? trim($input['nombre']) : $prodActual['nombre'];
    $descripcion = array_key_exists('descripcion', $input) ? trim($input['descripcion']) : $prodActual['descripcion'];
    $precio = isset($input['precio']) ? (float)$input['precio'] : (float)$prodActual['precio'];
    $costo = isset($input['costo']) ? (float)$input['costo'] : (float)$prodActual['costo'];
    $tiempo_preparacion_min = isset($input['tiempo_preparacion_min']) ? (int)$input['tiempo_preparacion_min'] : (int)$prodActual['tiempo_preparacion_min'];
    $tiempo_estimado_min = isset($input['tiempo_estimado_min']) ? (int)$input['tiempo_estimado_min'] : (int)$prodActual['tiempo_estimado_min'];
    $es_preparado = isset($input['es_preparado']) ? (int)$input['es_preparado'] : (int)$prodActual['es_preparado'];
    $disponible = isset($input['disponible']) ? (int)$input['disponible'] : (int)$prodActual['disponible'];

    $stmtUpdate = $conn->prepare("
        UPDATE productos 
        SET categoria_id = ?, estacion_kds_id = ?, nombre = ?, descripcion = ?, precio = ?, 
            costo = ?, tiempo_preparacion_min = ?, tiempo_estimado_min = ?, es_preparado = ?, disponible = ?
        WHERE id = ?
    ");

    $stmtUpdate->execute([
        $categoria_id,
        $estacion_kds_id,
        $nombre,
        $descripcion,
        $precio,
        $costo,
        $tiempo_preparacion_min,
        $tiempo_estimado_min,
        $es_preparado,
        $disponible,
        $id
    ]);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Producto actualizado exitosamente",
        "data" => [
            "id" => $id,
            "nombre" => $nombre,
            "precio" => $precio,
            "disponible" => $disponible
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al actualizar producto: " . $e->getMessage()]);
}