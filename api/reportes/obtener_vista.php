<?php
// api/reportes/obtener_vista.php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

// Desactivar impresión de warnings HTML que rompen el JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 1. Búsqueda automática del archivo de conexión en toda la estructura del proyecto
$rutas_conexion = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../config/conexion.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../../conexion.php',
    __DIR__ . '/../conexion.php',
    'C:/xampp/htdocs/restaurante_app/config/conexion.php',
    'C:/xampp/htdocs/restaurante_app/conexion.php'
];

$conexion_cargada = false;
foreach ($rutas_conexion as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        $conexion_cargada = true;
        break;
    }
}

if (!$conexion_cargada) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'No se encontró el archivo conexion.php. Verifica la ruta en api/reportes/obtener_vista.php.'
    ]);
    exit;
}

if (!isset($conn) && isset($pdo)) $conn = $pdo;
if (!isset($conn) && class_exists('Database')) $conn = (new Database())->getConnection();

if (!isset($conn)) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'La variable de conexión $conn o $pdo no está definida en conexion.php.'
    ]);
    exit;
}

// 2. Lista blanca de las 5 vistas válidas
$vistas_permitidas = [
    'vw_reporte_ventas_diarias',
    'vw_reporte_top_platillos',
    'vw_reporte_metodos_pago',
    'vw_reporte_ventas_por_cajero',
    'vw_reporte_auditoria_ordenes'
];

// Si se abre sin parámetros, carga por defecto la vista de ventas diarias
$vista_solicitada = isset($_GET['vista']) && !empty(trim($_GET['vista'])) 
    ? trim($_GET['vista']) 
    : 'vw_reporte_ventas_diarias';

if (!in_array($vista_solicitada, $vistas_permitidas)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => "La vista '{$vista_solicitada}' no está en la lista permitida."
    ]);
    exit;
}

try {
    // 3. Consultar la vista en PostgreSQL
    $stmt = $conn->query("SELECT * FROM public.{$vista_solicitada} LIMIT 100");
    $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Consultar KPIs generales
    $stmtKpi = $conn->query("
        SELECT 
            COUNT(id_orden) AS total_tickets,
            COALESCE(SUM(total), 0) AS ingresos_totales,
            COALESCE(ROUND(AVG(total), 2), 0) AS ticket_promedio
        FROM public.ordenes
        WHERE UPPER(estado) = 'PAGADA'
    ");
    $kpis = $stmtKpi->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'vista' => $vista_solicitada,
        'total_registros' => count($datos),
        'kpis' => $kpis,
        'data' => $datos
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error al consultar PostgreSQL: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>