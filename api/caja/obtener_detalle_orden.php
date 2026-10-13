<?php
header('Content-Type: application/json');
require_once '../../config/db.php';

$orden_id = isset($_GET['orden_id']) ? intval($_GET['orden_id']) : 0;

if ($orden_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ID de orden inválido']);
    exit;
}

try {
    $sql = "SELECT 
                od.cantidad,
                p.nombre AS producto,
                od.precio_unitario,
                (od.cantidad * od.precio_unitario) AS importe
            FROM orden_detalles od
            JOIN productos p ON p.id_producto = od.id_producto
            WHERE od.id_orden = :orden_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':orden_id' => $orden_id]);
    $detalles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'detalles' => $detalles
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error al obtener detalle: ' . $e->getMessage()
    ]);
}
?>