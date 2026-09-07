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
            id, nombre, unidad_medida, stock_actual, stock_minimo, costo_unitario,
            CASE 
                WHEN stock_actual <= 0 THEN 'agotado'
                WHEN stock_actual <= stock_minimo THEN 'critico'
                ELSE 'optimo'
            END AS estado_stock
        FROM insumos
        ORDER BY estado_stock DESC, nombre ASC
    ";

    $stmt = $conn->query($sql);
    $insumos = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total" => count($insumos),
        "data" => $insumos
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al consultar inventario: " . $e->getMessage()]);
}