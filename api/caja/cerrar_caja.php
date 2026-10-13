<?php
// api/caja/cerrar_turno.php
header('Content-Type: application/json');

if (file_exists(__DIR__ . '/../../config/conexion.php')) {
    require_once __DIR__ . '/../../config/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
}

try {
    $data = json_decode(file_get_contents("php://input"), true);
    $id_turno = intval($data['id_turno'] ?? 0);
    $efectivo_declarado = floatval($data['efectivo_declarado'] ?? 0);
    $observaciones = $data['observaciones'] ?? '';

    if ($id_turno <= 0) {
        throw new Exception("ID de turno inválido.");
    }

    $conn->beginTransaction();

    // Obtener fondo inicial
    $stmtT = $conn->prepare("SELECT fondo_inicial FROM turnos_caja WHERE id_turno = ?");
    $stmtT->execute([$id_turno]);
    $fondo = floatval($stmtT->fetchColumn());

    // Obtener ventas en efectivo de este turno
    $stmtV = $conn->prepare("SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE id_turno = ? AND UPPER(metodo_pago) LIKE '%EFECTIVO%'");
    $stmtV->execute([$id_turno]);
    $ventas_efectivo = floatval($stmtV->fetchColumn());

    $efectivo_calculado = $fondo + $ventas_efectivo;
    $diferencia = $efectivo_declarado - $efectivo_calculado;

    // Actualizar turno a cerrado
    $stmtC = $conn->prepare("
        UPDATE turnos_caja 
        SET efectivo_declarado = ?, 
            diferencia = ?, 
            observaciones = ?, 
            estado = 'cerrado', 
            fecha_cierre = CURRENT_TIMESTAMP 
        WHERE id_turno = ?
    ");
    $stmtC->execute([$efectivo_declarado, $diferencia, $observaciones, $id_turno]);

    $conn->commit();

    echo json_encode(['status' => 'success', 'id_turno' => $id_turno]);
} catch(Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>