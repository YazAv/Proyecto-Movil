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

    if (empty($data['id_insumo'])) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Falta id_insumo']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM insumos WHERE id_insumo = :id_insumo");
    $stmt->execute([':id_insumo' => intval($data['id_insumo'])]);

    ob_end_clean();
    echo json_encode(['status' => 'success', 'message' => 'Insumo eliminado']);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>