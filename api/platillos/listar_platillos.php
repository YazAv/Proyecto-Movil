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
    $stmt = $conn->prepare("
        SELECT p.id, p.nombre, p.descripcion, p.precio, p.tiempo_estimado_min,
               c.nombre AS categoria, k.nombre AS estacion_kds
        FROM productos p
        JOIN categorias c ON p.categoria_id = c.id
        JOIN kds_estaciones k ON p.estacion_kds_id = k.id
        WHERE p.disponible = 1
        ORDER BY c.orden ASC, p.nombre ASC
    ");
    $stmt->execute();
    $productos = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total" => count($productos),
        "data" => $productos
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error: " . $e->getMessage()]);
}