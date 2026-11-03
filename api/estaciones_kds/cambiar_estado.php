<?php
// api/estaciones_kds/cambiar_estado.php
header('Content-Type: application/json');

if (file_exists(__DIR__ . '/../../config/conexion.php')) {
    require_once __DIR__ . '/../../config/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
}

if (!isset($conn) && class_exists('Database')) {
    $db = new Database();
    $conn = $db->getConnection();
}

try {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) {
        $data = $_POST;
    }

    $detalle_id = intval($data['detalle_id'] ?? ($data['id_detalle'] ?? ($data['id'] ?? 0)));
    $nuevo_estado = strtolower(trim($data['estado'] ?? ($data['estado_preparacion'] ?? 'listo')));

    if ($detalle_id <= 0) {
        throw new Exception("ID de detalle no válido.");
    }

    $conn->beginTransaction();

    // Obtener estado actual y datos del producto antes de actualizar
    $stmtInfo = $conn->prepare("
        SELECT orden_id, producto_id, cantidad, 
               LOWER(COALESCE(estado_preparacion, estado_kds, 'pendiente')) AS estado_actual
        FROM orden_detalles 
        WHERE id_detalle = ?
    ");
    $stmtInfo->execute([$detalle_id]);
    $item = $stmtInfo->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new Exception("No se encontró el platillo en la comanda.");
    }

    // Si pasa a 'listo' o 'entregado' por primera vez, descontamos insumos según la receta
    if (in_array($nuevo_estado, ['listo', 'entregado']) && !in_array($item['estado_actual'], ['listo', 'entregado'])) {
        $stmtReceta = $conn->prepare("SELECT id_insumo, cantidad FROM recetas WHERE id_producto = ?");
        $stmtReceta->execute([$item['producto_id']]);
        $ingredientes = $stmtReceta->fetchAll(PDO::FETCH_ASSOC);

        $stmtDescontar = $conn->prepare("UPDATE insumos SET stock_actual = GREATEST(0, stock_actual - ?) WHERE id_insumo = ?");
        $stmtMov = $conn->prepare("
            INSERT INTO movimientos_inventario (id_insumo, tipo, cantidad, referencia_id, notas)
            VALUES (?, 'salida_comanda', ?, ?, ?)
        ");

        foreach ($ingredientes as $ing) {
            $total_descontar = floatval($ing['cantidad']) * intval($item['cantidad']);
            $stmtDescontar->execute([$total_descontar, $ing['id_insumo']]);
            $stmtMov->execute([
                $ing['id_insumo'],
                $total_descontar,
                $detalle_id,
                "Consumo KDS comanda detalle #{$detalle_id}"
            ]);
        }
    }

    // Actualizar ambas columnas de estado para compatibilidad total
    $stmtUpd = $conn->prepare("
        UPDATE orden_detalles 
        SET estado_preparacion = ?, 
            estado_kds = UPPER(?), 
            actualizado_en = CURRENT_TIMESTAMP 
        WHERE id_detalle = ?
    ");
    $stmtUpd->execute([$nuevo_estado, $nuevo_estado, $detalle_id]);

    $conn->commit();

    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => 'Estado del platillo actualizado a ' . $nuevo_estado
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>