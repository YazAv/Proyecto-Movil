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
    $stmtActual = $conn->prepare("SELECT * FROM proveedores WHERE id = ?");
    $stmtActual->execute([$id]);
    $actual = $stmtActual->fetch();

    if (!$actual) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Proveedor no encontrado"]);
        exit();
    }

    $razon_social = isset($input['razon_social']) ? trim($input['razon_social']) : $actual['razon_social'];
    $contacto_nombre = array_key_exists('contacto_nombre', $input) ? trim($input['contacto_nombre']) : $actual['contacto_nombre'];
    $telefono = isset($input['telefono']) ? trim($input['telefono']) : $actual['telefono'];
    $correo = array_key_exists('correo', $input) ? (!empty($input['correo']) ? trim(strtolower($input['correo'])) : null) : $actual['correo'];
    $rfc = array_key_exists('rfc', $input) ? strtoupper(trim($input['rfc'])) : $actual['rfc'];
    $direccion = array_key_exists('direccion', $input) ? trim($input['direccion']) : $actual['direccion'];
    $dias_credito = isset($input['dias_credito']) ? (int)$input['dias_credito'] : (int)$actual['dias_credito'];
    $estado = isset($input['estado']) ? trim($input['estado']) : $actual['estado'];

    if ($correo !== null && $correo !== $actual['correo']) {
        $checkMail = $conn->prepare("SELECT id FROM proveedores WHERE correo = ? AND id != ?");
        $checkMail->execute([$correo, $id]);
        if ($checkMail->fetch()) {
            http_response_code(409);
            echo json_encode(["status" => "error", "message" => "El correo ya está registrado por otro proveedor"]);
            exit();
        }
    }

    $stmtUpdate = $conn->prepare("
        UPDATE proveedores 
        SET razon_social = ?, contacto_nombre = ?, telefono = ?, correo = ?, rfc = ?, 
            direccion = ?, dias_credito = ?, estado = ?
        WHERE id = ?
    ");
    $stmtUpdate->execute([$razon_social, $contacto_nombre, $telefono, $correo, $rfc, $direccion, $dias_credito, $estado, $id]);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Proveedor actualizado con éxito",
        "data" => [
            "id" => $id,
            "razon_social" => $razon_social,
            "contacto_nombre" => $contacto_nombre,
            "telefono" => $telefono,
            "correo" => $correo,
            "rfc" => $rfc,
            "dias_credito" => $dias_credito,
            "estado" => $estado
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al actualizar proveedor: " . $e->getMessage()]);
}