<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

// Opcional: filtrar por estación específica si la cocina tiene pantallas separadas
$estacion_id = isset($_GET['estacion_id']) ? (int)$_GET['estacion_id'] : null;

try {
    // 1. Obtener órdenes activas
    $sqlOrdenes = "
        SELECT o.id AS orden_id, o.folio, o.tipo_pedido, o.estado, o.notas, o.creado_en,
               m.numero AS mesa, u.nombre AS mesero
        FROM ordenes o
        LEFT JOIN mesas m ON o.mesa_id = m.id
        JOIN usuarios u ON o.mesero_id = u.id
        WHERE o.estado IN ('abierta', 'cuenta_pedida')
        ORDER BY o.creado_en ASC
    ";
    $stmtOrdenes = $conn->query($sqlOrdenes);
    $ordenes = $stmtOrdenes->fetchAll();

    $resultado = [];

    // 2. Obtener los renglones pendientes por cada orden
    $sqlDetalle = "
        SELECT od.id AS detalle_id, od.cantidad, od.notas_cocina, od.estado_kds, od.hora_envio,
               p.nombre AS producto, k.nombre AS estacion, k.id AS estacion_id
        FROM orden_detalles od
        JOIN productos p ON od.producto_id = p.id
        JOIN kds_estaciones k ON od.estacion_kds_id = k.id
        WHERE od.orden_id = :orden_id
          AND od.estado_kds IN ('pendiente', 'en_preparacion')
    ";
    if ($estacion_id) {
        $sqlDetalle .= " AND od.estacion_kds_id = :estacion_id";
    }

    $stmtDetalle = $conn->prepare($sqlDetalle);

    foreach ($ordenes as $orden) {
        $params = [':orden_id' => $orden['orden_id']];
        if ($estacion_id) {
            $params[':estacion_id'] = $estacion_id;
        }

        $stmtDetalle->execute($params);
        $platillos = $stmtDetalle->fetchAll();

        // Solo incluir comandas que tengan platillos pendientes de preparar
        if (!empty($platillos)) {
            $orden['platillos'] = $platillos;
            $resultado[] = $orden;
        }
    }

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total_ordenes" => count($resultado),
        "data" => $resultado
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al obtener comandas de cocina: " . $e->getMessage()]);
}