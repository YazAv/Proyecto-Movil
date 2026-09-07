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
    $stmtCurrent = $conn->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmtCurrent->execute([$id]);
    $actual = $stmtCurrent->fetch();

    if (!$actual) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Cliente no encontrado"]);
        exit();
    }

    $nombre = isset($input['nombre']) ? trim($input['nombre']) : $actual['nombre'];
    $telefono = array_key_exists('telefono', $input) ? trim($input['telefono']) : $actual['telefono'];
    $correo = array_key_exists('correo', $input) ? (!empty($input['correo']) ? trim(strtolower($input['correo'])) : null) : $actual['correo'];
    $rfc = array_key_exists('rfc', $input) ? strtoupper(trim($input['rfc'])) : $actual['rfc'];
    $direccion = array_key_exists('direccion', $input) ? trim($input['direccion']) : $actual['direccion'];

    if ($correo !== null && $correo !== $actual['correo']) {
        $checkMail = $conn->prepare("SELECT id FROM clientes WHERE correo = ? AND id != ?");
        $checkMail->execute([$correo, $id]);
        if ($checkMail->fetch()) {
            http_response_code(409);
            echo json_encode(["status" => "error", "message" => "El correo ya está registrado en otro cliente"]);
            exit();
        }
    }

    $stmtUpdate = $conn->prepare("
        UPDATE clientes 
        SET nombre = ?, telefono = ?, correo = ?, rfc = ?, direccion = ? 
        WHERE id = ?
    ");
    $stmtUpdate->execute([$nombre, $telefono, $correo, $rfc, $direccion, $id]);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Cliente actualizado con éxito",
        "data" => [
            "id" => $id,
            "nombre" => $nombre,
            "telefono" => $telefono,
            "correo" => $correo,
            "rfc" => $rfc,
            "direccion" => $direccion
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al actualizar cliente: " . $e->getMessage()]);
}