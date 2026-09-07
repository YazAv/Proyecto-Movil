<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['id']) || empty($input['nombre'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios (id, nombre)"]);
    exit();
}

$id = (int)$input['id'];
$nombre = trim($input['nombre']);
$icono = isset($input['icono']) ? trim($input['icono']) : null;
$orden = isset($input['orden']) ? (int)$input['orden'] : 0;
$activa = isset($input['activa']) ? (int)$input['activa'] : 1;

$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("
        UPDATE categorias 
        SET nombre = ?, icono = ?, orden = ?, activa = ? 
        WHERE id = ?
    ");
    $stmt->execute([$nombre, $icono, $orden, $activa, $id]);

    if ($stmt->rowCount() === 0) {
        $check = $conn->prepare("SELECT id FROM categorias WHERE id = ?");
        $check->execute([$id]);
        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Categoría no encontrada"]);
            exit();
        }
    }

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Categoría actualizada con éxito",
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
    echo json_encode(["status" => "error", "message" => "Error al actualizar categoría: " . $e->getMessage()]);
}