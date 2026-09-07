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
            k.id, 
            k.nombre, 
            k.impresora_ip, 
            k.activa,
            COUNT(p.id) AS total_platillos_asignados
        FROM kds_estaciones k
        LEFT JOIN productos p ON p.estacion_kds_id = k.id
        GROUP BY k.id, k.nombre, k.impresora_ip, k.activa
        ORDER BY k.id ASC
    ");
    $estaciones = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total" => count($estaciones),
        "data" => $estaciones
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al listar estaciones: " . $e->getMessage()]);
}