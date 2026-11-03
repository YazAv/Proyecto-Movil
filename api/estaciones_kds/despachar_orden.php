<?php
// api/estaciones_kds/despachar_orden.php
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

    $orden_id = intval($data['orden_id'] ?? ($data['id_orden'] ?? ($data['id'] ?? 0)));
    $estacion = isset($data['estacion']) ? strtolower(trim($data['estacion'])) : null;

    if ($orden_id <= 0) {
        throw new Exception("ID de orden inválido para despachar.");
    }

    $conn->beginTransaction();

    // 1. Buscar platillos pendientes de esta orden (filtrando por estación si se envió)
    $sqlItems = "
        SELECT od.id_detalle, od.producto_id, od.cantidad,
               LOWER(COALESCE(od.estado_preparacion, od.estado_kds, 'pendiente')) AS estado_actual
        FROM orden_detalles od
        JOIN productos p ON p.id_producto = od.producto_id
        LEFT JOIN categorias c ON c.id_categoria = p.id_categoria
        WHERE od.orden_id = :orden_id
          AND LOWER(COALESCE(od.estado_preparacion, od.estado_kds, 'pendiente')) NOT IN ('entregado', 'cancelado')
    ";
    
    $params = [':orden_id' => $orden_id];
    if (!empty($estacion)) {
        $sqlItems .= " AND LOWER(COALESCE(c.estacion, 'cocina')) = :estacion";
        $params[':estacion'] = $estacion;
    }

    $stmtItems = $conn->prepare($sqlItems);
    $stmtItems->execute($params);
    $platillos = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // 2. Descontar inventario de los platillos que aún no estaban en 'listo'
    $stmtReceta = $conn->prepare("SELECT id_insumo, cantidad FROM recetas WHERE id_producto = ?");
    $stmtDescontar = $conn->prepare("UPDATE insumos SET stock_actual = GREATEST(0, stock_actual - ?) WHERE id_insumo = ?");
    $stmtMov = $conn->prepare("
        INSERT INTO movimientos_inventario (id_insumo, tipo, cantidad, referencia_id, notas)
        VALUES (?, 'salida_comanda', ?, ?, ?)
    ");
    $stmtUpdDetalle = $conn->prepare("
        UPDATE orden_detalles 
        SET estado_preparacion = 'entregado', 
            estado_kds = 'ENTREGADO', 
            actualizado_en = CURRENT_TIMESTAMP 
        WHERE id_detalle = ?
    ");

    foreach ($platillos as $item) {
        if ($item['estado_actual'] !== 'listo') {
            $stmtReceta->execute([$item['producto_id']]);
            $ingredientes = $stmtReceta->fetchAll(PDO::FETCH_ASSOC);

            foreach ($ingredientes as $ing) {
                $total_descontar = floatval($ing['cantidad']) * intval($item['cantidad']);
                $stmtDescontar->execute([$total_descontar, $ing['id_insumo']]);
                $stmtMov->execute([
                    $ing['id_insumo'],
                    $total_descontar,
                    $item['id_detalle'],
                    "Consumo KDS despacho orden #{$orden_id}"
                ]);
            }
        }
        $stmtUpdDetalle->execute([$item['id_detalle']]);
    }

    $conn->commit();

    echo json_encode([
        'status' => 'success',
        'success' => true,
        'message' => 'Orden despachada e inventario actualizado correctamente.'
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