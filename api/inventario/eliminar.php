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

    $id = intval($datos['id_insumo'] ?? ($datos['id'] ?? 0));
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'ID de insumo no válido.']);
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM public.insumos WHERE id_insumo = ?");
    $stmt->execute([$id]);

    echo json_encode(['status' => 'success', 'message' => 'Insumo eliminado correctamente.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No se pudo eliminar (puede estar en uso en una receta): ' . $e->getMessage()]);
}
?>