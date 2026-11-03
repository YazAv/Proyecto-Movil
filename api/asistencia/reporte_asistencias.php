<?php
// api/asistencia/reporte_asistencias.php
header('Content-Type: application/json; charset=utf-8');

// Búsqueda de conexión segura
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

if (!$conn) {
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos.']);
    exit;
}

$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
$empleado_id = $_GET['empleado_id'] ?? 'todos';

try {
    // Consulta SQL adaptada para unificar asistencias y calcular dinámicamente horas y salarios
    $sql = "
        SELECT a.*, 
               COALESCE(e.nombre, u.nombre, 'Empleado') AS nombre_empleado,
               COALESCE(e.puesto, 'Personal') AS puesto,
               COALESCE(e.salario_hora, 50.00) AS salario_hora,
               TO_CHAR(a.entrada, 'YYYY-MM-DD') AS fecha,
               CASE 
                   WHEN a.salida IS NOT NULL THEN ROUND(EXTRACT(EPOCH FROM (a.salida - a.entrada)) / 3600.0, 2)
                   ELSE 0.00 
               END AS horas_trabajadas,
               CASE 
                   WHEN a.salida IS NOT NULL THEN ROUND((EXTRACT(EPOCH FROM (a.salida - a.entrada)) / 3600.0) * COALESCE(e.salario_hora, 50.00), 2)
                   ELSE 0.00 
               END AS total_pagar
        FROM asistencias a
        LEFT JOIN empleados e ON a.id_empleado = e.id_empleado
        LEFT JOIN usuarios u ON a.id_usuario = u.id
        WHERE DATE(a.entrada) BETWEEN ? AND ?
    ";
    
    $params = [$fecha_inicio, $fecha_fin];

    if ($empleado_id !== 'todos' && $empleado_id !== '' && $empleado_id !== 'Todos los Empleados') {
        $sql .= " AND (a.id_empleado = ? OR a.id_usuario = ?)";
        $params[] = $empleado_id;
        $params[] = $empleado_id;
    }

    $sql .= " ORDER BY a.entrada DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $registros
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>