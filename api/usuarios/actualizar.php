<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El campo 'id' es obligatorio"]);
    exit();
}

$id = (int)$input['id'];

$db = new Database();
$conn = $db->getConnection();

try {
    // 1. Obtener datos actuales
    $stmtCurrent = $conn->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmtCurrent->execute([$id]);
    $actual = $stmtCurrent->fetch();

    if (!$actual) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
        exit();
    }

    $nombre = isset($input['nombre']) ? trim($input['nombre']) : $actual['nombre'];
    $rol_id = isset($input['rol_id']) ? (int)$input['rol_id'] : $actual['rol_id'];
    $correo = array_key_exists('correo', $input) ? (!empty($input['correo']) ? trim(strtolower($input['correo'])) : null) : $actual['correo'];
    $pin_acceso = isset($input['pin_acceso']) ? trim($input['pin_acceso']) : $actual['pin_acceso'];
    $estado = isset($input['estado']) ? trim($input['estado']) : $actual['estado'];

    // Validar formato de PIN si cambió
    if (isset($input['pin_acceso']) && !preg_match('/^[0-9]{4,6}$/', $pin_acceso)) {
        http_response_code(422);
        echo json_encode(["status" => "error", "message" => "El PIN debe tener entre 4 y 6 dígitos numéricos"]);
        exit();
    }

    // Comprobar colisión de PIN con otros usuarios
    if ($pin_acceso !== $actual['pin_acceso']) {
        $checkPin = $conn->prepare("SELECT id FROM usuarios WHERE pin_acceso = ? AND id != ?");
        $checkPin->execute([$pin_acceso, $id]);
        if ($checkPin->fetch()) {
            http_response_code(409);
            echo json_encode(["status" => "error", "message" => "El PIN ya está en uso por otro usuario"]);
            exit();
        }
    }

    // Comprobar colisión de Correo
    if ($correo !== null && $correo !== $actual['correo']) {
        $checkMail = $conn->prepare("SELECT id FROM usuarios WHERE correo = ? AND id != ?");
        $checkMail->execute([$correo, $id]);
        if ($checkMail->fetch()) {
            http_response_code(409);
            echo json_encode(["status" => "error", "message" => "El correo ya está en uso por otro usuario"]);
            exit();
        }
    }

    // Si mandan password nuevo se hashea, si no se mantiene el existente
    $password_hash = !empty($input['password']) ? password_hash($input['password'], PASSWORD_BCRYPT) : $actual['password_hash'];

    $stmtUpdate = $conn->prepare("
        UPDATE usuarios 
        SET rol_id = ?, nombre = ?, correo = ?, password_hash = ?, pin_acceso = ?, estado = ?
        WHERE id = ?
    ");
    $stmtUpdate->execute([$rol_id, $nombre, $correo, $password_hash, $pin_acceso, $estado, $id]);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Usuario modificado correctamente",
        "data" => [
            "id" => $id,
            "nombre" => $nombre,
            "correo" => $correo,
            "rol_id" => $rol_id,
            "pin_acceso" => $pin_acceso,
            "estado" => $estado
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al actualizar usuario: " . $e->getMessage()]);
}