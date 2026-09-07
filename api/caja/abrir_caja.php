<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['usuario_id']) || !isset($input['monto_inicial'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios (usuario_id, monto_inicial)"]);
    exit();
}

$usuario_id = (int)$input['usuario_id'];
$monto_inicial = max(0, (float)$input['monto_inicial']);
$notas = !empty($input['notas']) ? trim($input['notas']) : null;

$db = new Database();
$conn = $db->getConnection();

try {
    // Verificar si ya existe una sesión abierta para este usuario o en general
    $stmtCheck = $conn->prepare("SELECT id FROM cajas_sesiones WHERE usuario_id = ? AND estado = 'abierta' LIMIT 1");
    $stmtCheck->execute([$usuario_id]);
    
    if ($stmtCheck->fetch()) {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "El usuario ya cuenta con un turno de caja abierto."]);
        exit();
    }

    $stmtInsert = $conn->prepare("
        INSERT INTO cajas_sesiones (usuario_id, monto_inicial, estado, notas, fecha_apertura) 
        VALUES (?, ?, 'abierta', ?, NOW())
    ");
    $stmtInsert->execute([$usuario_id, $monto_inicial, $notas]);
    $caja_sesion_id = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "Turno de caja abierto correctamente",
        "data" => [
            "caja_sesion_id" => $caja_sesion_id,
            "usuario_id" => $usuario_id,
            "monto_inicial" => $monto_inicial
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al abrir caja: " . $e->getMessage()]);
}
