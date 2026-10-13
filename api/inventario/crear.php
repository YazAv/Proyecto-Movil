<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    // Buscar conexion.php en la carpeta config
    $path_db = dirname(__FILE__) . '/../../config/conexion.php';

    if (!file_exists($path_db)) {
        $path_db = $_SERVER['DOCUMENT_ROOT'] . '/restaurante_app/config/conexion.php';
    }

    if (!file_exists($path_db)) {
        throw new Exception("No se encontró el archivo config/conexion.php");
    }

    require_once $path_db;

    // Asignar PDO dinámicamente según el nombre de variable en tu conexion.php
    $pdo = isset($db) ? $db : (isset($conn) ? $conn : (isset($conexion) ? $conexion : null));

    if (!$pdo) {
        throw new Exception("No se detectó una variable de conexión válida (\$db o \$conn) en conexion.php");
    }

    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if (!$data || empty($data['nombre']) || empty($data['unidad_medida'])) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Campos obligatorios incompletos']);
        exit;
    }

    $nombre         = trim($data['nombre']);
    $unidad_medida  = trim($data['unidad_medida']);
    $stock_actual   = floatval($data['stock_actual'] ?? 0);
    $stock_minimo   = floatval($data['stock_minimo'] ?? 0);
    $costo_unitario = floatval($data['costo_unitario'] ?? 0);

    $stmt = $pdo->prepare("
        INSERT INTO insumos (nombre, unidad_medida, stock_actual, stock_minimo, costo_unitario, ultimo_costo)
        VALUES (:nombre, :unidad_medida, :stock_actual, :stock_minimo, :costo_unitario, :costo_unitario)
    ");

    $stmt->execute([
        ':nombre'         => $nombre,
        ':unidad_medida'  => $unidad_medida,
        ':stock_actual'   => $stock_actual,
        ':stock_minimo'   => $stock_minimo,
        ':costo_unitario' => $costo_unitario
    ]);

    ob_end_clean();
    echo json_encode(['status' => 'success', 'message' => 'Insumo guardado correctamente']);

} catch (PDOException $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de BD: ' . $e->getMessage()]);
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>