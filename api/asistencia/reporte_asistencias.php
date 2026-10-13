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

    $periodo = $_GET['periodo'] ?? 'hoy';
    $fechaInicio = $_GET['fecha_inicio'] ?? date('Y-m-d');
    $fechaFin = $_GET['fecha_fin'] ?? date('Y-m-d');

    // Filtros de fecha según periodo seleccionado
    if ($periodo === 'hoy') {
        $whereFecha = "WHERE a.fecha = CURRENT_DATE";
    } elseif ($periodo === 'semana') {
        $whereFecha = "WHERE a.fecha >= date_trunc('week', CURRENT_DATE) AND a.fecha <= CURRENT_DATE";
    } elseif ($periodo === 'quincena') {
        $whereFecha = "WHERE a.fecha >= (CURRENT_DATE - INTERVAL '15 days') AND a.fecha <= CURRENT_DATE";
    } elseif ($periodo === 'personalizado') {
        $whereFecha = "WHERE a.fecha BETWEEN '$fechaInicio' AND '$fechaFin'";
    } else {
        $whereFecha = "";
    }

    // Consulta para obtener todos los registros de asistencias del rango
    $query = "SELECT 
                a.id_asistencia,
                COALESCE(a.id_empleado, a.usuario_id) AS id_empleado,
                COALESCE(e.nombre, 'Empleado') AS nombre,
                COALESCE(e.apellido, '') AS apellido,
                COALESCE(e.puesto, 'Personal') AS puesto,
                COALESCE(e.salario_hora, 50.00) AS salario_hora,
                a.tipo,
                a.fecha,
                TO_CHAR(a.hora, 'HH24:MI:SS') AS hora
              FROM asistencias a
              LEFT JOIN empleados e ON (a.id_empleado = e.id_empleado OR a.usuario_id = e.id_empleado)
              $whereFecha
              ORDER BY COALESCE(a.id_empleado, a.usuario_id) ASC, a.fecha ASC, a.hora ASC";

    $stmt = $conexion->prepare($query);
    $stmt->execute();
    $rawLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar entradas y salidas por empleado y fecha
    $reporte = [];
    $totales = [
        'turnos' => 0,
        'horas' => 0,
        'nomina' => 0
    ];

    $empleadosProcesados = [];

    foreach ($rawLogs as $log) {
        $empId = $log['id_empleado'];
        $fecha = $log['fecha'];
        $key = $empId . '_' . $fecha;

        if (!isset($empleadosProcesados[$key])) {
            $empleadosProcesados[$key] = [
                'id_empleado' => $empId,
                'nombre' => trim($log['nombre'] . ' ' . $log['apellido']),
                'puesto' => $log['puesto'],
                'salario_hora' => floatval($log['salario_hora']),
                'fecha' => $fecha,
                'hora_entrada' => null,
                'hora_salida' => null,
                'horas_trabajadas' => 0,
                'pago_turno' => 0,
                'estado' => 'INCOMPLETO'
            ];
        }

        if ($log['tipo'] === 'ENTRADA' && !$empleadosProcesados[$key]['hora_entrada']) {
            $empleadosProcesados[$key]['hora_entrada'] = $log['hora'];
        } elseif ($log['tipo'] === 'SALIDA') {
            $empleadosProcesados[$key]['hora_salida'] = $log['hora'];
        }
    }

    // Calcular la diferencia de horas y montos
    foreach ($empleadosProcesados as $item) {
        if ($item['hora_entrada'] && $item['hora_salida']) {
            $t1 = strtotime($item['fecha'] . ' ' . $item['hora_entrada']);
            $t2 = strtotime($item['fecha'] . ' ' . $item['hora_salida']);

            if ($t2 > $t1) {
                $diffSegundos = $t2 - $t1;
                $horasDecimal = round($diffSegundos / 3600, 2);
            } else {
                $horasDecimal = 0;
            }

            $item['horas_trabajadas'] = $horasDecimal;
            $item['pago_turno'] = round($horasDecimal * $item['salario_hora'], 2);
            $item['estado'] = 'COMPLETADO';

            $totales['turnos']++;
            $totales['horas'] += $horasDecimal;
            $totales['nomina'] += $item['pago_turno'];
        } elseif ($item['hora_entrada']) {
            $item['estado'] = 'EN TURNO';
            $totales['turnos']++;
        }

        $reporte[] = $item;
    }

    echo json_encode([
        "status" => "success",
        "totales" => [
            "turnos" => $totales['turnos'],
            "horas" => number_format($totales['horas'], 2),
            "nomina" => number_format($totales['nomina'], 2)
        ],
        "data" => $reporte
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage(), "data" => []]);
}