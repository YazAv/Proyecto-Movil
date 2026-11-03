<?php

header('Content-Type: application/json');

if (file_exists(__DIR__ . '/../../config/conexion.php')) {
    require_once __DIR__ . '/../../config/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
    require_once __DIR__ . '/crear_comanda.php';
}

try {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data || empty($data['productos'])) {
        throw new Exception("No se enviaron platillos en la comanda.");
    }

    $id_mesa = intval($data['id_mesa'] ?? 1);
    $id_usuario = intval($data['id_usuario'] ?? 1);
    $productos = $data['productos'];

    $conn->beginTransaction();

    // Obtener turno activo
    $stmtTurno = $conn->query("SELECT id_turno FROM turnos_caja WHERE LOWER(estado) = 'abierto' ORDER BY id_turno DESC LIMIT 1");
    $id_turno = $stmtTurno->fetchColumn() ?: 1;

    // Verificar si la mesa ya tiene una orden ABIERTA
    $stmtOrd = $conn->prepare("SELECT id_orden FROM ordenes WHERE id_mesa = ? AND UPPER(estado) = 'ABIERTA' LIMIT 1");
    $stmtOrd->execute([$id_mesa]);
    $id_orden = $stmtOrd->fetchColumn();

    if (!$id_orden) {
        $folio = 'CMD-' . date('YmdHis') . '-' . $id_mesa;
        $stmtNueva = $conn->prepare("
            INSERT INTO ordenes (folio, id_mesa, id_usuario, id_turno, subtotal, total, estado, creado_en)
            VALUES (?, ?, ?, ?, 0, 0, 'ABIERTA', CURRENT_TIMESTAMP) RETURNING id_orden
        ");
        $stmtNueva->execute([$folio, $id_mesa, $id_usuario, $id_turno]);
        $id_orden = $stmtNueva->fetchColumn();
    }

    // Insertar platillos para el KDS
    $stmtDet = $conn->prepare("
        INSERT INTO orden_detalles (orden_id, producto_id, cantidad, precio_unitario, subtotal, notas, estado_kds)
        VALUES (?, ?, ?, ?, ?, ?, 'PENDIENTE')
    ");

    foreach ($productos as $p) {
        $id_prod = intval($p['id_producto'] ?? $p['id']);
        $cant = intval($p['cantidad'] ?? 1);
        $precio = floatval($p['precio'] ?? 0);
        $sub = $cant * $precio;
        $notas = $p['notas'] ?? '';

        $stmtDet->execute([$id_orden, $id_prod, $cant, $precio, $sub, $notas]);
    }

    // Recalcular el total acumulado de la orden de la mesa
    $stmtTotal = $conn->prepare("
        UPDATE ordenes 
        SET total = (SELECT COALESCE(SUM(subtotal), 0) FROM orden_detalles WHERE orden_id = ? AND estado_kds != 'CANCELADO'),
            subtotal = (SELECT COALESCE(SUM(subtotal), 0) FROM orden_detalles WHERE orden_id = ? AND estado_kds != 'CANCELADO') / 1.16
        WHERE id_orden = ?
    ");
    $stmtTotal->execute([$id_orden, $id_orden, $id_orden]);

    // Marcar la mesa como ocupada
    $stmtMesa = $conn->prepare("UPDATE mesas SET estado = 'ocupada' WHERE id_mesa = ?");
    $stmtMesa->execute([$id_mesa]);

    $conn->commit();
    echo json_encode(['status' => 'success', 'id_orden' => $id_orden, 'message' => 'Comanda enviada a cocina (KDS).']);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>