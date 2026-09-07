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
    echo json_encode(["status" => "error", "message" => "El campo 'nombre' es obligatorio"]);
    exit();
}

$nombre = trim($input['nombre']);
$icono = !empty($input['icono']) ? trim($input['icono']) : null;
$orden = isset($input['orden']) ? (int)$input['orden'] : 0;
$activa = isset($input['activa']) ? (int)$input['activa'] : 1;

$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("
        INSERT INTO categorias (nombre, icono, orden, activa) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$nombre, $icono, $orden, $activa]);
    $id = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "Categoría creada con éxito",
        "data" => [
            "id" => $id,
            "nombre" => $nombre,
            "icono" => $icono,
            "orden" => $orden,
            "activa" => $activa
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al registrar categoría: " . $e->getMessage()]);
}