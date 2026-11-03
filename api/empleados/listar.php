<?php
// api/empleados/listar.php
header('Content-Type: application/json');

if (file_exists(__DIR__ . '/../../config/conexion.php')) {
    require_once __DIR__ . '/../../config/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
} else {
    require_once __DIR__ . '/index.php';
}

try {
    $stmt = $conn->query("
        SELECT id_empleado, nombre, apellido, puesto, telefono, 
               activo, codigo_qr, pin_acceso, salario_por_hora,
               TO_CHAR(creado_en, 'DD/MM/YYYY') as fecha_registro
        FROM empleados 
        WHERE activo = true 
        ORDER BY id_empleado DESC
    ");
    $empleados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'data' => $empleados]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'data' => []]);
}
?>