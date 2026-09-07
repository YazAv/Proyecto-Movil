<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['area_id']) || empty($input['numero'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos requeridos (area_id, numero)"]);
    exit();
}

$area_id = (int)$input['area_id'];
$numero = trim($input['numero']);
$capacidad = isset($input['capacidad']) ? (int)$input['capacidad'] : 4;
$pos_x = isset($input['pos_x']) ? (int)$input['pos_x'] : 0;
$pos_y = isset($input['pos_y']) ? (int)$input['pos_y'] : 0;
$estado = !empty($input['estado']) ? trim($input['estado']) : 'disponible';

$db = new Database();
$conn = $db->getConnection();

try {
    // Validar que el área exista
    $stmtArea = $conn->prepare("SELECT id FROM areas WHERE id = ?");
    $stmtArea->execute([$area_id]);
    if (!$stmtArea->fetch()) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "El área especificada no existe."]);
        exit();
    }

    // Insertar mesa
    $stmt = $conn->prepare("
        INSERT INTO mesas (area_id, numero, capacidad, estado, pos_x, pos_y)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$area_id, $numero, $capacidad, $estado, $pos_x, $pos_y]);
    $id = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "Mesa creada exitosamente",
        "data" => [
            "id" => $id,
            "area_id" => $area_id,
            "numero" => $numero,
            "capacidad" => $capacidad,
            "estado" => $estado,
            "pos_x" => $pos_x,
            "pos_y" => $pos_y
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al registrar mesa: " . $e->getMessage()]);
}