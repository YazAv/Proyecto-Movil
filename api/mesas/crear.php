<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

// Aceptar variaciones de nombres de campo (numero o numero_mesa)
$numero_mesa = trim($input['numero_mesa'] ?? $input['numero'] ?? '');
$capacidad   = (int)($input['capacidad'] ?? 4);
$id_area     = (int)($input['id_area'] ?? 0);

if (empty($numero_mesa) || $id_area <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Debe ingresar el número de mesa y seleccionar un área válida."]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("
        INSERT INTO mesas (numero_mesa, capacidad, id_area, estado) 
        VALUES (:num, :cap, :area, 'disponible')
    ");
    
    $stmt->execute([
        ':num'  => $numero_mesa,
        ':cap'  => $capacidad,
        ':area' => $id_area
    ]);

    http_response_code(201);
    echo json_encode([
        "status"  => "success",
        "message" => "Mesa '{$numero_mesa}' agregada correctamente"
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
}