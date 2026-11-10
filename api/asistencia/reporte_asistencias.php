<?php
// api/asistencia/reporte_asistencias.php
header('Content-Type: application/json; charset=utf-8');

$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../../conexion.php',
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

$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
$empleado_id = $_GET['empleado_id'] ?? 'todos';

try {
    // Consulta SQL usando fecha_hora de asistencias cruzada con registros_asistencia para la salida
    $sql = "
        SELECT 
            a.id,
            a.id_empleado,
            a.fecha_hora AS entrada,
            r.hora_salida AS salida,
            COALESCE(r.estado, 'EN TURNO') AS estado,
            COALESCE(e.nombre, 'Empleado Eliminado') AS nombre_empleado,
            COALESCE(e.puesto, 'Personal') AS puesto,
            COALESCE(e.salario_por_hora, 50.00) AS salario_hora,
            TO_CHAR(a.fecha_hora, 'YYYY-MM-DD') AS fecha,
            TO_CHAR(a.fecha_hora, 'HH24:MI:SS') AS hora_entrada,
            TO_CHAR(r.hora_salida, 'HH24:MI:SS') AS hora_salida,
            COALESCE(r.horas_trabajadas, 0.00) AS horas_trabajadas,
            ROUND(CAST(COALESCE(r.horas_trabajadas, 0) * COALESCE(e.salario_por_hora, 50.00) AS numeric), 2) AS total_pagar
        FROM asistencias a
        INNER JOIN empleados e ON a.id_empleado = e.id_empleado
        LEFT JOIN registros_asistencia r ON r.id_empleado = a.id_empleado AND DATE(r.hora_entrada) = DATE(a.fecha_hora)
        WHERE DATE(a.fecha_hora) BETWEEN ? AND ? AND UPPER(a.tipo) = 'ENTRADA'
    ";
    
    $params = [$fecha_inicio, $fecha_fin];

    if ($empleado_id !== 'todos' && $empleado_id !== '' && strtolower($empleado_id) !== 'todos los empleados') {
        $sql .= " AND a.id_empleado = ?";
        $params[] = $empleado_id;
    }

    $sql .= " ORDER BY a.fecha_hora DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $registros
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>