<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);
$pin = isset($input['pin']) ? trim($input['pin']) : '';

if (empty($pin)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El PIN es requerido"]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("
        SELECT u.id, u.nombre, u.estado, r.nombre AS rol 
        FROM usuarios u
        JOIN roles r ON u.rol_id = r.id
        WHERE u.pin_acceso = :pin
        LIMIT 1
    ");
    $stmt->bindParam(":pin", $pin);
    $stmt->execute();
    $usuario = $stmt->fetch();

    if ($usuario) {
        if ($usuario['estado'] !== 'activo') {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Usuario inactivo"]);
            exit();
        }

        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "Acceso concedido",
            "data" => [
                "id_usuario" => (int)$usuario['id'],
                "nombre" => $usuario['nombre'],
                "rol" => $usuario['rol'],
                "token" => bin2hex(random_bytes(16))
            ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "PIN incorrecto"]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error: " . $e->getMessage()]);
}