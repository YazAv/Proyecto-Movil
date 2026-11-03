<?php
// api/estaciones_kds/obtener_comandas.php
header('Content-Type: application/json');

if (file_exists(__DIR__ . '/../../config/conexion.php')) {
    require_once __DIR__ . '/../../config/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
}

$estacion = isset($_GET['estacion']) ? trim($_GET['estacion']) : 'cocina';
$ver_historial = isset($_GET['historial']) && $_GET['historial'] === 'true';

// Soportar tanto la clase Database() como la variable $conn directa
if (!isset($conn) && class_exists('Database')) {
    $db = new Database();
    $conn = $db->getConnection();
}

try {
    // Filtrar entregados vs pendientes (insensible a mayúsculas/minúsculas)
    $condicionEstado = $ver_historial 
        ? "LOWER(COALESCE(od.estado_preparacion, od.estado_kds, '')) IN ('entregado', 'listo')"
        : "LOWER(COALESCE(od.estado_preparacion, od.estado_kds, 'pendiente')) NOT IN ('entregado', 'cancelado')";

    $query = "
        SELECT 
            o.id_orden AS orden_id,
            COALESCE(m.numero_mesa, 'Mostrador') AS numero_mesa,
            COALESCE(a.nombre, 'Salón Principal') AS area_nombre,
            TO_CHAR(o.creado_en, 'HH24:MI') AS hora_orden,
            EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - o.creado_en))/60 AS minutos_transcurridos,
            od.id_detalle AS detalle_id,
            p.nombre AS producto,
            od.cantidad,
            COALESCE(od.notas, '') AS notas,
            LOWER(COALESCE(od.estado_preparacion, od.estado_kds, 'pendiente')) AS estado_preparacion,
            COALESCE(c.estacion, 'cocina') AS estacion
        FROM ordenes o
        LEFT JOIN mesas m ON m.id_mesa = o.id_mesa
        LEFT JOIN areas a ON a.id_area = m.id_area
        JOIN orden_detalles od ON od.orden_id = o.id_orden
        JOIN productos p ON p.id_producto = od.producto_id
        LEFT JOIN categorias c ON c.id_categoria = p.id_categoria
        WHERE LOWER(o.estado) NOT IN ('pagada', 'cancelada')
          AND LOWER(COALESCE(c.estacion, 'cocina')) = :estacion
          AND {$condicionEstado}
        ORDER BY o.creado_en ASC, od.id_detalle ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute([':estacion' => strtolower($estacion)]);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $comandas = [];
    foreach ($filas as $fila) {
        $oid = $fila['orden_id'];
        if (!isset($comandas[$oid])) {
            $comandas[$oid] = [
                'orden_id'              => $fila['orden_id'],
                'numero_mesa'           => $fila['numero_mesa'],
                'area_nombre'           => $fila['area_nombre'],
                'hora_orden'            => $fila['hora_orden'],
                'minutos_transcurridos' => max(0, round((float)$fila['minutos_transcurridos'])),
                'detalles'              => []
            ];
        }

        $comandas[$oid]['detalles'][] = [
            'detalle_id'         => $fila['detalle_id'],
            'producto'           => $fila['producto'],
            'cantidad'           => $fila['cantidad'],
            'notas'              => $fila['notas'],
            'estado_preparacion' => $fila['estado_preparacion']
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
    echo json_encode([
        "status"  => "error", 
        "message" => "Error BD: " . $e->getMessage()
    ]);
}
?>