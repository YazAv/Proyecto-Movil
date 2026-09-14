<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['mesa_id']) || !isset($input['pos_x']) || !isset($input['pos_y'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios (mesa_id, pos_x, pos_y)"]);
    exit();
}

$mesa_id = (int)$input['mesa_id'];
$pos_x   = (int)$input['pos_x'];
$pos_y   = (int)$input['pos_y'];

$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("
        UPDATE mesas 
        SET pos_x = :pos_x, 
            pos_y = :pos_y 
        WHERE id = :mesa_id OR id_mesa = :mesa_id
    ");

    $stmt->execute([
        ':pos_x'   => $pos_x,
        ':pos_y'   => $pos_y,
        ':mesa_id' => $mesa_id
    ]);

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Posición de la mesa actualizada correctamente",
        "data"    => [
            "mesa_id" => $mesa_id,
            "pos_x"   => $pos_x,
            "pos_y"   => $pos_y
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al actualizar coordenadas: " . $e->getMessage()]);
}