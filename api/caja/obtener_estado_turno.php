<?php
// api/caja/obtener_estado_turno.php
header('Content-Type: application/json');

if (file_exists(__DIR__ . '/../../config/conexion.php')) {
    require_once __DIR__ . '/../../config/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
}

try {
    $stmt = $conn->query("SELECT id_turno, fondo_inicial, fecha_inicio FROM turnos_caja WHERE estado = 'abierto' ORDER BY id_turno DESC LIMIT 1");
    $turno = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($turno) {
        $id_turno = $turno['id_turno'];
        $fondo = floatval($turno['fondo_inicial']);
        
        $stmtPagos = $conn->prepare("SELECT UPPER(metodo_pago) as metodo, SUM(monto) as total FROM pagos WHERE id_turno = ? GROUP BY UPPER(metodo_pago)");
        $stmtPagos->execute([$id_turno]);
        $pagos = $stmtPagos->fetchAll(PDO::FETCH_ASSOC);
        
        $ventas_efectivo = 0;
        $ventas_tarjeta = 0;
        
        foreach ($pagos as $p) {
            if (strpos($p['metodo'], 'EFECTIVO') !== false) {
                $ventas_efectivo += floatval($p['total']);
            } else {
                $ventas_tarjeta += floatval($p['total']);
            }
        }
        
        echo json_encode([
            'abierto' => true,
            'id_turno' => $id_turno,
            'fondo' => $fondo,
            'ventas_efectivo' => $ventas_efectivo,
            'ventas_tarjeta' => $ventas_tarjeta
        ]);
    } else {
        echo json_encode(['abierto' => false]);
    }
} catch(Exception $e) {
    echo json_encode(['abierto' => false, 'message' => $e->getMessage()]);
}
?>