<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once '../../config/conexion.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("
        SELECT o.id_orden, 
               o.total, 
               COALESCE(m.numero_mesa, m.numero, 'Mesa ' || o.id_mesa) AS nombre_mesa
        FROM ordenes o
        LEFT JOIN mesas m ON m.id_mesa = o.id_mesa
        WHERE UPPER(o.estado) = 'ABIERTA' OR UPPER(o.estado) = 'PENDIENTE'
        ORDER BY o.id_orden DESC
    ");
    $stmt->execute();
    $ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultadoFinal = [];
    foreach ($ordenes as $ord) {
        $stmtDet = $conn->prepare("
            SELECT producto_id, nombre_producto, cantidad, precio_unitario 
            FROM detalle_orden 
            WHERE orden_id = :id_orden
        ");
        $stmtDet->execute([':id_orden' => $ord['id_orden']]);
        $productos = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

        $resultadoFinal[] = [
            "id_orden" => $ord['id_orden'],
            "nombre_mesa" => $ord['nombre_mesa'],
            "total" => $ord['total'],
            "productos" => $productos
        ];
    }

    echo json_encode($resultadoFinal);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>