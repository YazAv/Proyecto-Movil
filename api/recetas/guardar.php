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
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    $datos = is_array($json) && !empty($json) ? $json : $_POST;

    $id_producto  = intval($datos['id_producto'] ?? 0);
    $ingredientes = $datos['ingredientes'] ?? [];
    if (is_string($ingredientes)) {
        $ingredientes = json_decode($ingredientes, true) ?: [];
    }

    if ($id_producto <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Selecciona un producto válido.']);
        exit;
    }

    $colsStmt = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'recetas'");
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
    $colCant = in_array('cantidad_necesaria', $cols) ? 'cantidad_necesaria' : 'cantidad';

    $conn->beginTransaction();

    // Limpiar receta anterior del producto para reemplazarla limpia
    $stmtDel = $conn->prepare("DELETE FROM public.recetas WHERE id_producto = ?");
    $stmtDel->execute([$id_producto]);

    $stmtIns = $conn->prepare("
        INSERT INTO public.recetas (id_producto, id_insumo, $colCant)
        VALUES (?, ?, ?)
    ");

    foreach ($ingredientes as $ing) {
        $id_insumo = intval($ing['id_insumo'] ?? 0);
        $cantidad  = floatval($ing['cantidad'] ?? 0);
        if ($id_insumo > 0 && $cantidad > 0) {
            $stmtIns->execute([$id_producto, $id_insumo, $cantidad]);
        }
    }

    // Recalcular costo total y actualizar columna costo en productos si existe
    $stmtCosto = $conn->prepare("
        SELECT COALESCE(SUM(r.$colCant * COALESCE(i.costo_unitario, 0)), 0)
        FROM public.recetas r
        JOIN public.insumos i ON r.id_insumo = i.id_insumo
        WHERE r.id_producto = ?
    ");
    $stmtCosto->execute([$id_producto]);
    $costoTotal = floatval($stmtCosto->fetchColumn());

    $prodColsStmt = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'productos'");
    $prodCols = $prodColsStmt->fetchAll(PDO::FETCH_COLUMN);
    $prodIdCol = in_array('id_producto', $prodCols) ? 'id_producto' : 'id';

    if (in_array('costo', $prodCols)) {
        $stmtUpdProd = $conn->prepare("UPDATE public.productos SET costo = ? WHERE $prodIdCol = ?");
        $stmtUpdProd->execute([$costoTotal, $id_producto]);
    }

    $conn->commit();
    echo json_encode([
        'status' => 'success',
        'message' => 'Receta guardada y costo actualizado.',
        'costo_total' => $costoTotal
    ]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>