<?php
header('Content-Type: application/json');
require_once '../../config/conexion.php'; // Tu archivo de conexión a PostgreSQL

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['id_orden'])) {
    echo json_encode(['status' => 'error', 'message' => 'Falta el id_orden']);
    exit;
}

$id_orden = intval($data['id_orden']);
$id_usuario = isset($data['id_usuario']) ? intval($data['id_usuario']) : 1;

try {
    $db->beginTransaction();

    // 1. Obtener todos los productos y cantidades de la orden
    $stmtDetalles = $db->prepare("
        SELECT id_producto, cantidad 
        FROM orden_detalles 
        WHERE id_orden = :id_orden
    ");
    $stmtDetalles->execute([':id_orden' => $id_orden]);
    $detalles = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

    foreach ($detalles as $item) {
        $id_producto = $item['id_producto'];
        $cant_platillo = $item['cantidad'];

        // 2. Obtener los ingredientes requeridos según la receta
        $stmtReceta = $db->prepare("
            SELECT id_insumo, cantidad 
            FROM recetas 
            WHERE id_producto = :id_producto
        ");
        $stmtReceta->execute([':id_producto' => $id_producto]);
        $ingredientes = $stmtReceta->fetchAll(PDO::FETCH_ASSOC);

        foreach ($ingredientes as $ing) {
            $id_insumo = $ing['id_insumo'];
            $cantidad_descontar = $ing['cantidad'] * $cant_platillo;

            // 3. Descontar del stock actual
            $stmtUpdate = $db->prepare("
                UPDATE insumos 
                SET stock_actual = stock_actual - :cant 
                WHERE id_insumo = :id_insumo
            ");
            $stmtUpdate->execute([
                ':cant' => $cantidad_descontar,
                ':id_insumo' => $id_insumo
            ]);

            // 4. Registrar en el Kardex de movimientos
            $stmtKardex = $db->prepare("
                INSERT INTO movimientos_inventario (id_insumo, id_usuario, tipo, cantidad, motivo) 
                VALUES (:id_insumo, :id_usuario, 'SALIDA_VENTA', :cant, :motivo)
            ");
            $stmtKardex->execute([
                ':id_insumo' => $id_insumo,
                ':id_usuario' => $id_usuario,
                ':cant' => $cantidad_descontar,
                ':motivo' => "Venta de Orden #" . $id_orden
            ]);
        }
    }

    $db->commit();
    echo json_encode(['status' => 'success', 'message' => 'Stock descontado correctamente']);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>