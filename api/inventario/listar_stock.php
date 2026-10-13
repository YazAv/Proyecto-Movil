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

    if (!file_exists($path_db)) {
        throw new Exception("No se encontró el archivo config/conexion.php");
    }

    require_once $path_db;

    $pdo = isset($db) ? $db : (isset($conn) ? $conn : (isset($conexion) ? $conexion : null));

    if (!$pdo) {
        throw new Exception("No se detectó una variable de conexión válida en conexion.php");
    }

    $stmt = $pdo->prepare("
        SELECT 
            id_insumo,
            nombre,
            unidad_medida,
            stock_actual,
            stock_minimo,
            costo_unitario,
            CASE 
                WHEN stock_actual <= 0 THEN 'agotado'
                WHEN stock_actual <= stock_minimo THEN 'critico'
                ELSE 'optimo'
            END AS estado_stock
        FROM insumos
        ORDER BY id_insumo DESC
    ");
    $stmt->execute();
    $insumos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_end_clean();
    echo json_encode(['status' => 'success', 'data' => $insumos]);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>