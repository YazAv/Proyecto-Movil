<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $sql = "
        SELECT 
            p.id,
            p.nombre,
            p.descripcion,
            p.precio,
            p.costo,
            p.tiempo_preparacion_min,
            p.tiempo_estimado_min,
            p.es_preparado,
            p.disponible,
            c.id AS categoria_id,
            c.nombre AS categoria_nombre,
            k.id AS estacion_kds_id,
            k.nombre AS estacion_kds_nombre
        FROM productos p
        INNER JOIN categorias c ON p.categoria_id = c.id
        LEFT JOIN kds_estaciones k ON p.estacion_kds_id = k.id
        ORDER BY c.orden ASC, p.nombre ASC
    ";

    $stmt = $conn->query($sql);
    $productos = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total" => count($productos),
        "data" => $productos
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al listar productos: " . $e->getMessage()]);
}