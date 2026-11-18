<?php
// api/comandas/listar_mesas_pendientes.php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../conexion.php'
];
foreach ($rutas as $r) { if (file_exists($r)) { require_once $r; break; } }
if (!isset($conn) && isset($pdo)) $conn = $pdo;
if (!isset($conn) && class_exists('Database')) $conn = (new Database())->getConnection();

if (!isset($conn)) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'No se pudo establecer la conexion con la base de datos.'
    ]);
    exit;
}

try {
    $stmt = $conn->query("
        SELECT o.id_orden,
               COALESCE(o.folio, 'ORD-' || o.id_orden) AS folio,
               o.id_mesa,
               COALESCE(o.total, 0) AS total,
               COALESCE(m.numero_mesa::text, o.id_mesa::text) AS numero_mesa,
               COALESCE('Mesa ' || m.numero_mesa, 'Mesa #' || o.id_mesa) AS nombre_mesa
        FROM public.ordenes o
        LEFT JOIN public.mesas m ON o.id_mesa = m.id_mesa
        WHERE UPPER(o.estado) = 'ABIERTA'
        ORDER BY o.creado_en ASC
    ");
    $ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtDet = $conn->prepare("
        SELECT od.id_detalle,
               od.id_producto AS producto_id,
               COALESCE(od.nombre_producto, p.nombre, 'Platillo') AS nombre_producto,
               od.cantidad,
               COALESCE(od.precio_unitario, p.precio, 0) AS precio_unitario,
               (od.cantidad * COALESCE(od.precio_unitario, p.precio, 0)) AS subtotal
        FROM public.orden_detalle od
        LEFT JOIN public.productos p ON od.id_producto = p.id_producto
        WHERE od.id_orden = ? AND UPPER(COALESCE(od.estado, 'PENDIENTE')) != 'CANCELADO'
    ");

    foreach ($ordenes as $ordIndex => $ord) {
        $stmtDet->execute([$ord['id_orden']]);
        $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
        $ord['productos'] = $items;

        // Recalcular total si en la cabecera estuviera en 0
        if (floatval($ord['total']) <= 0 && !empty($items)) {
            $ord['total'] = array_reduce(
                $items,
                function ($acc, $i) {
                    return $acc + floatval($i['subtotal']);
                },
                0
            );
        }

        $resumen = array_map(function ($i) {
            return $i['cantidad'] . 'x ' . $i['nombre_producto'];
        }, $items);
        $ord['detalle_resumen'] = implode(', ', $resumen) ?: 'Sin productos detallados';
        $ordenes[$ordIndex] = $ord;
    }

    echo json_encode($ordenes, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>