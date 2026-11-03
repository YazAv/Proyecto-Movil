<?php
// api/comandas/crear_comanda.php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../conexion.php'
];
foreach ($rutas as $r) { if (file_exists($r)) { require_once $r; break; } }
if (!isset($conn) && isset($pdo)) $conn = $pdo;
if (!isset($conn) && class_exists('Database')) $conn = (new Database())->getConnection();

function notificarSocketWeb($evento, $payload) {
    try {
        $ch = curl_init('http://127.0.0.1:3000/emitir');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['evento' => $evento, 'data' => $payload]));
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 400);
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {}
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

    // 1. Obtener turno abierto actual
    $stmtTurno = $conn->query("SELECT id_turno FROM public.turnos_caja WHERE LOWER(estado) = 'abierto' ORDER BY id_turno DESC LIMIT 1");
    $id_turno = $stmtTurno->fetchColumn() ?: 1;

    // 2. Verificar si la mesa ya tiene una orden ABIERTA
    $stmtOrd = $conn->prepare("SELECT id_orden FROM public.ordenes WHERE id_mesa = ? AND UPPER(estado) = 'ABIERTA' LIMIT 1");
    $stmtOrd->execute([$id_mesa]);
    $id_orden = $stmtOrd->fetchColumn();

    if (!$id_orden) {
        $folio = 'CMD-' . date('YmdHis') . '-' . $id_mesa;
        // Uso del Procedimiento Almacenado oficial 'sp_crear_orden'
        $stmtSp = $conn->prepare("CALL public.sp_crear_orden(?, ?, ?, ?)");
        $stmtSp->execute([$folio, $id_mesa, $id_usuario, $id_turno]);

        $stmtOrd->execute([$id_mesa]);
        $id_orden = $stmtOrd->fetchColumn();
    }

    // 3. Consultar la VISTA 'vw_productos_detallados' y registrar en 'orden_detalle'
    $stmtInfoProd = $conn->prepare("SELECT producto, precio, COALESCE(LOWER(estacion), 'cocina') AS estacion FROM public.vw_productos_detallados WHERE id_producto = ?");
    $stmtDet = $conn->prepare("
        INSERT INTO public.orden_detalle (id_orden, id_producto, nombre_producto, cantidad, precio_unitario, subtotal, notas, estacion, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE')
    ");

    $stmtReceta = $conn->prepare("SELECT insumo_id, cantidad_necesaria FROM public.producto_recetas WHERE producto_id = ?");
    $stmtDescontar = $conn->prepare("CALL public.sp_actualizar_stock_insumo(?, ?)");

    foreach ($productos as $p) {
        $id_prod = intval($p['id_producto'] ?? ($p['producto_id'] ?? $p['id']));
        $cant = intval($p['cantidad'] ?? 1);
        $notas = $p['notas'] ?? '';

        $stmtInfoProd->execute([$id_prod]);
        $info = $stmtInfoProd->fetch(PDO::FETCH_ASSOC);

        $nombre_prod = $p['nombre'] ?? ($info['producto'] ?? 'Platillo');
        $precio = isset($p['precio']) ? floatval($p['precio']) : floatval($info['precio'] ?? 0);
        $estacion = $p['estacion'] ?? ($info['estacion'] ?? 'cocina');
        $sub = $cant * $precio;

        $stmtDet->execute([$id_orden, $id_prod, $nombre_prod, $cant, $precio, $sub, $notas, $estacion]);

        // Descontar inventario automáticamente usando la vista 'producto_recetas' y el procedimiento 'sp_actualizar_stock_insumo'
        $stmtReceta->execute([$id_prod]);
        foreach ($stmtReceta->fetchAll(PDO::FETCH_ASSOC) as $ing) {
            $descuentoNegativo = -1 * (floatval($ing['cantidad_necesaria']) * $cant);
            $stmtDescontar->execute([intval($ing['insumo_id']), $descuentoNegativo]);
        }
    }

    // 4. Actualizar totales de la orden
    $stmtTotal = $conn->prepare("
        UPDATE public.ordenes 
        SET total = (SELECT COALESCE(SUM(cantidad * precio_unitario), 0) FROM public.orden_detalle WHERE id_orden = ? AND UPPER(estado) != 'CANCELADO'),
            subtotal = (SELECT COALESCE(SUM(cantidad * precio_unitario), 0) FROM public.orden_detalle WHERE id_orden = ? AND UPPER(estado) != 'CANCELADO') / 1.16
        WHERE id_orden = ?
    ");
    $stmtTotal->execute([$id_orden, $id_orden, $id_orden]);

    // 5. Cambiar estado de la mesa a 'ocupada' usando el Procedimiento Almacenado
    $stmtMesa = $conn->prepare("CALL public.sp_cambiar_estado_mesa(?, 'ocupada')");
    $stmtMesa->execute([$id_mesa]);

    $conn->commit();

    // 6. Emitir aviso al servidor Node.js en tiempo real
    notificarSocketWeb('nueva_comanda', ['id_orden' => $id_orden, 'id_mesa' => $id_mesa]);

    echo json_encode(['status' => 'success', 'ok' => true, 'message' => 'Comanda guardada correctamente.']);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>