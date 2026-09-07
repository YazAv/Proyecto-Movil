<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['nombre'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El nombre de la estación es obligatorio"]);
    exit();
}

$nombre = trim($input['nombre']);
$impresora_ip = !empty($input['impresora_ip']) ? trim($input['impresora_ip']) : null;
$activa = isset($input['activa']) ? (int)$input['activa'] : 1;

$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("
        INSERT INTO kds_estaciones (nombre, impresora_ip, activa) 
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$nombre, $impresora_ip, $activa]);
    $id = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "Estación KDS creada con éxito",
        "data" => [
            "id" => $id,
            "nombre" => $nombre,
            "impresora_ip" => $impresora_ip,
            "activa" => $activa
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al registrar estación: " . $e->getMessage()]);
}