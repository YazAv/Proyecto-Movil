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
        SELECT m.id AS id_mesa, m.numero AS numero_mesa, m.capacidad, m.estado, a.nombre AS area
        FROM mesas m
        JOIN areas a ON m.area_id = a.id
        ORDER BY a.id ASC, m.numero ASC
    ");
    $stmt->execute();
    $mesas = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total" => count($mesas),
        "data" => $mesas
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error: " . $e->getMessage()]);
}