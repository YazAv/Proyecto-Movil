<?php
// api/asistencia/listar_hoy.php
header('Content-Type: application/json; charset=utf-8');

if (file_exists(__DIR__ . '/../../config/conexion.php')) {
    require_once __DIR__ . '/../../config/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
}

if (!isset($conn) && class_exists('Database')) {
    $db = new Database();
    $conn = $db->getConnection();
}

try {
    $stmt = $conn->query("
        SELECT a.id, a.id_empleado, a.tipo, a.metodo,
               TO_CHAR(a.fecha_hora, 'HH24:MI:SS') AS hora,
               TO_CHAR(a.fecha_hora, 'DD/MM/YYYY') AS fecha,
               (e.nombre || ' ' || CASE WHEN e.apellido = '-' THEN '' ELSE e.apellido END) AS empleado,
               e.puesto
        FROM asistencias a
        JOIN empleados e ON a.id_empleado = e.id_empleado
        WHERE DATE(a.fecha_hora) = CURRENT_DATE
        ORDER BY a.fecha_hora DESC
    ");
    $datos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'success' => true,
        'data' => $datos,
        'asistencias' => $datos
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'data' => [], 'message' => $e->getMessage()]);
}
?>