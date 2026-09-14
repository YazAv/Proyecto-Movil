<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

// Acepta 'nombre' o 'razon_social' si viene de algún formulario antiguo
$nombre = !empty($input['nombre']) ? trim($input['nombre']) : (!empty($input['razon_social']) ? trim($input['razon_social']) : null);

if (empty($nombre)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El campo nombre es obligatorio"]);
    exit();
}

$contacto_nombre = !empty($input['contacto_nombre']) ? trim($input['contacto_nombre']) : (!empty($input['contacto']) ? trim($input['contacto']) : null);
$telefono = !empty($input['telefono']) ? trim($input['telefono']) : null;
$correo = !empty($input['correo']) ? trim(strtolower($input['correo'])) : (!empty($input['email']) ? trim(strtolower($input['email'])) : null);
$rfc = !empty($input['rfc']) ? strtoupper(trim($input['rfc'])) : null;
$direccion = !empty($input['direccion']) ? trim($input['direccion']) : null;
$dias_credito = isset($input['dias_credito']) ? (int)$input['dias_credito'] : 0;
$estado = !empty($input['estado']) ? trim($input['estado']) : 'activo';

$db = new Database();
$conn = $db->getConnection();

try {
    if ($correo !== null) {
        $stmtCheck = $conn->prepare("SELECT id FROM proveedores WHERE correo = ? OR email = ?");
        $stmtCheck->execute([$correo, $correo]);
        if ($stmtCheck->fetch()) {
            http_response_code(409);
            echo json_encode(["status" => "error", "message" => "El correo ya está registrado para otro proveedor"]);
            exit();
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO proveedores 
        (nombre, contacto_nombre, telefono, correo, rfc, direccion, dias_credito, estado) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$nombre, $contacto_nombre, $telefono, $correo, $rfc, $direccion, $dias_credito, $estado]);
    $id = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "Proveedor dado de alta correctamente",
        "data" => [
            "id" => $id,
            "nombre" => $nombre,
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
    echo json_encode(["status" => "error", "message" => "Error al registrar proveedor: " . $e->getMessage()]);
}