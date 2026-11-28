<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../config/conexion.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../conexion.php'
];
foreach ($rutas as $r) {
    if (file_exists($r)) {
        require_once $r;
        break;
    }
}
if (!isset($conn) && isset($pdo)) $conn = $pdo;
if (!isset($conn) && class_exists('Database')) $conn = (new Database())->getConnection();

try {
    $id_producto = intval($_GET['id_producto'] ?? 0);
    $todas       = intval($_GET['todas'] ?? 0);

    $colsStmt = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'recetas'");
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);

    $colId   = in_array('id_receta', $cols) ? 'id_receta' : 'id';
    $colCant = in_array('cantidad_necesaria', $cols) ? 'cantidad_necesaria' : 'cantidad';

    $prodColsStmt = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'productos'");
    $prodCols = $prodColsStmt->fetchAll(PDO::FETCH_COLUMN);
    $prodIdCol = in_array('id_producto', $prodCols) ? 'id_producto' : 'id';

    if ($id_producto > 0) {
        $stmt = $conn->prepare("
            SELECT
                r.$colId AS id_receta,
                r.id_producto,
                r.id_insumo,
                r.$colCant AS cantidad,
                i.nombre AS nombre_insumo,
                COALESCE(i.unidad_medida, 'kg') AS unidad_medida,
                COALESCE(i.costo_unitario, 0) AS costo_unitario,
                (r.$colCant * COALESCE(i.costo_unitario, 0)) AS subtotal_costo
            FROM public.recetas r
            JOIN public.insumos i ON r.id_insumo = i.id_insumo
            WHERE r.id_producto = ?
            ORDER BY r.$colId ASC
        ");
        $stmt->execute([$id_producto]);
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    } elseif ($todas === 1) {
        $stmt = $conn->query("
            SELECT
                r.id_producto,
                p.nombre AS nombre_producto,
                COALESCE(p.precio, 0) AS precio_venta,
                i.nombre AS nombre_insumo,
                r.$colCant AS cantidad,
                COALESCE(i.unidad_medida, 'kg') AS unidad_medida,
                COALESCE(i.costo_unitario, 0) AS costo_unitario,
                (r.$colCant * COALESCE(i.costo_unitario, 0)) AS subtotal_costo
            FROM public.recetas r
            JOIN public.productos p ON r.id_producto = p.$prodIdCol
            JOIN public.insumos i ON r.id_insumo = i.id_insumo
            ORDER BY p.nombre ASC, r.$colId ASC
        ");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    } else {
        $stmt = $conn->query("
            SELECT
                r.id_producto,
                COUNT(r.$colId) AS total_insumos,
                COALESCE(SUM(r.$colCant * COALESCE(i.costo_unitario, 0)), 0) AS costo_total
            FROM public.recetas r
            JOIN public.insumos i ON r.id_insumo = i.id_insumo
            GROUP BY r.id_producto
        ");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>