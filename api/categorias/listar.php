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
    $stmt = $conn->query("
        SELECT 
            c.id, 
            c.nombre, 
            c.icono, 
            c.orden, 
            c.activa,
            COUNT(p.id) AS total_productos
        FROM categorias c
        LEFT JOIN productos p ON p.categoria_id = c.id
        GROUP BY c.id, c.nombre, c.icono, c.orden, c.activa
        ORDER BY c.orden ASC, c.nombre ASC
    ");
    $categorias = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total" => count($categorias),
        "data" => $categorias
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al listar categorías: " . $e->getMessage()]);
}