<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    // Consulta con tiempo estimado y minutos transcurridos desde que se envió la comanda
    $sql = "
        SELECT 
            od.id AS detalle_id,
            o.id AS orden_id,
            o.folio,
            m.numero AS mesa_numero,
            p.nombre AS platillo,
            od.cantidad,
            od.notas_cocina,
            od.estado_kds,
            p.tiempo_preparacion_min,
            od.creado_en AS hora_pedido,
            TIMESTAMPDIFF(MINUTE, od.creado_en, NOW()) AS minutos_transcurridos,
            ke.nombre AS estacion
        FROM orden_detalles od
        INNER JOIN ordenes o ON od.orden_id = o.id
        INNER JOIN productos p ON od.producto_id = p.id
        LEFT JOIN mesas m ON o.mesa_id = m.id
        LEFT JOIN kds_estaciones ke ON p.estacion_kds_id = ke.id
        WHERE od.estado_kds IN ('pendiente', 'en_preparacion')
        ORDER BY od.creado_en ASC
    ";

    $stmt = $conn->query($sql);
    $pendientes = $stmt->fetchAll();

    // Marcado semafórico para el KDS
    $resultados = array_map(function($item) {
        $transcurrido = (int)$item['minutos_transcurridos'];
        $estimado = (int)$item['tiempo_preparacion_min'];

        if ($transcurrido >= $estimado) {
            $alerta = 'retrasado'; // Rojo
        } elseif ($transcurrido >= ($estimado * 0.7)) {
            $alerta = 'alerta';    // Amarillo
        } else {
            $alerta = 'a_tiempo';  // Verde
        }

        $item['alerta_tiempo'] = $alerta;
        return $item;
    }, $pendientes);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total" => count($resultados),
        "data" => $resultados
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al listar cocina: " . $e->getMessage()]);
}