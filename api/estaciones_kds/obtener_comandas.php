<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$estacion_id = isset($_GET['estacion_id']) ? (int)$_GET['estacion_id'] : null;

$db = new Database();
$conn = $db->getConnection();

try {
    $sql = "
        SELECT 
            od.id AS detalle_id,
            od.orden_id,
            o.folio,
            m.numero AS mesa_numero,
            u.nombre AS mesero,
            p.nombre AS platillo,
            od.cantidad,
            od.notas_cocina,
            od.estado_kds,
            od.hora_envio,
            od.hora_inicio_cocina,
            ROUND(TIMESTAMPDIFF(SECOND, od.hora_envio, NOW()) / 60, 1) AS minutos_espera
        FROM orden_detalles od
        INNER JOIN ordenes o ON od.orden_id = o.id
        INNER JOIN mesas m ON o.mesa_id = m.id
        INNER JOIN usuarios u ON o.mesero_id = u.id
        INNER JOIN productos p ON od.producto_id = p.id
        WHERE od.estado_kds IN ('pendiente', 'en_preparacion')
          AND o.estado = 'abierta'
    ";

    $params = [];
    if ($estacion_id) {
        $sql .= " AND od.estacion_kds_id = ?";
        $params[] = $estacion_id;
    }

    $sql .= " ORDER BY od.hora_envio ASC, od.id ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $comandas = [];
    foreach ($items as $row) {
        $oid = $row['orden_id'];
        if (!isset($comandas[$oid])) {
            $comandas[$oid] = [
                "orden_id" => (int)$row['orden_id'],
                "folio" => $row['folio'],
                "mesa" => "Mesa " . $row['mesa_numero'],
                "mesero" => $row['mesero'],
                "hora_envio" => $row['hora_envio'],
                "items" => []
            ];
        }

        $comandas[$oid]['items'][] = [
            "detalle_id" => (int)$row['detalle_id'],
            "platillo" => $row['platillo'],
            "cantidad" => (int)$row['cantidad'],
            "notas_cocina" => $row['notas_cocina'],
            "estado_kds" => $row['estado_kds'],
            "minutos_espera" => (float)$row['minutos_espera']
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total_comandas" => count($comandas),
        "data" => array_values($comandas)
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al obtener comandas KDS: " . $e->getMessage()]);
}