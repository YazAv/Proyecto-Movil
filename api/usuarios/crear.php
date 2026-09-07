<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

// Validaciones estrictas de campos base
if (empty($input['nombre']) || empty($input['rol_id']) || empty($input['pin_acceso'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan campos obligatorios: nombre, rol_id o pin_acceso"]);
    exit();
}

$nombre = trim($input['nombre']);
$rol_id = (int)$input['rol_id'];
$pin_acceso = trim($input['pin_acceso']);
$correo = !empty($input['correo']) ? trim(strtolower($input['correo'])) : null;
$password = !empty($input['password']) ? password_hash($input['password'], PASSWORD_BCRYPT) : null;
$estado = !empty($input['estado']) ? trim($input['estado']) : 'activo';

// Validación de formato de PIN (numérico de 4 a 6 dígitos)
if (!preg_match('/^[0-9]{4,6}$/', $pin_acceso)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "El PIN debe contener entre 4 y 6 dígitos numéricos"]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    // 1. Validar que el rol exista
    $stmtRol = $conn->prepare("SELECT id, nombre FROM roles WHERE id = ?");
    $stmtRol->execute([$rol_id]);
    $rol = $stmtRol->fetch();
    if (!$rol) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "El rol especificado no existe"]);
        exit();
    }

    // 2. Comprobar PIN único en el restaurante
    $stmtPin = $conn->prepare("SELECT id FROM usuarios WHERE pin_acceso = ?");
    $stmtPin->execute([$pin_acceso]);
    if ($stmtPin->fetch()) {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "El PIN ingresado ya pertenece a otro colaborador"]);
        exit();
    }

    // 3. Comprobar correo único (si se proporciona)
    if ($correo !== null) {
        $stmtEmail = $conn->prepare("SELECT id FROM usuarios WHERE correo = ?");
        $stmtEmail->execute([$correo]);
        if ($stmtEmail->fetch()) {
            http_response_code(409);
            echo json_encode(["status" => "error", "message" => "El correo electrónico ya se encuentra registrado"]);
            exit();
        }
    }

    // 4. Inserción
    $stmt = $conn->prepare("
        INSERT INTO usuarios (rol_id, nombre, correo, password_hash, pin_acceso, estado)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$rol_id, $nombre, $correo, $password, $pin_acceso, $estado]);
    $id = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "Usuario dado de alta exitosamente",
        "data" => [
            "id" => $id,
            "nombre" => $nombre,
            "correo" => $correo,
            "rol" => $rol['nombre'],
            "pin_acceso" => $pin_acceso,
            "estado" => $estado
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al registrar usuario: " . $e->getMessage()]);
}