<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['detalle_id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Falta detalle_id de la orden"]);
    exit();
}

$detalle_id = (int)$input['detalle_id'];

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // 1. Obtener producto y cantidad solicitada en la comanda
    $stmtDetalle = $conn->prepare("
        SELECT producto_id, cantidad, estado_kds 
        FROM orden_detalles 
        WHERE id = ? FOR UPDATE
    ");
    $stmtDetalle->execute([$detalle_id]);
    $detalle = $stmtDetalle->fetch();

    if (!$detalle) {
        throw new Exception("El platillo en la comanda no existe.");
    }

    $producto_id = (int)$detalle['producto_id'];
    $cantidad_platillos = (int)$detalle['cantidad'];

    // 2. Consultar la receta asociada al producto
    $stmtReceta = $conn->prepare("
        SELECT r.insumo_id, r.cantidad_necesaria, i.nombre AS insumo_nombre, i.stock_actual, i.stock_minimo
        FROM producto_recetas r
        JOIN insumos i ON r.insumo_id = i.id
        WHERE r.producto_id = ?
    ");
    $stmtReceta->execute([$producto_id]);
    $ingredientes = $stmtReceta->fetchAll();

    if (empty($ingredientes)) {
        // Platillo sin receta configurada (ej. producto empaquetado o directo)
        $conn->commit();
        echo json_encode([
            "status" => "success", 
            "message" => "El platillo no cuenta con insumos configurados para descuento automático."
        ]);
        exit();
    }

    // 3. Descontar insumos y registrar movimiento en kardex/inventario
    $stmtUpdateStock = $conn->prepare("
        UPDATE insumos 
        SET stock_actual = stock_actual - ? 
        WHERE id = ?
    ");

    $stmtKardex = $conn->prepare("
        INSERT INTO movimientos_inventario (insumo_id, tipo_movimiento, cantidad, referencia_id, notas, creado_en) 
        VALUES (?, 'salida_comanda', ?, ?, ?, NOW())
    ");

    $insumos_afectados = [];

    foreach ($ingredientes as $ing) {
        $insumo_id = (int)$ing['insumo_id'];
        $total_a_descontar = (float)$ing['cantidad_necesaria'] * $cantidad_platillos;

        // Descontar stock
        $stmtUpdateStock->execute([$total_a_descontar, $insumo_id]);

        // Registrar en historial de movimientos
        $nota = "Descuento automático comanda detalle #$detalle_id";
        $stmtKardex->execute([$insumo_id, $total_a_descontar, $detalle_id, $nota]);

        $stock_remanente = (float)$ing['stock_actual'] - $total_a_descontar;

        $insumos_afectados[] = [
            "insumo" => $ing['insumo_nombre'],
            "descontado" => $total_a_descontar,
            "stock_remanente" => $stock_remanente,
            "alerta_stock_bajo" => ($stock_remanente <= (float)$ing['stock_minimo'])
        ];
    }

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Inventario descontado exitosamente",
        "data" => [
            "detalle_id" => $detalle_id,
            "insumos_actualizados" => $insumos_afectados
        ]
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}