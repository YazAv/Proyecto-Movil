<?php
// api/comandas/historial.php
header('Content-Type: application/json; charset=utf-8');

// Búsqueda dinámica de conexión
$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../../conexion.php',
    __DIR__ . '/../conexion.php'
];

foreach ($rutas as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        break;
    }
}

if (!isset($conn) && class_exists('Database')) {
    $db = new Database();
    $conn = $db->getConnection();
}

$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
$estado = $_GET['estado'] ?? 'todas';

try {
    $sql = "
        SELECT o.id_orden as folio, 
               o.fecha_apertura, 
               o.fecha_cierre, 
               o.estado, 
               COALESCE(o.total, 0.00) as total,
               COALESCE(m.nombre, 'Para Llevar') as mesa,
               COALESCE(e.nombre, u.nombre, 'Sin asignar') as mesero
        FROM ordenes o
        LEFT JOIN mesas m ON o.id_mesa = m.id_mesa
        LEFT JOIN empleados e ON o.id_mesero = e.id_empleado
        LEFT JOIN usuarios u ON o.id_usuario = u.id
        WHERE DATE(o.fecha_apertura) BETWEEN ? AND ?
    ";
    
    $params = [$fecha_inicio, $fecha_fin];

    if ($estado !== 'todas') {
        $sql .= " AND o.estado = ?";
        $params[] = $estado;
    }

    $sql .= " ORDER BY o.id_orden DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $comandas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $comandas
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>