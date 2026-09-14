<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['orden_detalle_id']) || empty($input['nuevo_estado'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Los campos 'orden_detalle_id' y 'nuevo_estado' son requeridos."]);
    exit();
}

$detalle_id = (int)$input['orden_detalle_id'];
$nuevo_estado = trim($input['nuevo_estado']);

$estados_validos = ['pendiente', 'en_preparacion', 'listo', 'entregado'];
if (!in_array($nuevo_estado, $estados_validos, true)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Estado inválido. Opciones: " . implode(', ', $estados_validos)]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $stmtCheck = $conn->prepare("SELECT id, estado_kds FROM orden_detalles WHERE id = ?");
    $stmtCheck->execute([$detalle_id]);
    $item = $stmtCheck->fetch();

    if (!$item) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Ítem de comanda no encontrado."]);
        exit();
    }

    if ($item['estado_kds'] === 'cancelado') {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "No se puede modificar el estado de un ítem cancelado."]);
        exit();
    }

    // Construcción dinámica de timestamps según el avance
    $sql = "UPDATE orden_detalles SET estado_kds = ?";
    $params = [$nuevo_estado];

    if ($nuevo_estado === 'en_preparacion') {
        $sql .= ", hora_inicio_cocina = COALESCE(hora_inicio_cocina, NOW())";
    } elseif ($nuevo_estado === 'listo') {
        $sql .= ", hora_listo = NOW()";
    } elseif ($nuevo_estado === 'entregado') {
        $sql .= ", hora_entregado = NOW()";
    }

    $sql .= " WHERE id = ?";
    $params[] = $detalle_id;

    $stmtUpdate = $conn->prepare($sql);
    $stmtUpdate->execute($params);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Estado actualizado a '$nuevo_estado' con éxito",
        "data" => [
            "orden_detalle_id" => $detalle_id,
            "estado_anterior" => $item['estado_kds'],
            "estado_actual" => $nuevo_estado
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al actualizar estado KDS: " . $e->getMessage()]);
}