<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $stmt = $conn->prepare("
        SELECT 
            e.id_empleado,
            e.nombre,
            e.apellido,
            e.puesto,
            e.activo,
            u.id AS id_usuario,
            u.nombre AS nombre_usuario,
            u.rol
        FROM empleados e
        LEFT JOIN usuarios u ON u.empleado_id = e.id_empleado
        ORDER BY e.id_empleado ASC
    ");
    $stmt->execute();
    $empleados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total"  => count($empleados),
        "data"   => array_map(function($emp) {
            return [
                "empleado_id" => (int)$emp['id_empleado'],
                "nombre_completo" => $emp['nombre'] . ' ' . $emp['apellido'],
                "puesto" => $emp['puesto'],
                "activo" => (bool)$emp['activo'],
                "cuenta_usuario" => $emp['id_usuario'] ? [
                    "usuario_id" => (int)$emp['id_usuario'],
                    "username"   => $emp['nombre_usuario'],
                    "rol"        => $emp['rol']
                ] : null
            ];
        }, $empleados)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al consultar empleados: " . $e->getMessage()]);
}