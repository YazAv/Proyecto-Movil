<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['razon_social']) || empty($input['telefono'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Razón social y teléfono son campos obligatorios"]);
    exit();
}

$razon_social = trim($input['razon_social']);
$contacto_nombre = !empty($input['contacto_nombre']) ? trim($input['contacto_nombre']) : null;
$telefono = trim($input['telefono']);
$correo = !empty($input['correo']) ? trim(strtolower($input['correo'])) : null;
$rfc = !empty($input['rfc']) ? strtoupper(trim($input['rfc'])) : null;
$direccion = !empty($input['direccion']) ? trim($input['direccion']) : null;
$dias_credito = isset($input['dias_credito']) ? (int)$input['dias_credito'] : 0;
$estado = !empty($input['estado']) ? trim($input['estado']) : 'activo';

$db = new Database();
$conn = $db->getConnection();

try {
    if ($correo !== null) {
        $stmtCheck = $conn->prepare("SELECT id FROM proveedores WHERE correo = ?");
        $stmtCheck->execute([$correo]);
        if ($stmtCheck->fetch()) {
            http_response_code(409);
            echo json_encode(["status" => "error", "message" => "El correo ya está registrado para otro proveedor"]);
            exit();
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO proveedores 
        (razon_social, contacto_nombre, telefono, correo, rfc, direccion, dias_credito, estado) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$razon_social, $contacto_nombre, $telefono, $correo, $rfc, $direccion, $dias_credito, $estado]);
    $id = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "Proveedor dado de alta correctamente",
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
    echo json_encode(["status" => "error", "message" => "Error al registrar proveedor: " . $e->getMessage()]);
}