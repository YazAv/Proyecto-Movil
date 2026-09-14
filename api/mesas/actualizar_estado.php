<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

$id_mesa = (int)($input['id_mesa'] ?? 0);
$nuevo_estado = trim($input['estado'] ?? '');

$estados_validos = ['disponible', 'ocupada', 'reservada', 'por_limpiar'];

if ($id_mesa <= 0 || !in_array($nuevo_estado, $estados_validos)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Datos de mesa o estado no válidos"]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("UPDATE mesas SET estado = :estado WHERE id_mesa = :id_mesa");
    $stmt->execute([
        ':estado'  => $nuevo_estado,
        ':id_mesa' => $id_mesa
    ]);

    http_response_code(200);
    echo json_encode(["status" => "success", "message" => "Estado de la mesa actualizado correctamente"]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al actualizar estado: " . $e->getMessage()]);
}