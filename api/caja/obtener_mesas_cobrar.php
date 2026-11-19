<?php
// api/caja/obtener_mesas_cobrar.php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');

$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../config/conexion.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../conexion.php'
];
foreach ($rutas as$r) { if (file_exists($r)) { require_once$r; break; } }
if (!isset($conn) && isset($pdo)) $conn =$pdo;
if (!isset($conn) && class_exists('Database'))$conn = (new Database())->getConnection();

try {
    $stmt =$conn->query("
        SELECT 
            o.id_orden,
            COALESCE(o.folio, 'ORD-' || o.id_orden) AS folio,
            o.id_mesa,
            COALESCE(m.numero_mesa::text, 'Mostrador') AS numero_mesa,
            CASE 
                WHEN o.id_mesa IS NOT NULL THEN COALESCE('Mesa ' || m.numero_mesa, 'Mesa #' || o.id_mesa)
                ELSE 'Mostrador (' || COALESCE(o.folio, '#' || o.id_orden) || ')'
            END AS nombre_mesa,
            COALESCE(o.subtotal, o.total, 0) AS subtotal,
            COALESCE(o.total, 0) AS total,
            TO_CHAR(o.creado_en, 'HH24:MI') AS hora_apertura
        FROM public.ordenes o
        LEFT JOIN public.mesas m ON o.id_mesa = m.id_mesa
        WHERE UPPER(COALESCE(o.estado, '')) = 'ABIERTA'
        ORDER BY o.creado_en ASC
    ");
    $ordenes =$stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtDet =$conn->prepare("
        SELECT 
            od.id_detalle,
            od.id_producto,
            COALESCE(NULLIF(od.nombre_producto, ''), p.nombre, 'Platillo') AS nombre,
            od.cantidad,
            od.precio_unitario AS precio,
            COALESCE(od.subtotal, od.cantidad * od.precio_unitario) AS subtotal,
            COALESCE(od.notas, '') AS notas,
            UPPER(COALESCE(od.estado, 'PENDIENTE')) AS estado_kds
        FROM public.orden_detalle od
        LEFT JOIN public.productos p ON od.id_producto = p.id_producto
        WHERE od.id_orden = ? AND UPPER(COALESCE(od.estado, 'PENDIENTE')) != 'CANCELADO'
        ORDER BY od.id_detalle ASC
    ");

    foreach ($ordenes as &$ord) {
        $stmtDet->execute([$ord['id_orden']]);
        $items =$stmtDet->fetchAll(PDO::FETCH_ASSOC);
        $ord['productos'] =$items;

        $totalCalculado = 0;
        $resumenArr = [];
        foreach ($items as $it) {$totalCalculado += floatval($it['subtotal']);$resumenArr[] = $it['cantidad'] . 'x ' .$it['nombre'];
        }
        if ($totalCalculado > 0) {
            $ord['total'] =$totalCalculado;
            $ord['subtotal'] =$totalCalculado;
        }
        $ord['resumen'] = implode(', ',$resumenArr);
    }

    echo json_encode(['status' => 'success', 'data' => $ordenes], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'data' => []]);
}
?>