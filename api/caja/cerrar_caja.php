<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['caja_sesion_id']) || !isset($input['monto_final_real'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios (caja_sesion_id, monto_final_real)"]);
    exit();
}

$caja_sesion_id = (int)$input['caja_sesion_id'];
$monto_final_real = (float)$input['monto_final_real'];
$notas = !empty($input['notas']) ? trim($input['notas']) : null;

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // 1. Obtener la sesión activa
    $stmtSesion = $conn->prepare("SELECT id, monto_inicial, estado, fecha_apertura FROM cajas_sesiones WHERE id = ? FOR UPDATE");
    $stmtSesion->execute([$caja_sesion_id]);
    $sesion = $stmtSesion->fetch();

    if (!$sesion) {
        throw new Exception("La sesión de caja no existe.");
    }
    if ($sesion['estado'] === 'cerrada') {
        throw new Exception("Esta sesión de caja ya fue cerrada previamente.");
    }

    $fecha_apertura = $sesion['fecha_apertura'];
    $monto_inicial = (float)$sesion['monto_inicial'];

    // 2. Ventas y cobros agrupados por método de pago desde la apertura
    $stmtVentas = $conn->prepare("
        SELECT 
            p.metodo_pago,
            COALESCE(SUM(p.monto), 0) AS total_metodo,
            COALESCE(SUM(o.propina), 0) AS propinas_metodo
        FROM pagos p
        JOIN ordenes o ON p.orden_id = o.id
        WHERE p.fecha_pago >= ?
        GROUP BY p.metodo_pago
    ");
    $stmtVentas->execute([$fecha_apertura]);
    $ventas_por_metodo = $stmtVentas->fetchAll();

    $total_efectivo = 0.00;
    $total_tarjeta = 0.00;
    $total_transferencia = 0.00;
    $propinas_efectivo = 0.00;
    $propinas_electronicas = 0.00;

    foreach ($ventas_por_metodo as $v) {
        $monto = (float)$v['total_metodo'];
        $prop = (float)$v['propinas_metodo'];

        if ($v['metodo_pago'] === 'efectivo') {
            $total_efectivo += $monto;
            $propinas_efectivo += $prop;
        } elseif (in_array($v['metodo_pago'], ['tarjeta_debito', 'tarjeta_credito'])) {
            $total_tarjeta += $monto;
            $propinas_electronicas += $prop;
        } elseif ($v['metodo_pago'] === 'transferencia') {
            $total_transferencia += $monto;
            $propinas_electronicas += $prop;
        }
    }

    // 3. Totales globales de propinas y descuentos aplicados durante el turno
    $stmtGlobales = $conn->prepare("
        SELECT 
            COALESCE(SUM(o.propina), 0) AS total_propinas,
            COALESCE(SUM(o.descuento), 0) AS total_descuentos,
            COUNT(o.id) AS total_ordenes_cobradas
        FROM pagos p
        JOIN ordenes o ON p.orden_id = o.id
        WHERE p.fecha_pago >= ?
    ");
    $stmtGlobales->execute([$fecha_apertura]);
    $globales = $stmtGlobales->fetch();

    $total_propinas = (float)$globales['total_propinas'];
    $total_descuentos = (float)$globales['total_descuentos'];
    $ordenes_cobradas = (int)$globales['total_ordenes_cobradas'];

    // 4. Arqueo de efectivo en cajón: Fondo Inicial + Todo el efectivo cobrado
    $monto_estimado = $monto_inicial + $total_efectivo;
    $diferencia = $monto_final_real - $monto_estimado;

    // 5. Guardar cierre
    $stmtCierre = $conn->prepare("
        UPDATE cajas_sesiones 
        SET monto_final_estimado = ?, 
            monto_final_real = ?, 
            diferencia = ?, 
            fecha_cierre = NOW(), 
            estado = 'cerrada',
            notas = COALESCE(CONCAT(IFNULL(notas, ''), ' | Cierre: ', ?), notas)
        WHERE id = ?
    ");
    $stmtCierre->execute([$monto_estimado, $monto_final_real, $diferencia, $notas, $caja_sesion_id]);

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Caja cerrada y arqueo finalizado",
        "data" => [
            "caja_sesion_id" => $caja_sesion_id,
            "ordenes_cobradas" => $ordenes_cobradas,
            "fondo_inicial" => $monto_inicial,
            "totales_ventas" => [
                "efectivo" => $total_efectivo,
                "tarjetas" => $total_tarjeta,
                "transferencias" => $total_transferencia,
                "gran_total" => ($total_efectivo + $total_tarjeta + $total_transferencia)
            ],
            "balance_caja_fisica" => [
                "esperado_en_caja" => $monto_estimado,
                "contado_por_cajero" => $monto_final_real,
                "diferencia" => $diferencia // 0 = exacto, -X = faltante, +X = sobrante
            ],
            "resumen_adicional" => [
                "total_propinas" => $total_propinas,
                "propinas_efectivo" => $propinas_efectivo,
                "propinas_electronicas" => $propinas_electronicas, // Muy útil para saber cuánto pagarle a meseros de vouchers
                "total_descuentos_otorgados" => $total_descuentos
            ]
        ]
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}