<?php
// api/comandas/crear_comanda.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../config/conexion.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../conexion.php'
];
foreach ($rutas as $r) { if (file_exists($r)) { require_once $r; break; } }
if (!isset($conn) && isset($pdo)) $conn = $pdo;
if (!isset($conn) && class_exists('Database')) $conn = (new Database())->getConnection();

try {
    $data = json_decode(file_get_contents("php://input"), true) ?: $_POST;
    
    $id_mesa = !empty($data['id_mesa']) ? intval($data['id_mesa']) : null;
    $id_usuario = intval($data['id_usuario'] ?? 1);
    $origen = strtoupper(trim($data['origen'] ?? 'WEB')); // 'MOVIL' o 'WEB'
    $items = $data['productos'] ?? ($data['items'] ?? []);

    if (empty($items)) {
        throw new Exception("La comanda no contiene productos.");
    }

    $conn->beginTransaction();

    // 1. Buscar turno abierto o usar turno 1 por defecto
    $stmtTurno = $conn->query("SELECT id_turno FROM public.turnos_caja WHERE LOWER(estado) = 'abierto' ORDER BY id_turno DESC LIMIT 1");
    $id_turno = $stmtTurno->fetchColumn() ?: 1;

    // 2. Verificar si la mesa ya tiene una orden ABIERTA para agregarle platillos, o crear una nueva
    $id_orden = null;
    if ($id_mesa) {
        $stmtOrdExistente = $conn->prepare("SELECT id_orden FROM public.ordenes WHERE id_mesa = ? AND UPPER(estado) = 'ABIERTA' ORDER BY id_orden DESC LIMIT 1");
        $stmtOrdExistente->execute([$id_mesa]);
        $id_orden = $stmtOrdExistente->fetchColumn();
    }

    if (!$id_orden) {
        $folio = ($origen === 'MOVIL' ? 'MOV-' : 'WEB-') . date('His') . '-' . rand(10, 99);
        $stmtNewOrd = $conn->prepare("
            INSERT INTO public.ordenes (folio, id_mesa, id_usuario, id_turno, subtotal, total, estado, creado_en)
            VALUES (?, ?, ?, ?, 0, 0, 'ABIERTA', CURRENT_TIMESTAMP)
            RETURNING id_orden
        ");
        $stmtNewOrd->execute([$folio, $id_mesa, $id_usuario, $id_turno]);
        $id_orden = $stmtNewOrd->fetchColumn();
    }

    // 3. Insertar cada producto detectando su estación KDS (COCINA o BARRA)
    $stmtProdInfo = $conn->prepare("
        SELECT p.nombre, p.precio,
               LOWER(COALESCE(ke.nombre, c.estacion, 'cocina')) AS estacion_destino
        FROM public.productos p
        LEFT JOIN public.categorias c ON p.id_categoria = c.id_categoria
        LEFT JOIN public.kds_estaciones ke ON p.estacion_kds_id = ke.id_estacion
        WHERE p.id_producto = ?
    ");

    $stmtInsertDet = $conn->prepare("
        INSERT INTO public.orden_detalle 
        (id_orden, id_producto, nombre_producto, cantidad, precio_unitario, subtotal, notas, estacion, estado, enviado_en)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', CURRENT_TIMESTAMP)
    ");

    $total_agregado = 0;
    foreach ($items as $item) {
        $id_prod = intval($item['id_producto'] ?? $item['producto_id']);
        $cant = intval($item['cantidad'] ?? 1);
        $notas = trim($item['notas'] ?? ($item['modificadores'] ?? ''));

        $stmtProdInfo->execute([$id_prod]);
        $info = $stmtProdInfo->fetch(PDO::FETCH_ASSOC);

        $nombre_prod = $info['nombre'] ?? ($item['nombre'] ?? 'Platillo');
        $precio = floatval($info['precio'] ?? ($item['precio'] ?? 0));
        $subtotal = $precio * $cant;
        $total_agregado += $subtotal;

        // Normalizar estación a 'barra' o 'cocina'
        $estacion_raw = $info['estacion_destino'] ?? 'cocina';
        $estacion = (strpos($estacion_raw, 'barra') !== false || strpos($estacion_raw, 'bebida') !== false) ? 'barra' : 'cocina';

        $stmtInsertDet->execute([$id_orden, $id_prod, $nombre_prod, $cant, $precio, $subtotal, $notas, $estacion]);
    }

    // 4. Actualizar el total acumulado de la orden y ocupar la mesa
    $stmtUpdOrd = $conn->prepare("UPDATE public.ordenes SET subtotal = subtotal + ?, total = total + ? WHERE id_orden = ?");
    $stmtUpdOrd->execute([$total_agregado, $total_agregado, $id_orden]);

    if ($id_mesa) {
        $conn->prepare("UPDATE public.mesas SET estado = 'ocupada' WHERE id_mesa = ?")->execute([$id_mesa]);
    }

    $conn->commit();

    echo json_encode([
        'status' => 'success',
        'id_orden' => $id_orden,
        'origen' => $origen,
        'message' => 'Comanda enviada a Cocina/Barra correctamente.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>