<?php
// api/caja/procesar_pago.php
header('Content-Type: application/json');

// Ajusta a tu ruta real de conexión
if (file_exists(__DIR__ . '/../../config/conexion.php')) {
    require_once __DIR__ . '/../../config/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
}

try {
    // 1. Recibir datos del frontend
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!$data || empty($data['productos'])) {
        throw new Exception("El carrito está vacío o los datos son inválidos.");
    }

    $total = floatval($data['total']);
    $metodo = $data['metodo_pago'] ?? 'Efectivo';
    $id_orden = $data['id_orden'] ?? null; 
    $productos = $data['productos'];
    
    $id_usuario = 1; // Usuario por defecto (Cajero)

    // Iniciar transacción en PostgreSQL
    $conn->beginTransaction();

    // 2. OBTENER AUTOMÁTICAMENTE EL ID DEL TURNO ABIERTO ACTUAL
    $stmtTurnoActual = $conn->query("SELECT id_turno FROM turnos_caja WHERE estado = 'abierto' ORDER BY id_turno DESC LIMIT 1");
    $id_turno_actual = $stmtTurnoActual->fetchColumn();

    if (!$id_turno_actual) {
        throw new Exception("No hay ningún turno de caja abierto. Por favor, abre un turno antes de realizar cobros.");
    }

    if ($id_orden > 0) {
        // A) COBRAR UNA MESA EXISTENTE
        $stmt = $conn->prepare("UPDATE ordenes SET total = ?, estado = 'Pagada' WHERE id_orden = ?");
        $stmt->execute([$total, $id_orden]);
    } else {
        // B) VENTA RÁPIDA (Directo en caja)
        $folio = 'VTA-' . date('YmdHis');
        
        $stmt = $conn->prepare("INSERT INTO ordenes (folio, total, estado, id_usuario, creado_en) VALUES (?, ?, 'Pagada', ?, CURRENT_TIMESTAMP) RETURNING id_orden");
        $stmt->execute([$folio, $total, $id_usuario]);
        $id_orden = $stmt->fetchColumn();

        // Insertar los productos en orden_detalles
        $stmtDetalle = $conn->prepare("INSERT INTO orden_detalles (orden_id, producto_id, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?)");
        
        foreach ($productos as $p) {
            $subtotal = intval($p['cantidad']) * floatval($p['precio']);
            $stmtDetalle->execute([
                $id_orden, 
                $p['id'], 
                $p['cantidad'], 
                $p['precio'], 
                $subtotal
            ]);
        }
    }

    // 3. Registrar el pago vinculado al turno activo de caja
    $stmtPago = $conn->prepare("INSERT INTO pagos (id_orden, id_turno, monto, metodo_pago, fecha_pago) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $stmtPago->execute([$id_orden, $id_turno_actual, $total, $metodo]);

    // Confirmar cambios
    $conn->commit();

    echo json_encode([
        'status' => 'success', 
        'id_orden' => $id_orden, 
        'message' => 'Venta registrada y cobrada con éxito.'
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode([
        'status' => 'error', 
        'message' => $e->getMessage()
    ]);
}
?>