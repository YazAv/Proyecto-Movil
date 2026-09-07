<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['orden_detalle_id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El campo 'orden_detalle_id' es obligatorio"]);
    exit();
}

$detalle_id = (int)$input['orden_detalle_id'];
$pin_autorizacion = !empty($input['pin_admin']) ? trim($input['pin_admin']) : null;
$motivo = !empty($input['motivo']) ? trim($input['motivo']) : 'Cancelado por el cliente';

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    $stmtItem = $conn->prepare("SELECT * FROM orden_detalles WHERE id = ? FOR UPDATE");
    $stmtItem->execute([$detalle_id]);
    $item = $stmtItem->fetch();

    if (!$item) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Ítem no encontrado."]);
        exit();
    }

    if ($item['estado_kds'] === 'cancelado') {
        $conn->rollBack();
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "El ítem ya se encuentra cancelado."]);
        exit();
    }

    // Si ya no está en pendiente, requiere autorización de Administrador (rol_id = 1)
    if ($item['estado_kds'] !== 'pendiente') {
        if (empty($pin_autorizacion)) {
            $conn->rollBack();
            http_response_code(403);
            echo json_encode([
                "status" => "error",
                "message" => "El platillo ya está en '{$item['estado_kds']}'. Se requiere el PIN de administrador para cancelarlo."
            ]);
            exit();
        }

        $stmtAdmin = $conn->prepare("SELECT id, rol_id, nombre FROM usuarios WHERE pin_acceso = ? AND estado = 'activo'");
        $stmtAdmin->execute([$pin_autorizacion]);
        $admin = $stmtAdmin->fetch();

        if (!$admin || (int)$admin['rol_id'] !== 1) {
            $conn->rollBack();
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "PIN inválido o el usuario no cuenta con rango de Administrador."]);
            exit();
        }
    }

    // 1. Revertir insumos al almacén si el producto tiene receta
    $stmtReceta = $conn->prepare("SELECT insumo_id, cantidad, rendimiento_factor FROM recetas WHERE producto_id = ?");
    $stmtReceta->execute([$item['producto_id']]);
    $ingredientes = $stmtReceta->fetchAll();

    foreach ($ingredientes as $ing) {
        $cantidad_devolver = ((float)$ing['cantidad'] * (int)$item['cantidad']) / (float)$ing['rendimiento_factor'];
        $stmtStock = $conn->prepare("UPDATE insumos SET stock_actual = stock_actual + ? WHERE id = ?");
        $stmtStock->execute([$cantidad_devolver, $ing['insumo_id']]);
    }

    // 2. Marcar como cancelado y registrar el motivo en notas
    $nota_actualizada = trim($item['notas_cocina'] . " [CANCELADO: $motivo]");
    $stmtCancel = $conn->prepare("
        UPDATE orden_detalles 
        SET estado_kds = 'cancelado', subtotal = 0.00, notas_cocina = ? 
        WHERE id = ?
    ");
    $stmtCancel->execute([$nota_actualizada, $detalle_id]);

    // 3. Recalcular el total general de la comanda en la tabla 'ordenes'
    $stmtRecalc = $conn->prepare("
        UPDATE ordenes 
        SET total = (SELECT COALESCE(SUM(subtotal), 0) FROM orden_detalles WHERE orden_id = ? AND estado_kds != 'cancelado')
        WHERE id = ?
    ");
    $stmtRecalc->execute([$item['orden_id'], $item['orden_id']]);

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Ítem cancelado correctamente. Insumos reintegrados al stock y orden recalculada.",
        "data" => [
            "orden_detalle_id" => $detalle_id,
            "estado_kds" => "cancelado",
            "orden_id" => $item['orden_id']
        ]
    ]);
} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al procesar cancelación: " . $e->getMessage()]);
}