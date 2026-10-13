<?php
header("Content-Type: application/json; charset=UTF-8");
require_once "../../config/conexion.php";

try {
    $db = new Database();
    
    if (method_exists($db, 'getConexion')) { $conexion = $db->getConexion(); }
    elseif (method_exists($db, 'conectar')) { $conexion = $db->conectar(); }
    elseif (method_exists($db, 'getConnection')) { $conexion = $db->getConnection(); }
    elseif (property_exists($db, 'pdo')) { $conexion = $db->pdo; }
    elseif (property_exists($db, 'conexion')) { $conexion = $db->conexion; }
    else { $conexion = $db; }

    $query = "SELECT id_empleado, nombre, apellido, COALESCE(puesto, 'Empleado') AS puesto, 
                     COALESCE(telefono, 'N/A') AS telefono, COALESCE(salario_por_hora, 0.00) AS salario_por_hora,
                     CASE WHEN activo = true THEN 'Activo' ELSE 'Inactivo' END AS estado,
                     COALESCE(codigo_qr, CONCAT('EMP-', id_empleado)) AS codigo_qr
              FROM empleados 
              ORDER BY id_empleado DESC";

    $stmt = $conexion->prepare($query);
    $stmt->execute();
    $empleados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["status" => "success", "data" => $empleados]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
}