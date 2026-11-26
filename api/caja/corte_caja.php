<?php
// api/caja/corte_caja.php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');
error_reporting(E_ALL);
ini_set('display_errors', 0);

$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../../conexion.php',
    __DIR__ . '/../conexion.php',
    __DIR__ . '/conexion.php'
];

$conexion_ok = false;
foreach ($rutas as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        $conexion_ok = true;
        break;
    }
}

if (!$conexion_ok) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'No se encontró config/conexion.php']);
    exit;
}

if (!isset($conn) && class_exists('Database')) {
    $db = new Database();
    $conn = $db->getConnection();
}

function obtenerResumenTurno(PDO $conn, int $idTurno): array {
    $stmtT = $conn->prepare("
        SELECT
            id_turno,
            COALESCE(NULLIF(fondo_inicial, 0), monto_inicial, 0)::numeric(10,2) AS fondo_inicial,
            monto_final,
            estado,
            tipo_turno,
            fecha_apertura,
            fecha_cierre
        FROM public.turnos_caja
        WHERE id_turno = ?
    ");
    $stmtT->execute([$idTurno]);
    $turno = $stmtT->fetch(PDO::FETCH_ASSOC);

    if (!$turno) {
        return [];
    }

    $stmtP = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN UPPER(TRIM(COALESCE(metodo_pago, ''))) LIKE '%EFECTIVO%' THEN monto_pagado ELSE 0 END), 0)::numeric(10,2) AS total_efectivo,
            COALESCE(SUM(CASE WHEN UPPER(TRIM(COALESCE(metodo_pago, ''))) LIKE '%TRANSFER%' THEN monto_pagado ELSE 0 END), 0)::numeric(10,2) AS total_transferencia,
            COALESCE(SUM(CASE WHEN UPPER(TRIM(COALESCE(metodo_pago, ''))) LIKE '%TARJETA%' OR UPPER(TRIM(COALESCE(metodo_pago, ''))) LIKE '%DEBITO%' OR UPPER(TRIM(COALESCE(metodo_pago, ''))) LIKE '%CREDITO%' THEN monto_pagado ELSE 0 END), 0)::numeric(10,2) AS total_tarjeta,
            COALESCE(SUM(monto_pagado), 0)::numeric(10,2) AS total_vendido,
            COUNT(*)::int AS total_pagos
        FROM public.pagos
        WHERE id_turno = ?
    ");
    $stmtP->execute([$idTurno]);
    $pagos = $stmtP->fetch(PDO::FETCH_ASSOC);

    $fondo = floatval($turno['fondo_inicial']);
    $efectivo = floatval($pagos['total_efectivo']);
    $transferencia = floatval($pagos['total_transferencia']);
    $tarjeta = floatval($pagos['total_tarjeta']);
    $totalVendido = floatval($pagos['total_vendido']);
    $efectivoEsperado = round($fondo + $efectivo, 2);

    return [
        'id_turno' => intval($turno['id_turno']),
        'estado' => $turno['estado'],
        'tipo_turno' => $turno['tipo_turno'],
        'fecha_apertura' => $turno['fecha_apertura'],
        'fecha_cierre' => $turno['fecha_cierre'],
        'fondo_inicial' => round($fondo, 2),
        'total_efectivo' => round($efectivo, 2),
        'total_transferencia' => round($transferencia, 2),
        'total_tarjeta' => round($tarjeta, 2),
        'total_vendido' => round($totalVendido, 2),
        'total_pagos' => intval($pagos['total_pagos']),
        'efectivo_esperado' => $efectivoEsperado
    ];
}

try {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_GET ?: $_POST ?: [];
    }
    $accion = $data['accion'] ?? 'resumen';

    if ($accion === 'resumen' || $accion === 'estado') {
        $idSolicitado = intval($data['id_turno'] ?? 0);
        if ($idSolicitado > 0) {
            $idTurno = $idSolicitado;
        } else {
            $stmtAbierto = $conn->query("SELECT id_turno FROM public.turnos_caja WHERE LOWER(estado) = 'abierto' ORDER BY id_turno DESC LIMIT 1");
            $idTurno = intval($stmtAbierto->fetchColumn() ?: 0);
            if ($idTurno <= 0) {
                $stmtUltimo = $conn->query("SELECT id_turno FROM public.turnos_caja ORDER BY id_turno DESC LIMIT 1");
                $idTurno = intval($stmtUltimo->fetchColumn() ?: 0);
            }
        }

        if ($idTurno <= 0) {
            echo json_encode(['status' => 'success', 'turno' => null]);
            exit;
        }

        echo json_encode([
            'status' => 'success',
            'turno' => obtenerResumenTurno($conn, $idTurno)
        ]);
        exit;
    }

    if ($accion === 'abrir') {
        $montoInicial = floatval($data['monto_inicial'] ?? $data['fondo'] ?? 0);
        $tipoTurno = trim((string)($data['tipo_turno'] ?? 'general'));
        $idEmpleado = intval($data['id_empleado'] ?? 1);

        $stmtCheck = $conn->query("SELECT id_turno FROM public.turnos_caja WHERE LOWER(estado) = 'abierto' ORDER BY id_turno DESC LIMIT 1");
        if ($stmtCheck->fetchColumn()) {
            throw new Exception("Ya existe un turno abierto. Ciérralo antes de abrir uno nuevo.");
        }

        $stmtIns = $conn->prepare("
            INSERT INTO public.turnos_caja (id_usuario, id_empleado, monto_inicial, fondo_inicial, tipo_turno, estado, fecha_apertura, fecha_inicio)
            VALUES (?, ?, ?, ?, ?, 'abierto', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id_turno
        ");
        $stmtIns->execute([$idEmpleado, $idEmpleado, $montoInicial, $montoInicial, $tipoTurno]);
        $idTurno = intval($stmtIns->fetchColumn());

        echo json_encode([
            'status' => 'success',
            'message' => 'Turno abierto correctamente.',
            'turno' => obtenerResumenTurno($conn, $idTurno)
        ]);
        exit;
    }

    if ($accion === 'cerrar') {
        $idTurno = intval($data['id_turno'] ?? 0);
        $montoFinal = floatval($data['monto_final'] ?? $data['efectivo_declarado'] ?? 0);

        if ($idTurno <= 0) {
            $stmtAbierto = $conn->query("SELECT id_turno FROM public.turnos_caja WHERE LOWER(estado) = 'abierto' ORDER BY id_turno DESC LIMIT 1");
            $idTurno = intval($stmtAbierto->fetchColumn() ?: 0);
        }
        if ($idTurno <= 0) {
            throw new Exception("No se encontró un turno válido para cerrar.");
        }

        $stmtUpd = $conn->prepare("
            UPDATE public.turnos_caja
            SET estado = 'cerrado',
                monto_final = ?,
                fecha_cierre = CURRENT_TIMESTAMP
            WHERE id_turno = ?
        ");
        $stmtUpd->execute([$montoFinal, $idTurno]);

        echo json_encode([
            'status' => 'success',
            'message' => 'Turno cerrado correctamente.',
            'turno' => obtenerResumenTurno($conn, $idTurno)
        ]);
        exit;
    }

    throw new Exception("Acción no reconocida: '$accion'");
} catch (PDOException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Error de BD: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}