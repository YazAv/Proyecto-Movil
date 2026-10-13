<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once "../../config/conexion.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['orden_id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ID de orden no proporcionado."]);
    exit();
}

$orden_id = intval($data['orden_id']);

try {
    $conn->beginTransaction();

    // 1. Obtener todos los productos y cantidades consumidas en la orden
    $stmtDetalle = $conn->prepare("
        SELECT id_producto, cantidad 
        FROM detalle_orden 
        WHERE id_orden = :orden_id
    ");
    $stmtDetalle->execute([':orden_id' => $orden_id]);
    $detalles = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

    // 2. Por cada producto, buscar su receta y descontar del inventario
    $stmtReceta = $conn->prepare("
        SELECT id_insumo, cantidad_requerida 
        FROM recetas 
        WHERE id_producto = :id_producto
    ");

    $stmtDescuento = $conn->prepare("
        UPDATE insumos 
        SET stock_actual = stock_actual - :cantidad_descontar 
        WHERE id_insumo = :id_insumo
    ");

    foreach ($detalles as $item) {
        $id_producto = $item['id_producto'];
        $cant_platillo = floatval($item['cantidad']);

        // Consultar ingredientes del platillo
        $stmtReceta->execute([':id_producto' => $id_producto]);
        $ingredientes = $stmtReceta->fetchAll(PDO::FETCH_ASSOC);

        foreach ($ingredientes as $ing) {
            $cantidadTotalDescontar = floatval($ing['cantidad_requerida']) * $cant_platillo;

            // Descontar del stock actual del insumo
            $stmtDescuento->execute([
                ':cantidad_descontar' => $cantidadTotalDescontar,
                ':id_insumo'          => $ing['id_insumo']
            ]);
        }
    }

    $conn->commit();
    echo json_encode([
        "status" => "success",
        "message" => "Inventario descontado exitosamente en base a las recetas."
    ]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al descontar inventario: " . $e->getMessage()]);
}
?>