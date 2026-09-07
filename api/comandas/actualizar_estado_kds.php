<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['detalle_id']) || !isset($input['nuevo_estado'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos requeridos (detalle_id o nuevo_estado)"]);
    exit();
}

$detalle_id = (int)$input['detalle_id'];
$nuevo_estado = trim($input['nuevo_estado']);

$estados_validos = ['pendiente', 'en_preparacion', 'listo', 'entregado', 'cancelado'];
if (!in_array($nuevo_estado, $estados_validos)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Estado no válido"]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // 1. Obtener estado actual y producto de este ítem
    $stmtActual = $conn->prepare("
        SELECT od.estado_kds, od.producto_id, od.cantidad 
        FROM orden_detalles od 
        WHERE od.id = ? FOR UPDATE
    ");
    $stmtActual->execute([$detalle_id]);
    $item = $stmtActual->fetch();

    if (!$item) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "No se encontró el detalle de orden"]);
        exit();
    }

    $estado_anterior = $item['estado_kds'];

    // 2. Timestamp correspondiente
    $campo_tiempo = "";
    switch ($nuevo_estado) {
        case 'en_preparacion':
            $campo_tiempo = ", hora_inicio_cocina = NOW()";
            break;
        case 'listo':
            $campo_tiempo = ", hora_listo = NOW()";
            break;
        case 'entregado':
            $campo_tiempo = ", hora_entregado = NOW()";
            break;
    }

    // 3. Actualizar estado KDS
    $sql = "UPDATE orden_detalles 
            SET estado_kds = :nuevo_estado $campo_tiempo 
            WHERE id = :detalle_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':nuevo_estado' => $nuevo_estado,
        ':detalle_id' => $detalle_id
    ]);

    $insumos_afectados = [];

    // 4. Descuento automático de stock solo cuando pasa a 'en_preparacion' por primera vez
    if ($nuevo_estado === 'en_preparacion' && $estado_anterior === 'pendiente') {
        $producto_id = (int)$item['producto_id'];
        $cantidad_pedida = (int)$item['cantidad'];

        // Consultar receta
        $stmtReceta = $conn->prepare("
            SELECT r.insumo_id, r.cantidad_necesaria, i.nombre AS insumo_nombre, i.stock_actual, i.stock_minimo
            FROM producto_recetas r
            JOIN insumos i ON r.insumo_id = i.id
            WHERE r.producto_id = ?
        ");
        $stmtReceta->execute([$producto_id]);
        $ingredientes = $stmtReceta->fetchAll();

        $stmtUpdateStock = $conn->prepare("UPDATE insumos SET stock_actual = stock_actual - ? WHERE id = ?");
        $stmtKardex = $conn->prepare("
            INSERT INTO movimientos_inventario (insumo_id, tipo_movimiento, cantidad, referencia_id, notas, creado_en) 
            VALUES (?, 'salida_comanda', ?, ?, ?, NOW())
        ");

        foreach ($ingredientes as $ing) {
            $insumo_id = (int)$ing['insumo_id'];
            $total_descontar = (float)$ing['cantidad_necesaria'] * $cantidad_pedida;

            $stmtUpdateStock->execute([$total_descontar, $insumo_id]);
            $stmtKardex->execute([$insumo_id, $total_descontar, $detalle_id, "Consumo KDS comanda detalle #$detalle_id"]);

            $stock_remanente = (float)$ing['stock_actual'] - $total_descontar;

            $insumos_afectados[] = [
                "insumo" => $ing['insumo_nombre'],
                "descontado" => $total_descontar,
                "stock_remanente" => $stock_remanente,
                "alerta_minimo" => ($stock_remanente <= (float)$ing['stock_minimo'])
            ];
        }
    }

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Estado actualizado a '$nuevo_estado'",
        "data" => [
            "detalle_id" => $detalle_id,
            "estado_anterior" => $estado_anterior,
            "estado_nuevo" => $nuevo_estado,
            "insumos_descontados" => $insumos_afectados
        ]
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al actualizar estado: " . $e->getMessage()]);
}