<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');
error_reporting(E_ALL);
ini_set('display_errors', 0);

$rutas = [
    __DIR__ . '/../config/conexion.php',
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/conexion.php'
];
foreach ($rutas as $r) {
    if (file_exists($r)) {
        require_once $r;
        break;
    }
}
if (!isset($conn) && isset($pdo)) $conn = $pdo;
if (!isset($conn) && class_exists('Database')) $conn = (new Database())->getConnection();

try {
    // 1. Turno activo
    $stmtTurno = $conn->query("SELECT id_turno FROM public.turnos_caja WHERE UPPER(COALESCE(estado, '')) = 'ABIERTO' ORDER BY id_turno DESC LIMIT 1");
    $turnoActivoId = $stmtTurno->fetchColumn() ?: null;

    // 2. KPIs últimos 30 días
    $stmtKpi = $conn->query("
        SELECT 
            COALESCE(SUM(total), 0) AS ventas_totales,
            COUNT(*) AS total_ordenes,
            COALESCE(AVG(total), 0) AS ticket_promedio
        FROM public.ordenes
        WHERE UPPER(COALESCE(estado, '')) IN ('PAGADA', 'CERRADA', 'COMPLETADA')
          AND creado_en >= CURRENT_DATE - INTERVAL '30 days'
    ");
    $kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

    // 3. Mejor día de ventas
    $stmtMejor = $conn->query("
        SELECT 
            TO_CHAR(DATE(creado_en), 'DD/MM/YYYY') AS fecha_txt,
            EXTRACT(DOW FROM DATE(creado_en)) AS dow,
            SUM(total) AS total_dia
        FROM public.ordenes
        WHERE UPPER(COALESCE(estado, '')) IN ('PAGADA', 'CERRADA', 'COMPLETADA')
          AND creado_en >= CURRENT_DATE - INTERVAL '30 days'
        GROUP BY DATE(creado_en)
        ORDER BY total_dia DESC
        LIMIT 1
    ");
    $mejorDia = $stmtMejor->fetch(PDO::FETCH_ASSOC);
    $diasNombre = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $mejorDiaMonto = $mejorDia ? floatval($mejorDia['total_dia']) : 0;
    $mejorDiaNombre = $mejorDia
        ? ($diasNombre[intval($mejorDia['dow'])] . ' (' . $mejorDia['fecha_txt'] . ')')
        : 'Sin ventas aún';

    // 4. Personal activo
    $stmtEmp = $conn->query("SELECT COUNT(*) FROM public.empleados WHERE COALESCE(activo, true) = true");
    $personalActivo = intval($stmtEmp->fetchColumn());

    // 5. Ingresos por día (últimos 7 días)
    $stmtSemana = $conn->query("
        SELECT DATE(creado_en) AS fecha, COALESCE(SUM(total), 0) AS total
        FROM public.ordenes
        WHERE UPPER(COALESCE(estado, '')) IN ('PAGADA', 'CERRADA', 'COMPLETADA')
          AND DATE(creado_en) >= CURRENT_DATE - INTERVAL '6 days'
        GROUP BY DATE(creado_en)
    ");
    $ventasPorFecha = [];
    foreach ($stmtSemana->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ventasPorFecha[$row['fecha']] = floatval($row['total']);
    }

    $diasCortos = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    $semanaData = [];
    for ($i = 6; $i >= 0; $i--) {
        $ts = strtotime("-$i days");
        $fIso = date('Y-m-d', $ts);
        $dow = intval(date('w', $ts));
        $semanaData[] = [
            'fecha' => $fIso,
            'etiqueta' => $diasCortos[$dow] . ' ' . date('d', $ts),
            'total' => $ventasPorFecha[$fIso] ?? 0
        ];
    }

    // 6. Órdenes por estado
    $stmtEst = $conn->query("
        SELECT UPPER(COALESCE(estado, 'ABIERTA')) AS est, COUNT(*) AS cant
        FROM public.ordenes
        WHERE DATE(creado_en) >= CURRENT_DATE - INTERVAL '30 days'
        GROUP BY UPPER(COALESCE(estado, 'ABIERTA'))
    ");
    $estados = ['listas' => 0, 'en_preparacion' => 0, 'pendientes' => 0, 'canceladas' => 0];
    foreach ($stmtEst->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $e = $r['est'];
        $c = intval($r['cant']);
        if (in_array($e, ['PAGADA', 'CERRADA', 'LISTA', 'COMPLETADA'])) $estados['listas'] += $c;
        elseif (in_array($e, ['EN_PREPARACION', 'PREPARANDO'])) $estados['en_preparacion'] += $c;
        elseif ($e === 'CANCELADA') $estados['canceladas'] += $c;
        else $estados['pendientes'] += $c;
    }

    // 7. Reservaciones (para el Calendario Ejecutivo)
    $stmtRes = $conn->query("
        SELECT 
            r.id_reserva,
            COALESCE(m.numero_mesa::text, 'Sin asignar') AS numero_mesa,
            r.nombre_cliente,
            COALESCE(r.telefono, 'Sin tel.') AS telefono,
            TO_CHAR(r.fecha_reserva, 'YYYY-MM-DD') AS fecha_iso,
            TO_CHAR(r.fecha_reserva, 'DD/MM/YYYY') AS fecha_fmt,
            TO_CHAR(r.hora_reserva, 'HH24:MI') AS hora,
            COALESCE(r.num_personas, 2) AS num_personas,
            COALESCE(r.tipo_evento, 'Normal') AS tipo_evento,
            COALESCE(r.alergias_notas, '') AS notas,
            UPPER(COALESCE(r.estado, 'PROGRAMADA')) AS estado
        FROM public.reservas r
        LEFT JOIN public.mesas m ON r.id_mesa = m.id_mesa
        ORDER BY r.fecha_reserva ASC, r.hora_reserva ASC
    ");
    $reservas = $stmtRes->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'turno_activo_id' => $turnoActivoId,
        'kpis' => [
            'ventas_totales' => floatval($kpi['ventas_totales']),
            'ordenes' => intval($kpi['total_ordenes']),
            'ticket_promedio' => floatval($kpi['ticket_promedio']),
            'mejor_dia_monto' => $mejorDiaMonto,
            'mejor_dia_nombre' => $mejorDiaNombre,
            'personal_activo' => $personalActivo
        ],
        'semana' => $semanaData,
        'grafica_estados' => $estados,
        'reservas' => $reservas
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>