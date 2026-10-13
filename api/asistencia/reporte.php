<?php
error_reporting(0);
ini_set('display_errors', 0);
date_default_timezone_set('America/Mexico_City');
header("Content-Type: application/json; charset=UTF-8");

function normalizarFechaSQL($fechaStr) {
    if (empty($fechaStr)) return date('Y-m-d');
    if (strpos($fechaStr, '/') !== false) {
        $parts = explode('/', $fechaStr);
        if (count($parts) === 3) {
            return sprintf('%04d-%02d-%02d', $parts[2], $parts[1], $parts[0]);
        }
    }
    return $fechaStr;
}

try {
    require_once "../../config/conexion.php";

    $db = new Database();
    if (method_exists($db, 'getConexion')) { $conexion = $db->getConexion(); }
    elseif (method_exists($db, 'conectar')) { $conexion = $db->conectar(); }
    elseif (method_exists($db, 'getConnection')) { $conexion = $db->getConnection(); }
    elseif (property_exists($db, 'pdo')) { $conexion = $db->pdo; }
    elseif (property_exists($db, 'conexion')) { $conexion = $db->conexion; }
    else { $conexion = $db; }

    if (!$conexion) {
        throw new Exception("No se pudo establecer la conexión a la base de datos.");
    }

    $conexion->exec("SET TIME ZONE 'America/Mexico_City'");

    $periodo = $_GET['periodo'] ?? '';
    $fInicio = normalizarFechaSQL($_GET['inicio'] ?? $_GET['fecha_inicio'] ?? '');
    $fFin = normalizarFechaSQL($_GET['fin'] ?? $_GET['fecha_fin'] ?? '');
    $empleadoParam = $_GET['empleado_id'] ?? $_GET['empleado'] ?? '';

    $whereClauses = [];

    if ($periodo === 'hoy') {
        $whereClauses[] = "a.fecha = CURRENT_DATE";
    } elseif ($periodo === 'semana') {
        $whereClauses[] = "a.fecha >= (CURRENT_DATE - INTERVAL '7 days')";
    } elseif ($periodo === 'quincena') {
        $whereClauses[] = "a.fecha >= (CURRENT_DATE - INTERVAL '15 days')";
    } elseif ($periodo === 'mes') {
        $whereClauses[] = "a.fecha >= date_trunc('month', CURRENT_DATE)";
    } elseif (!empty($fInicio) && !empty($fFin)) {
        $whereClauses[] = "a.fecha BETWEEN '$fInicio' AND '$fFin'";
    }

    if (!empty($empleadoParam) && $empleadoParam !== 'todos') {
        preg_match('/\d+/', $empleadoParam, $matches);
        $empIdLimpio = isset($matches[0]) ? intval($matches[0]) : 0;
        if ($empIdLimpio > 0) {
            $whereClauses[] = "(a.id_empleado = $empIdLimpio OR a.usuario_id = $empIdLimpio)";
        }
    }

    $whereSql = count($whereClauses) > 0 ? "WHERE " . implode(" AND ", $whereClauses) : "";

    // CONSULTA CORREGIDA: Cambiamos a.id_asistencia por a.id
    $query = "SELECT 
                a.id,
                COALESCE(a.id_empleado, a.usuario_id) AS id_empleado,
                COALESCE(e.nombre, 'Empleado') AS nombre,
                COALESCE(e.apellido, '') AS apellido,
                COALESCE(e.puesto, 'Personal') AS puesto,
                COALESCE(e.salario_por_hora, 0.00) AS salario_por_hora,
                a.tipo,
                a.fecha,
                TO_CHAR(a.hora, 'HH24:MI:SS') AS hora
              FROM asistencias a
              LEFT JOIN empleados e ON (a.id_empleado = e.id_empleado OR a.usuario_id = e.id_empleado)
              $whereSql
              ORDER BY COALESCE(a.id_empleado, a.usuario_id) ASC, a.fecha ASC, a.id ASC";

    $stmt = $conexion->prepare($query);
    $stmt->execute();
    $rawLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totales = ['turnos' => 0, 'horas' => 0, 'nomina' => 0];
    $agrupados = [];

    foreach ($rawLogs as $log) {
        $empId = $log['id_empleado'];
        $fecha = $log['fecha'];
        $key = $empId . '_' . $fecha;

        if (!isset($agrupados[$key])) {
            $agrupados[$key] = [
                'id_empleado' => $empId,
                'nombre' => trim($log['nombre'] . ' ' . $log['apellido']),
                'puesto' => $log['puesto'],
                'salario_hora' => floatval($log['salario_por_hora']),
                'fecha' => $fecha,
                'hora_entrada' => null,
                'hora_salida' => null,
                'horas_trabajadas' => 0,
                'pago_turno' => 0,
                'estado' => 'INCOMPLETO'
            ];
        }

        if ($log['tipo'] === 'ENTRADA') {
            $agrupados[$key]['hora_entrada'] = $log['hora'];
        } elseif ($log['tipo'] === 'SALIDA') {
            $agrupados[$key]['hora_salida'] = $log['hora'];
        }
    }

    $reporteFinal = [];
    foreach ($agrupados as $item) {
        if ($item['hora_entrada'] && $item['hora_salida']) {
            $t1 = strtotime($item['fecha'] . ' ' . $item['hora_entrada']);
            $t2 = strtotime($item['fecha'] . ' ' . $item['hora_salida']);

            if ($t2 >= $t1) {
                $diffSeg = $t2 - $t1;
                $horasDec = round($diffSeg / 3600, 2);
                if ($horasDec == 0 && $diffSeg > 0) $horasDec = 0.05; 
            } else {
                $horasDec = 0;
            }

            $item['horas_trabajadas'] = $horasDec;
            $item['pago_turno'] = round($horasDec * $item['salario_hora'], 2);
            $item['estado'] = 'COMPLETADO';

            $totales['turnos']++;
            $totales['horas'] += $horasDec;
            $totales['nomina'] += $item['pago_turno'];
        } elseif ($item['hora_entrada']) {
            $item['estado'] = 'EN TURNO';
            $totales['turnos']++;
        }

        $reporteFinal[] = $item;
    }

    echo json_encode([
        "status" => "success",
        "totales" => [
            "turnos" => $totales['turnos'],
            "horas" => number_format($totales['horas'], 2),
            "nomina" => number_format($totales['nomina'], 2)
        ],
        "data" => array_values($reporteFinal)
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Error de BD: " . $e->getMessage(),
        "totales" => ["turnos" => 0, "horas" => "0.00", "nomina" => "0.00"],
        "data" => []
    ]);
}