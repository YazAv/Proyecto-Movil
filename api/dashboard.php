<?php
// api/dashboard.php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');

$rutas = [
    __DIR__ . '/../config/conexion.php',
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../conexion.php'
];

foreach ($rutas as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        break;
    }
}

if (!isset($pdo) && isset($conn)) {
    $pdo = $conn;
} elseif (!isset($pdo) && class_exists('Database')) {
    $db = new Database();
    $pdo = $db->getConnection();
}

try {
    $periodo = $_GET['periodo'] ?? 'hoy'; // hoy, semana, mes
    $filtroFecha = "DATE(creado_en) = CURRENT_DATE";
    if ($periodo === 'semana') {
        $filtroFecha = "creado_en >= CURRENT_DATE - INTERVAL '7 days'";
    } elseif ($periodo === 'mes') {
        $filtroFecha = "creado_en >= CURRENT_DATE - INTERVAL '30 days'";
    }

    // 1. KPI: Ventas Totales y Órdenes Pagadas
    $stmtVentas = $pdo->query("
        SELECT 
            COALESCE(SUM(total), 0) AS ventas_totales,
            COUNT(*) AS ordenes_pagadas
        FROM ordenes 
        WHERE UPPER(estado) = 'PAGADA' AND $filtroFecha
    ");
    $resVentas = $stmtVentas->fetch(PDO::FETCH_ASSOC);
    $ventasTotales = floatval($resVentas['ventas_totales'] ?? 0);
    $ordenesPagadas = intval($resVentas['ordenes_pagadas'] ?? 0);
    $ticketPromedio = $ordenesPagadas > 0 ? ($ventasTotales / $ordenesPagadas) : 0;

    // 2. KPI: Estado de Mesas en Tiempo Real
    $stmtMesas = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            COUNT(*) FILTER (WHERE LOWER(COALESCE(estado, 'disponible')) IN ('disponible', 'libre')) AS disponibles,
            COUNT(*) FILTER (WHERE LOWER(estado) = 'ocupada') AS ocupadas,
            COUNT(*) FILTER (WHERE LOWER(estado) = 'reservada') AS reservadas
        FROM mesas
    ");
    $mesas = $stmtMesas->fetch(PDO::FETCH_ASSOC);

    // 3. KPI: Empleados en Turno Hoy
    $empleadosTurno = 0;
    try {
        $stmtEmp = $pdo->query("
            SELECT COUNT(DISTINCT id_empleado) 
            FROM asistencias 
            WHERE DATE(fecha_hora_entrada) = CURRENT_DATE AND fecha_hora_salida IS NULL
        ");
        $empleadosTurno = intval($stmtEmp->fetchColumn());
    } catch (Exception $e) {
        $empleadosTurno = 0;
    }

    // 4. GRÁFICA 1: Ingresos de los últimos 7 días
    $stmtSemana = $pdo->query("
        SELECT 
            TO_CHAR(d.dia, 'DD/MM') AS etiqueta,
            COALESCE(SUM(o.total), 0) AS total,
            COUNT(o.id_orden) AS ordenes
        FROM (
            SELECT CURRENT_DATE - offs AS dia 
            FROM generate_series(6, 0, -1) AS offs
        ) d
        LEFT JOIN ordenes o ON DATE(o.creado_en) = d.dia AND UPPER(o.estado) = 'PAGADA'
        GROUP BY d.dia
        ORDER BY d.dia ASC
    ");
    $ingresosSemana = $stmtSemana->fetchAll(PDO::FETCH_ASSOC);

    // 5. GRÁFICA 2: Métodos de Pago Reales
    $metodosPago = [];
    try {
        $stmtPagos = $pdo->query("
            SELECT 
                COALESCE(metodo_pago, 'Efectivo') AS metodo,
                COALESCE(SUM(monto), 0) AS total
            FROM pagos
            WHERE DATE(creado_en) >= CURRENT_DATE - INTERVAL '7 days'
            GROUP BY COALESCE(metodo_pago, 'Efectivo')
            ORDER BY total DESC
        ");
        $metodosPago = $stmtPagos->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $metodosPago = [];
    }

    // 6. GRÁFICA 3: Top 5 Platillos Más Vendidos (Usando detalle_orden)
    $topPlatillos = [];
    try {
        $stmtTop = $pdo->query("
            SELECT 
                COALESCE(p.nombre, od.nombre_producto, 'Platillo') AS producto,
                SUM(od.cantidad) AS cantidad_vendida,
                SUM(od.cantidad * od.precio_unitario) AS ingresos
            FROM detalle_orden od
            LEFT JOIN productos p ON p.id_producto = od.id_producto
            JOIN ordenes o ON o.id_orden = od.id_orden
            WHERE UPPER(o.estado) = 'PAGADA'
            GROUP BY COALESCE(p.nombre, od.nombre_producto, 'Platillo')
            ORDER BY cantidad_vendida DESC
            LIMIT 5
        ");
        $topPlatillos = $stmtTop->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $topPlatillos = [];
    }

    // 7. MONITOR KDS EN VIVO (Platillos en preparación)
    $kdsResumen = ['PENDIENTE' => 0, 'EN_PREPARACION' => 0, 'LISTO' => 0];
    try {
        $stmtKds = $pdo->query("
            SELECT 
                UPPER(COALESCE(od.estado, 'PENDIENTE')) AS estado_item,
                COALESCE(SUM(od.cantidad), 0) AS total
            FROM detalle_orden od
            JOIN ordenes o ON o.id_orden = od.id_orden
            WHERE UPPER(o.estado) = 'ABIERTA'
            GROUP BY UPPER(COALESCE(od.estado, 'PENDIENTE'))
        ");
        foreach ($stmtKds->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $est = $f['estado_item'];
            if (isset($kdsResumen[$est])) {
                $kdsResumen[$est] = intval($f['total']);
            }
        }
    } catch (Exception $e) {}

    // 8. MÓDULO DE RESERVAS (Lista de Reservaciones Activas y de Hoy)
    $reservasLista = [];
    $reservasHoyCount = 0;
    try {
        $stmtResCount = $pdo->query("
            SELECT COUNT(*) FROM reservas 
            WHERE fecha_reserva = CURRENT_DATE AND LOWER(COALESCE(estado, 'pendiente')) NOT IN ('cancelada', 'completada')
        ");
        $reservasHoyCount = intval($stmtResCount->fetchColumn());

        $stmtResList = $pdo->query("
            SELECT 
                r.id_reserva,
                COALESCE(r.nombre_cliente, 'Cliente Reservación') AS cliente,
                COALESCE(r.telefono, '-') AS telefono,
                r.fecha_reserva,
                TO_CHAR(r.hora_reserva, 'HH24:MI') AS hora,
                COALESCE(r.numero_personas, 2) AS personas,
                COALESCE(m.numero_mesa::text, r.id_mesa::text, 'Sin asignar') AS mesa,
                UPPER(COALESCE(r.estado, 'CONFIRMADA')) AS estado,
                COALESCE(r.notas, '') AS notas
            FROM reservas r
            LEFT JOIN mesas m ON m.id_mesa = r.id_mesa
            WHERE r.fecha_reserva >= CURRENT_DATE
            ORDER BY r.fecha_reserva ASC, r.hora_reserva ASC
            LIMIT 10
        ");
        $reservasLista = $stmtResList->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $reservasLista = [];
    }

    echo json_encode([
        'status' => 'success',
        'metrics' => [
            'ventas_totales' => $ventasTotales,
            'ordenes_pagadas' => $ordenesPagadas,
            'ticket_promedio' => $ticketPromedio,
            'empleados_turno' => $empleadosTurno,
            'reservas_hoy' => $reservasHoyCount,
            'mesas' => [
                'total' => intval($mesas['total'] ?? 0),
                'disponibles' => intval($mesas['disponibles'] ?? 0),
                'ocupadas' => intval($mesas['ocupadas'] ?? 0),
                'reservadas' => intval($mesas['reservadas'] ?? 0)
            ]
        ],
        'kds' => $kdsResumen,
        'semana' => $ingresosSemana,
        'metodos_pago' => $metodosPago,
        'top_platillos' => $topPlatillos,
        'reservas' => $reservasLista
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
