<?php
// api/estaciones_kds/obtener_comandas.php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../config/conexion.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../conexion.php'
];
foreach ($rutas as $r) { if (file_exists($r)) { require_once $r; break; } }
if (!isset($conn) && isset($pdo)) $conn = $pdo;
if (!isset($conn) && class_exists('Database')) $conn = (new Database())->getConnection();

$estacion = strtolower(trim($_GET['estacion'] ?? 'todas')); // 'cocina', 'barra' o 'todas'

try {
    // 1. Buscar órdenes con platillos activos en public.orden_detalle
    $stmt = $conn->query("
        SELECT o.id_orden, 
               COALESCE(o.folio, 'ORD-' || o.id_orden) AS folio, 
               COALESCE('Mesa ' || m.numero_mesa, 'Mostrador') AS mesa,
               o.creado_en,
               ROUND(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - o.creado_en)) / 60) AS minutos_espera
        FROM public.ordenes o
        LEFT JOIN public.mesas m ON o.id_mesa = m.id_mesa
        WHERE EXISTS (
            SELECT 1 FROM public.orden_detalle od 
            WHERE od.id_orden = o.id_orden 
              AND UPPER(COALESCE(od.estado, 'PENDIENTE')) IN ('PENDIENTE', 'EN_PREPARACION', 'PREPARANDO')
        )
        ORDER BY o.creado_en ASC
    ");
    $comandas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Traer los platillos de cada orden desde public.orden_detalle
    $sqlDetalles = "
        SELECT od.id_detalle, 
               od.cantidad, 
               COALESCE(od.nombre_producto, p.nombre, 'Platillo') AS platillo, 
               COALESCE(od.notas, '') AS notas, 
               UPPER(COALESCE(od.estado, 'PENDIENTE')) AS estado_kds,
               LOWER(COALESCE(od.estacion, 'cocina')) AS estacion
        FROM public.orden_detalle od
        LEFT JOIN public.productos p ON od.id_producto = p.id_producto
        WHERE od.id_orden = ? 
          AND UPPER(COALESCE(od.estado, 'PENDIENTE')) IN ('PENDIENTE', 'EN_PREPARACION', 'PREPARANDO')
    ";

    if ($estacion === 'cocina' || $estacion === 'barra') {
        $sqlDetalles .= " AND LOWER(COALESCE(od.estacion, 'cocina')) = " . $conn->quote($estacion);
    }

    $stmtDet = $conn->prepare($sqlDetalles);
    $resultado = [];

    foreach ($comandas as $cmd) {
        $stmtDet->execute([$cmd['id_orden']]);
        $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($items)) {
            $cmd['origen'] = (strpos($cmd['folio'], 'MOV-') === 0) ? 'MÓVIL' : 'WEB';
            $cmd['detalles'] = $items;
            $resultado[] = $cmd;
        }
    }

    echo json_encode([
        'status' => 'success',
        'estacion' => $estacion,
        'total_comandas' => count($resultado),
        'data' => $resultado
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'data' => []
    ], JSON_UNESCAPED_UNICODE);
}
?>