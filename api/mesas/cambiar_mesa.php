<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['orden_id']) || !isset($input['nueva_mesa_id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios (orden_id, nueva_mesa_id)"]);
    exit();
}

$orden_id = (int)$input['orden_id'];
$nueva_mesa_id = (int)$input['nueva_mesa_id'];

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // 1. Validar orden activa
    $stmtOrden = $conn->prepare("SELECT id, mesa_id, estado FROM ordenes WHERE id = ? FOR UPDATE");
    $stmtOrden->execute([$orden_id]);
    $orden = $stmtOrden->fetch();

    if (!$orden || $orden['estado'] === 'pagada' || $orden['estado'] === 'cancelada') {
        throw new Exception("La orden no existe o ya no está activa.");
    }

    $mesa_anterior_id = (int)$orden['mesa_id'];

    if ($mesa_anterior_id === $nueva_mesa_id) {
        throw new Exception("La orden ya se encuentra asignada a esta mesa.");
    }

    // 2. Validar que la nueva mesa esté libre
    $stmtMesa = $conn->prepare("SELECT id, numero, estado FROM mesas WHERE id = ? FOR UPDATE");
    $stmtMesa->execute([$nueva_mesa_id]);
    $nueva_mesa = $stmtMesa->fetch();

    if (!$nueva_mesa) {
        throw new Exception("La mesa destino no existe.");
    }
    if ($nueva_mesa['estado'] !== 'disponible') {
        throw new Exception("La mesa destino '{$nueva_mesa['numero']}' no está disponible.");
    }

    // 3. Mover la orden
    $stmtUpdateOrden = $conn->prepare("UPDATE ordenes SET mesa_id = ? WHERE id = ?");
    $stmtUpdateOrden->execute([$nueva_mesa_id, $orden_id]);

    // 4. Liberar mesa vieja y ocupar mesa nueva
    $conn->prepare("UPDATE mesas SET estado = 'disponible' WHERE id = ?")->execute([$mesa_anterior_id]);
    $conn->prepare("UPDATE mesas SET estado = 'ocupada' WHERE id = ?")->execute([$nueva_mesa_id]);

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Comanda transferida a la mesa '{$nueva_mesa['numero']}' con éxito",
        "data" => [
            "orden_id" => $orden_id,
            "mesa_anterior" => $mesa_anterior_id,
            "mesa_nueva" => $nueva_mesa_id
        ]
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}