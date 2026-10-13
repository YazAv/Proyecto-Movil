<?php
error_reporting(0);
ini_set('display_errors', 0);
date_default_timezone_set('America/Mexico_City');
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

    $conexion->exec("SET TIME ZONE 'America/Mexico_City'");

    // Obtener los registros ordenados del más reciente al más antiguo
    $query = "SELECT 
                a.id_asistencia,
                COALESCE(a.id_empleado, a.usuario_id) AS id_empleado,
                COALESCE(e.nombre, 'Empleado') AS nombre,
                COALESCE(e.apellido, '') AS apellido,
                COALESCE(e.puesto, 'Personal') AS puesto,
                a.tipo,
                TO_CHAR(a.hora, 'HH24:MI:SS') AS hora
              FROM asistencias a
              LEFT JOIN empleados e ON (a.id_empleado = e.id_empleado OR a.usuario_id = e.id_empleado)
              ORDER BY a.id_asistencia DESC
              LIMIT 50";

    $stmt = $conexion->prepare($query);
    $stmt->execute();
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["status" => "success", "data" => $registros ? $registros : []]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage(), "data" => []]);
}