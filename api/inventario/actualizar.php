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
        throw new Exception("No se encontró conexion.php");
    }

    require_once $path_db;

    $pdo = isset($db) ? $db : (isset($conn) ? $conn : (isset($conexion) ? $conexion : null));

    if (!$pdo) {
        throw new Exception("No se detectó una variable de conexión válida en conexion.php");
    }

    $data = json_decode(file_get_contents("php://input"), true);

    if (empty($data['id_insumo']) || empty($data['nombre'])) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Campos obligatorios incompletos']);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE insumos 
        SET nombre         = :nombre,
            unidad_medida = :unidad_medida,
            stock_actual  = :stock_actual,
            stock_minimo  = :stock_minimo,
            costo_unitario = :costo_unitario
        WHERE id_insumo   = :id_insumo
    ");

    $stmt->execute([
        ':id_insumo'      => intval($data['id_insumo']),
        ':nombre'         => trim($data['nombre']),
        ':unidad_medida'  => trim($data['unidad_medida']),
        ':stock_actual'   => floatval($data['stock_actual']),
        ':stock_minimo'   => floatval($data['stock_minimo']),
        ':costo_unitario' => floatval($data['costo_unitario'])
    ]);

    ob_end_clean();
    echo json_encode(['status' => 'success', 'message' => 'Insumo actualizado']);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>