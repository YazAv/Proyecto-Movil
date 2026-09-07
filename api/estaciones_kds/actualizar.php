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
    $stmtEst = $conn->prepare("SELECT * FROM kds_estaciones WHERE id = ?");
    $stmtEst->execute([$id]);
    $actual = $stmtEst->fetch();

    if (!$actual) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Estación KDS no encontrada"]);
        exit();
    }

    $nombre = isset($input['nombre']) ? trim($input['nombre']) : $actual['nombre'];
    $impresora_ip = array_key_exists('impresora_ip', $input) ? trim($input['impresora_ip']) : $actual['impresora_ip'];
    $activa = isset($input['activa']) ? (int)$input['activa'] : (int)$actual['activa'];

    $stmtUpdate = $conn->prepare("
        UPDATE kds_estaciones 
        SET nombre = ?, impresora_ip = ?, activa = ? 
        WHERE id = ?
    ");
    $stmtUpdate->execute([$nombre, $impresora_ip, $activa, $id]);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Estación KDS actualizada con éxito",
        "data" => [
            "id" => $id,
            "nombre" => $nombre,
            "impresora_ip" => $impresora_ip,
            "activa" => $activa
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al actualizar estación: " . $e->getMessage()]);
}