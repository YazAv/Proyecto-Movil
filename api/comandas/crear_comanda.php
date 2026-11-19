<?php
// api/comandas/crear_comanda.php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');

$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../config/conexion.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../conexion.php'
];
foreach ($rutas as$r) { if (file_exists($r)) { require_once$r; break; } }
if (!isset($conn) && isset($pdo)) $conn =$pdo;
if (!isset($conn) && class_exists('Database'))$conn = (new Database())->getConnection();

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['productos'])) {
    echo json_encode(['status' => 'error', 'message' => 'No hay productos en la comanda']);
    exit;
}

try {
    $conn->beginTransaction();

    $id_orden   = !empty($data['id_orden']) ? intval($data['id_orden']) : null;
    $id_mesa    = !empty($data['id_mesa']) ? intval($data['id_mesa']) : null;
    $id_usuario = intval($data['id_usuario'] ?? 1);$subtotal   = floatval($data['subtotal'] ?? 0);$total      = floatval($data['total'] ?? $subtotal);
    $productos  =$data['productos'];

    // Si no viene id_orden pero sí id_mesa, buscar orden ABIERTA de esa mesa
    if (!$id_orden &&$id_mesa) {
        $stmtExist =$conn->prepare("SELECT id_orden, folio FROM public.ordenes WHERE id_mesa = ? AND UPPER(estado) = 'ABIERTA' ORDER BY id_orden DESC LIMIT 1");
        $stmtExist->execute([$id_mesa]);
        $rowExist =$stmtExist->fetch(PDO::FETCH_ASSOC);
        if ($rowExist) {
            $id_orden = intval($rowExist['id_orden']);
            $folio    =$rowExist['folio'];
        }
    }

    if ($id_orden) {
        $stmtUpd =$conn->prepare("UPDATE public.ordenes SET subtotal = ?, total = ? WHERE id_orden = ?");
        $stmtUpd->execute([$subtotal, $total,$id_orden]);
        if (!isset($folio)) {
            $stmtF =$conn->prepare("SELECT folio FROM public.ordenes WHERE id_orden = ?");
            $stmtF->execute([$id_orden]);
            $folio =$stmtF->fetchColumn();
        }
    } else {
        $folio = 'ORD-' . date('Ymd-His');
        $stmtIns =$conn->prepare("
            INSERT INTO public.ordenes (folio, id_mesa, id_usuario, subtotal, total, estado, creado_en)
            VALUES (?, ?, ?, ?, ?, 'ABIERTA', CURRENT_TIMESTAMP)
            RETURNING id_orden
        ");
        $stmtIns->execute([$folio, $id_mesa,$id_usuario, $subtotal,$total]);
        $id_orden = intval($stmtIns->fetchColumn());
    }

    // Consultar nombre y estación real del producto
    $stmtProdInfo =$conn->prepare("
        SELECT p.nombre, COALESCE(LOWER(c.estacion), 'cocina') AS estacion
        FROM public.productos p
        LEFT JOIN public.categorias c ON p.id_categoria = c.id_categoria
        WHERE p.id_producto = ?
    ");

    // Insertar incluyendo nombre_producto (obligatorio en public.orden_detalle)
    $stmtDet =$conn->prepare("
        INSERT INTO public.orden_detalle 
            (id_orden, id_producto, nombre_producto, cantidad, precio_unitario, notas, estacion, estado, subtotal)
        VALUES 
            (?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', ?)
    ");

    foreach ($productos as$prod) {
        if (!empty($prod['ya_enviado'])) continue;
        $idP   = intval($prod['id'] ?? $prod['id_producto']);
        $cant  = intval($prod['cantidad'] ?? 1);
        $prec  = floatval($prod['precio'] ?? 0);
        $notas = trim($prod['notas'] ?? '');

        $stmtProdInfo->execute([$idP]);
        $infoP =$stmtProdInfo->fetch(PDO::FETCH_ASSOC);

        $nombreProd = trim($prod['nombre'] ?? ($infoP['nombre'] ?? 'Platillo'));
        $estacion   =$infoP['estacion'] ?? 'cocina';

        $stmtDet->execute([$id_orden,
            $idP,$nombreProd,
            $cant,$prec,
            $notas,
            $estacion,
            ($prec * $cant)
        ]);
    }

    if ($id_mesa) {
        $stmtM =$conn->prepare("UPDATE public.mesas SET estado = 'ocupada' WHERE id_mesa = ?");
        $stmtM->execute([$id_mesa]);
    }

    $conn->commit();

    echo json_encode([
        'status'   => 'success',
        'id_orden' => $id_orden,
        'folio'    => $folio,
        'message'  => 'Comanda enviada a cocina/barra'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction())$conn->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>