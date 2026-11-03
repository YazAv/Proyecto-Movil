<?php
// api/comandas/listar_pendientes_cocina.php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/../../config/conexion.php';

if (!isset($conn) && class_exists('Database')) {
    $db = new Database();
    $conn = $db->getConnection();
}

try {
    // Buscar detalles pendientes o en preparación de órdenes abiertas
    $stmt = $conn->query("
        SELECT 
            o.id_orden, 
            o.folio, 
            o.creado_en AS fecha_orden, 
            COALESCE(m.numero_mesa, 'Mostrador') AS numero_mesa,
            od.id_detalle, 
            od.cantidad, 
            od.notas, 
            od.estado_kds, 
            p.nombre AS producto
        FROM orden_detalles od
        JOIN ordenes o ON o.id_orden = od.orden_id
        JOIN productos p ON p.id_producto = od.producto_id
        LEFT JOIN mesas m ON m.id_mesa = o.id_mesa
        WHERE UPPER(od.estado_kds) IN ('PENDIENTE', 'EN_PREPARACION')
          AND UPPER(o.estado) = 'ABIERTA'
        ORDER BY o.creado_en ASC, o.id_orden ASC, od.id_detalle ASC
    ");
    
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar por Orden/Ticket para mostrarlo en forma de "Tarjetas" en la cocina
    $comandas = [];
    foreach ($resultados as $row) {
        $id_orden = $row['id_orden'];
        if (!isset($comandas[$id_orden])) {
            $comandas[$id_orden] = [
                'id_orden'    => $id_orden,
                'folio'       => $row['folio'],
                'numero_mesa' => $row['numero_mesa'],
                'fecha_orden' => $row['fecha_orden'],
                'detalles'    => []
            ];
        }
        $comandas[$id_orden]['detalles'][] = [
            'id_detalle' => $row['id_detalle'],
            'cantidad'   => $row['cantidad'],
            'producto'   => $row['producto'],
            'notas'      => $row['notas'],
            'estado_kds' => $row['estado_kds']
        ];
    }

    echo json_encode(array_values($comandas));

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>