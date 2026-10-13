<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json');

$estacion = isset($_GET['estacion']) ? trim($_GET['estacion']) : 'cocina';
$ver_historial = isset($_GET['historial']) && $_GET['historial'] === 'true';

$db = new Database();
$conn = $db->getConnection();

try {
    // Si solicita historial, muestra las últimas 10 entregadas
    $condicionEstado = $ver_historial 
        ? "od.estado_preparacion = 'entregado'"
        : "(od.estado_preparacion IS NULL OR od.estado_preparacion != 'entregado')";

    $query = "
        SELECT 
            o.id AS orden_id,
            m.numero_mesa,
            a.nombre AS area_nombre,
            o.creado_en AS hora_orden,
            EXTRACT(EPOCH FROM (NOW() - o.creado_en))/60 AS minutos_transcurridos,
            od.id AS detalle_id,
            p.nombre AS producto,
            od.cantidad,
            o.notas AS notas,
            od.estado_preparacion,
            c.estacion
        FROM ordenes o
        JOIN mesas m ON m.id_mesa = o.id_mesa OR m.id = o.mesa_id
        JOIN areas a ON a.id_area = m.id_area
        JOIN orden_detalles od ON od.orden_id = o.id
        JOIN productos p ON p.id = od.producto_id
        JOIN categorias c ON c.id = p.categoria_id
        WHERE o.estado NOT IN ('pagada', 'cancelada')
          AND LOWER(c.estacion) = :estacion
          AND {$condicionEstado}
        ORDER BY o.creado_en ASC, od.id ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute([':estacion' => strtolower($estacion)]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $comandas = [];
    foreach ($filas as $fila) {
        $oid = $fila['orden_id'];
        if (!isset($comandas[$oid])) {
            $comandas[$oid] = [
                'orden_id'             => $fila['orden_id'],
                'numero_mesa'          => $fila['numero_mesa'],
                'area_nombre'          => $fila['area_nombre'],
                'hora_orden'           => $fila['hora_orden'],
                'minutos_transcurridos'=> round((float)$fila['minutos_transcurridos']),
                'detalles'             => []
            ];
        }

        $comandas[$oid]['detalles'][] = [
            'detalle_id'         => $fila['detalle_id'],
            'producto'           => $fila['producto'],
            'cantidad'           => $fila['cantidad'],
            'notas'              => $fila['notas'] ?? '',
            'estado_preparacion' => $fila['estado_preparacion'] ?? 'pendiente'
        ];
    }

    http_response_code(200);
    echo json_encode([
        "status"   => "success",
        "estacion" => $estacion,
        "comandas" => array_values($comandas)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
}