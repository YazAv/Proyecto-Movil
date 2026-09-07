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
    echo json_encode(["status" => "error", "message" => "El nombre del cliente es obligatorio"]);
    exit();
}

$nombre = trim($input['nombre']);
$telefono = !empty($input['telefono']) ? trim($input['telefono']) : null;
$correo = !empty($input['correo']) ? trim(strtolower($input['correo'])) : null;
$rfc = !empty($input['rfc']) ? strtoupper(trim($input['rfc'])) : null;
$direccion = !empty($input['direccion']) ? trim($input['direccion']) : null;

$db = new Database();
$conn = $db->getConnection();

try {
    if ($correo !== null) {
        $stmtCheck = $conn->prepare("SELECT id FROM clientes WHERE correo = ?");
        $stmtCheck->execute([$correo]);
        if ($stmtCheck->fetch()) {
            http_response_code(409);
            echo json_encode(["status" => "error", "message" => "El correo ya está registrado para otro cliente"]);
            exit();
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO clientes (nombre, telefono, correo, rfc, direccion) 
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$nombre, $telefono, $correo, $rfc, $direccion]);
    $id = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "Cliente registrado exitosamente",
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
    echo json_encode(["status" => "error", "message" => "Error al registrar cliente: " . $e->getMessage()]);
}