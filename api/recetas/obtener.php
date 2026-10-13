<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    $path_db = dirname(__FILE__) . '/../../config/conexion.php';
    if (!file_exists($path_db)) {
        $path_db = $_SERVER['DOCUMENT_ROOT'] . '/restaurante_app/config/conexion.php';
    }
    require_once $path_db;

    $pdo = isset($db) ? $db : (isset($conn) ? $conn : null);
    if (!$pdo) throw new Exception("Error de conexión a la base de datos");

    // Consultar productos activos
    $stmtProd = $pdo->prepare("
        SELECT p.id_producto, p.nombre, p.precio, COALESCE(c.nombre, 'General') AS categoria
        FROM productos p
        LEFT JOIN categorias c ON p.id_categoria = c.id_categoria
        WHERE p.activo = TRUE
        ORDER BY p.nombre ASC
    ");
    $stmtProd->execute();
    $productos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

    // Obtener los insumos configurados en la receta de cada producto
    foreach ($productos as &$prod) {
        $stmtRec = $pdo->prepare("
            SELECT r.id_receta, r.id_insumo, i.nombre AS nombre_insumo, i.unidad_medida, r.cantidad, i.costo_unitario
            FROM recetas r
            INNER JOIN insumos i ON r.id_insumo = i.id_insumo
            WHERE r.id_producto = :id_producto
            ORDER BY i.nombre ASC
        ");
        $stmtRec->execute([':id_producto' => $prod['id_producto']]);
        $prod['ingredientes'] = $stmtRec->fetchAll(PDO::FETCH_ASSOC);
    }

    ob_end_clean();
    echo json_encode(['status' => 'success', 'data' => $productos]);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>