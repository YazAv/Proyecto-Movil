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
    echo json_encode(["status" => "error", "message" => "El campo 'id' de la mesa es obligatorio"]);
    exit();
}

$id = (int)$input['id'];
$area_id = isset($input['area_id']) ? (int)$input['area_id'] : null;
$numero = isset($input['numero']) ? trim($input['numero']) : null;
$capacidad = isset($input['capacidad']) ? (int)$input['capacidad'] : null;
$estado = isset($input['estado']) ? trim($input['estado']) : null;
$pos_x = isset($input['pos_x']) ? (int)$input['pos_x'] : null;
$pos_y = isset($input['pos_y']) ? (int)$input['pos_y'] : null;

$db = new Database();
$conn = $db->getConnection();

try {
    // Verificar que la mesa exista
    $stmtMesa = $conn->prepare("SELECT * FROM mesas WHERE id = ?");
    $stmtMesa->execute([$id]);
    $mesaActual = $stmtMesa->fetch();

    if (!$mesaActual) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Mesa no encontrada"]);
        exit();
    }

    $area_final = $area_id !== null ? $area_id : $mesaActual['area_id'];
    $numero_final = $numero !== null ? $numero : $mesaActual['numero'];
    $capacidad_final = $capacidad !== null ? $capacidad : $mesaActual['capacidad'];
    $estado_final = $estado !== null ? $estado : $mesaActual['estado'];
    $pos_x_final = $pos_x !== null ? $pos_x : $mesaActual['pos_x'];
    $pos_y_final = $pos_y !== null ? $pos_y : $mesaActual['pos_y'];

    $stmtUpdate = $conn->prepare("
        UPDATE mesas 
        SET area_id = ?, numero = ?, capacidad = ?, estado = ?, pos_x = ?, pos_y = ? 
        WHERE id = ?
    ");
    $stmtUpdate->execute([
        $area_final, $numero_final, $capacidad_final, $estado_final, $pos_x_final, $pos_y_final, $id
    ]);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Mesa actualizada exitosamente",
        "data" => [
            "id" => $id,
            "area_id" => $area_final,
            "numero" => $numero_final,
            "capacidad" => $capacidad_final,
            "estado" => $estado_final,
            "pos_x" => $pos_x_final,
            "pos_y" => $pos_y_final
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al actualizar mesa: " . $e->getMessage()]);
}