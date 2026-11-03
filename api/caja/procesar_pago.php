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

function notificarSocketPago($evento, $payload) {
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
    if (!$data) throw new Exception("Datos de pago inválidos.");

    $id_orden = isset($data['id_orden']) && intval($data['id_orden']) > 0 ? intval($data['id_orden']) : null;
    $total = floatval($data['total'] ?? 0);
    $monto_recibido = floatval($data['monto_recibido'] ?? $total);
    $cambio = max(0, $monto_recibido - $total);
    $metodo_pago = $data['metodo_pago'] ?? 'Efectivo';
    $productos = $data['productos'] ?? [];

    $conn->beginTransaction();

    // 1. Obtener turno abierto actual
    $stmtTurno = $conn->query("SELECT id_turno FROM public.turnos_caja WHERE LOWER(estado) = 'abierto' ORDER BY id_turno DESC LIMIT 1");
    $id_turno = $stmtTurno->fetchColumn() ?: 1;

    // 2. Si es venta rápida directa en caja
    if (!$id_orden) {
        $folio = 'VTA-' . date('YmdHis');
        $stmtNueva = $conn->prepare("
            INSERT INTO public.ordenes (folio, id_usuario, id_turno, subtotal, total, metodo_pago, estado, creado_en)
            VALUES (?, 1, ?, ?, ?, ?, 'ABIERTA', CURRENT_TIMESTAMP) RETURNING id_orden
        ");
        $stmtNueva->execute([$folio, $id_turno, ($total / 1.16), $total, $metodo_pago]);
        $id_orden = $stmtNueva->fetchColumn();

        $stmtDet = $conn->prepare("
            INSERT INTO public.orden_detalle (id_orden, id_producto, nombre_producto, cantidad, precio_unitario, subtotal, estacion, estado)
            VALUES (?, ?, ?, ?, ?, ?, 'cocina', 'ENTREGADO')
        ");
        foreach ($productos as $prod) {
            $id_p = intval($prod['id'] ?? ($prod['id_producto'] ?? 0));
            $cant = intval($prod['cantidad'] ?? 1);
            $precio = floatval($prod['precio'] ?? 0);
            $nombre = $prod['nombre'] ?? 'Producto';
            $stmtDet->execute([$id_orden, $id_p, $nombre, $cant, $precio, ($cant * $precio)]);
        }
    }

    // 3. LLAMAR AL PROCEDIMIENTO ALMACENADO OFICIAL DE LA BASE DE DATOS
    $stmtSpPago = $conn->prepare("CALL public.sp_registrar_pago(?, ?, ?, ?, ?, ?)");
    $stmtSpPago->execute([$id_orden, $id_turno, $metodo_pago, $total, $monto_recibido, $cambio]);

    // 4. Calcular el total del turno usando la FUNCIÓN de la BD
    $stmtFnTotal = $conn->prepare("SELECT public.fn_total_pagos_turno(?)");
    $stmtFnTotal->execute([$id_turno]);
    $totalTurno = floatval($stmtFnTotal->fetchColumn());

    $conn->commit();

    // 5. Emitir aviso al WebSocket en tiempo real
    notificarSocketPago('orden_pagada', ['id_orden' => $id_orden, 'total_turno' => $totalTurno]);

    echo json_encode([
        'status' => 'success',
        'id_orden' => $id_orden,
        'message' => 'Cobro procesado con éxito mediante sp_registrar_pago.'
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>