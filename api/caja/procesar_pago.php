<?php
// api/caja/procesar_pago.php
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

if (!isset($conn)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error crítico: No hay conexión a la base de datos.']);
    exit;
}

try {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data || empty($data['productos'])) {
        throw new Exception("El carrito está vacío o los datos de pago son inválidos.");
    }

    $id_orden = isset($data['id_orden']) && intval($data['id_orden']) > 0 ? intval($data['id_orden']) : null;
    $total = floatval($data['monto'] ?? $data['total'] ?? 0);
    $monto_recibido = floatval($data['monto_recibido'] ?? $total);
    $cambio = max(0, $monto_recibido - $total);
    $metodo_pago = $data['metodo_pago'] ?? 'Efectivo';
    $descuento = floatval($data['descuento'] ?? 0);
    $propina = floatval($data['propina'] ?? 0);
    $productos = $data['productos'];

    $conn->beginTransaction();

    // 1. Obtener turno abierto actual
    $stmtTurno = $conn->query("SELECT id_turno FROM public.turnos_caja WHERE LOWER(estado) = 'abierto' ORDER BY id_turno DESC LIMIT 1");
    $id_turno = $stmtTurno->fetchColumn() ?: 1;

    // 2. Si es venta rápida directa en caja
    if (!$id_orden) {
        $folio = 'VTA-' . date('YmdHis');
        $stmtNueva = $conn->prepare("
            INSERT INTO public.ordenes (folio, id_usuario, id_turno, subtotal, total, descuento, propina, metodo_pago, estado, creado_en)
            VALUES (?, 1, ?, ?, ?, ?, ?, ?, 'ABIERTA', CURRENT_TIMESTAMP) RETURNING id_orden
        ");
        $subtotal_base = $total - $propina + $descuento;
        $stmtNueva->execute([$folio, $id_turno, $subtotal_base, $total, $descuento, $propina, $metodo_pago]);
        $id_orden = $stmtNueva->fetchColumn();

        $stmtDet = $conn->prepare("
            INSERT INTO public.orden_detalle (id_orden, id_producto, nombre_producto, cantidad, precio_unitario, subtotal, notas, estacion, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'cocina', 'PENDIENTE')
        ");
        foreach ($productos as $prod) {
            $id_p = intval($prod['id'] ?? ($prod['id_producto'] ?? 1));
            $cant = intval($prod['cantidad'] ?? 1);
            $precio = floatval($prod['precio'] ?? 0);
            $nombre = $prod['nombre'] ?? 'Producto';
            $notas = $prod['notas'] ?? '';
            $subt = $cant * $precio;

            $stmtDet->execute([$id_orden, $id_p, $nombre, $cant, $precio, $subt, $notas]);
        }
    }

    // 3. Registrar el pago (Usando únicamente columnas compatibles con la tabla pagos: id_orden, id_turno, metodo_pago, monto_pagado, cambio)
    $stmtPago = $conn->prepare("
        INSERT INTO public.pagos (id_orden, id_turno, metodo_pago, monto_pagado, cambio, fecha_pago)
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $stmtPago->execute([$id_orden, $id_turno, $metodo_pago, $monto_recibido, $cambio]);

    // Actualizar estado de orden a PAGADA
    $stmtUpd = $conn->prepare("UPDATE public.ordenes SET estado = 'PAGADA' WHERE id_orden = ?");
    $stmtUpd->execute([$id_orden]);

    $conn->commit();

    echo json_encode([
        'success' => true,
        'status' => 'success',
        'id_orden' => $id_orden,
        'message' => 'Cobro procesado con éxito.'
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'status' => 'error', 
        'message' => $e->getMessage()
    ]);
}
?>