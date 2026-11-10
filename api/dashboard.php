<?php
// api/dashboard.php
header('Content-Type: application/json; charset=utf-8');

$rutas = [
    __DIR__ . '/../config/conexion.php',
    __DIR__ . '/../conexion.php',
    __DIR__ . '/conexion.php'
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

try {
    // 1. Obtener Ventas Totales y Órdenes Finalizadas del día o del turno activo
    $stmtVentas = $conn->query("
        SELECT 
            COUNT(id_orden) AS total_ordenes,
            COALESCE(SUM(total), 0) AS ventas_totales
        FROM ordenes 
        WHERE estado IN ('pagada', 'completada') 
          AND DATE(fecha_creacion) = CURRENT_DATE
    ");
    $estadisticas = $stmtVentas->fetch(PDO::FETCH_ASSOC);
    
    $ventas_totales = (float)$estadisticas['ventas_totales'];
    $total_ordenes = (int)$estadisticas['total_ordenes'];
    
    // Ticket Promedio
    $ticket_promedio = ($total_ordenes > 0) ? ($ventas_totales / $total_ordenes) : 0;

    // 2. Personal Activo (Empleados en turno que no han salido)
    $stmtPersonal = $conn->query("
        SELECT COUNT(id_empleado) AS personal_activo
        FROM registros_asistencia
        WHERE hora_salida IS NULL AND DATE(fecha) = CURRENT_DATE
    ");
    $personal_activo = (int)$stmtPersonal->fetchColumn();

    // 3. Órdenes por Estado (Para la gráfica de dona)
    $stmtEstados = $conn->query("
        SELECT estado, COUNT(id_orden) as cantidad 
        FROM ordenes 
        WHERE DATE(fecha_creacion) = CURRENT_DATE
        GROUP BY estado
    ");
    
    $ordenes_estado = [
        'listas' => 0,
        'en_preparacion' => 0,
        'pendientes' => 0,
        'canceladas' => 0
    ];
    
    while ($row = $stmtEstados->fetch(PDO::FETCH_ASSOC)) {
        $estado = strtolower($row['estado']);
        if (in_array($estado, ['lista', 'completada', 'pagada'])) {
            $ordenes_estado['listas'] += (int)$row['cantidad'];
        } elseif (in_array($estado, ['preparando', 'en cocina', 'cocinando'])) {
            $ordenes_estado['en_preparacion'] += (int)$row['cantidad'];
        } elseif (in_array($estado, ['pendiente', 'nueva'])) {
            $ordenes_estado['pendientes'] += (int)$row['cantidad'];
        } elseif ($estado === 'cancelada') {
            $ordenes_estado['canceladas'] += (int)$row['cantidad'];
        }
    }

    echo json_encode([
        'status' => 'success',
        'kpis' => [
            'ventas_totales' => $ventas_totales,
            'ordenes' => $total_ordenes,
            'ticket_promedio' => $ticket_promedio,
            'personal_activo' => $personal_activo
        ],
        'grafica_estados' => $ordenes_estado
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>