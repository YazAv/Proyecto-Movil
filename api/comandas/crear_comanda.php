<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (
    !isset($input['mesa_id']) || 
    !isset($input['mesero_id']) || 
    !isset($input['detalles']) || 
    !is_array($input['detalles']) || 
    empty($input['detalles'])
) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios"]);
    exit();
}

$mesa_id = (int)$input['mesa_id'];
$mesero_id = (int)$input['mesero_id'];
$tipo_pedido = isset($input['tipo_pedido']) ? $input['tipo_pedido'] : 'comedor';
$notas = !empty($input['notas']) ? trim($input['notas']) : null;
$detalles = $input['detalles'];

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // 1. Folio único
    $stmtFolio = $conn->query("SELECT COUNT(*) AS total FROM ordenes WHERE DATE(creado_en) = CURDATE()");
    $conteo = (int)$stmtFolio->fetch()['total'] + 1;
    $folio = 'ORD-' . date('Ymd') . '-' . str_pad($conteo, 4, '0', STR_PAD_LEFT);

    // 2. Insertar orden
    $stmtOrden = $conn->prepare("
        INSERT INTO ordenes (mesa_id, mesero_id, tipo_pedido, folio, estado, notas, subtotal, total) 
        VALUES (?, ?, ?, ?, 'abierta', ?, 0.00, 0.00)
    ");
    $stmtOrden->execute([$mesa_id, $mesero_id, $tipo_pedido, $folio, $notas]);
    $orden_id = (int)$conn->lastInsertId();

    $subtotal_acumulado = 0.00;

    // 3. Consultas preparadas para los productos
    $stmtProd = $conn->prepare("SELECT precio, estacion_kds_id FROM productos WHERE id = ? AND disponible = 1");
    $stmtDetalle = $conn->prepare("
        INSERT INTO orden_detalles (orden_id, producto_id, estacion_kds_id, cantidad, precio_unitario, subtotal, notas_cocina, estado_kds) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente')
    ");

    foreach ($detalles as $item) {
        $prod_id = (int)$item['producto_id'];
        $cant = max(1, (int)$item['cantidad']);
        $notas_cocina = !empty($item['notas_cocina']) ? trim($item['notas_cocina']) : null;

        $stmtProd->execute([$prod_id]);
        $prod = $stmtProd->fetch();

        if (!$prod) {
            throw new Exception("El producto con ID $prod_id no existe o no está activo.");
        }

        $precio_u = (float)$prod['precio'];
        $subtotal_linea = $precio_u * $cant;
        $subtotal_acumulado += $subtotal_linea;

        $stmtDetalle->execute([
            $orden_id,
            $prod_id,
            (int)$prod['estacion_kds_id'],
            $cant,
            $precio_u,
            $subtotal_linea,
            $notas_cocina
        ]);
    }

    // 4. Actualizar totales
    $stmtTotales = $conn->prepare("UPDATE ordenes SET subtotal = ?, total = ? WHERE id = ?");
    $stmtTotales->execute([$subtotal_acumulado, $subtotal_acumulado, $orden_id]);

    // 5. Ocupar mesa
    $stmtMesa = $conn->prepare("UPDATE mesas SET estado = 'ocupada' WHERE id = ?");
    $stmtMesa->execute([$mesa_id]);

    $conn->commit();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "Comanda enviada a cocina con éxito",
        "data" => [
            "orden_id" => $orden_id,
            "folio" => $folio,
            "total" => $subtotal_acumulado,
            "items" => count($detalles)
        ]
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Fallo al procesar la comanda: " . $e->getMessage()
    ]);
}