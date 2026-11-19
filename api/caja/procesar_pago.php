<?php
// api/caja/procesar_pago.php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');

$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../config/conexion.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../conexion.php'
];
foreach ($rutas as $r) { if (file_exists($r)) { require_once $r; break; } }
if (!isset($conn) && isset($pdo)) $conn = $pdo;
if (!isset($conn) && class_exists('Database')) $conn = (new Database())->getConnection();

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['productos'])) {
    echo json_encode(['status' => 'error', 'message' => 'El carrito está vacío']);
    exit;
}

try {
    $conn->beginTransaction();

    $id_orden    = !empty($data['id_orden']) ? intval($data['id_orden']) : null;
    $id_mesa     = !empty($data['id_mesa']) ? intval($data['id_mesa']) : null;
    $id_usuario  = intval($data['id_usuario'] ?? 1);
    $subtotal    = floatval($data['subtotal'] ?? 0);
    $total       = floatval($data['total'] ?? 0);
    $cambio      = floatval($data['cambio'] ?? 0);
    $productos   = $data['productos'];

    $stmtProdInfo = $conn->prepare("
        SELECT p.nombre, COALESCE(LOWER(c.estacion), 'cocina') AS estacion
        FROM public.productos p
        LEFT JOIN public.categorias c ON p.id_categoria = c.id_categoria
        WHERE p.id_producto = ?
    ");

    $stmtDet = $conn->prepare("
        INSERT INTO public.orden_detalle 
            (id_orden, id_producto, nombre_producto, cantidad, precio_unitario, notas, estacion, estado, subtotal)
        VALUES 
            (?, ?, ?, ?, ?, ?, ?, 'ENTREGADO', ?)
    ");

    if ($id_orden) {
        $stmtUpd = $conn->prepare("
            UPDATE public.ordenes
            SET estado = 'PAGADA', subtotal = ?, total = ?, cerrado_en = CURRENT_TIMESTAMP
            WHERE id_orden = ?
        ");
        $stmtUpd->execute([$subtotal, $total, $id_orden]);

        foreach ($productos as $prod) {
            if (!empty($prod['ya_enviado'])) continue;
            $idP   = intval($prod['id'] ?? $prod['id_producto']);
            $cant  = intval($prod['cantidad'] ?? 1);
            $prec  = floatval($prod['precio'] ?? 0);
            $notas = trim($prod['notas'] ?? '');

            $stmtProdInfo->execute([$idP]);
            $infoP = $stmtProdInfo->fetch(PDO::FETCH_ASSOC);
            $nombreProd = trim($prod['nombre'] ?? ($infoP['nombre'] ?? 'Platillo'));
            $estacion   = $infoP['estacion'] ?? 'cocina';

            $stmtDet->execute([$id_orden, $idP, $nombreProd, $cant, $prec, $notas, $estacion, ($prec * $cant)]);
        }

        if (!$id_mesa) {
            $stmtGetM = $conn->prepare("SELECT id_mesa FROM public.ordenes WHERE id_orden = ?");
            $stmtGetM->execute([$id_orden]);
            $id_mesa = $stmtGetM->fetchColumn();
        }
    } else {
        $folio = 'VTA-' . date('Ymd-His');
        $stmtIns = $conn->prepare("
            INSERT INTO public.ordenes (folio, id_mesa, id_usuario, subtotal, total, estado, creado_en, cerrado_en)
            VALUES (?, ?, ?, ?, ?, 'PAGADA', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            RETURNING id_orden
        ");
        $stmtIns->execute([$folio, $id_mesa, $id_usuario, $subtotal, $total]);
        $id_orden = intval($stmtIns->fetchColumn());

        foreach ($productos as $prod) {
            $idP   = intval($prod['id'] ?? $prod['id_producto']);
            $cant  = intval($prod['cantidad'] ?? 1);
            $prec  = floatval($prod['precio'] ?? 0);
            $notas = trim($prod['notas'] ?? '');

            $stmtProdInfo->execute([$idP]);
            $infoP = $stmtProdInfo->fetch(PDO::FETCH_ASSOC);
            $nombreProd = trim($prod['nombre'] ?? ($infoP['nombre'] ?? 'Platillo'));
            $estacion   = $infoP['estacion'] ?? 'cocina';

            $stmtDet->execute([$id_orden, $idP, $nombreProd, $cant, $prec, $notas, $estacion, ($prec * $cant)]);
        }
    }

    if ($id_mesa) {
        $stmtMesa = $conn->prepare("UPDATE public.mesas SET estado = 'disponible' WHERE id_mesa = ?");
        $stmtMesa->execute([$id_mesa]);
    }

    $conn->commit();

    echo json_encode([
        'status'   => 'success',
        'id_orden' => $id_orden,
        'cambio'   => $cambio,
        'message'  => 'Pago procesado correctamente'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>