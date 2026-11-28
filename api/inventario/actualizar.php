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

    $id     = intval($datos['id_insumo'] ?? ($datos['id'] ?? 0));
    $nombre = trim($datos['nombre'] ?? '');
    $unidad = trim($datos['unidad_medida'] ?? ($datos['unidad'] ?? 'kg'));
    $costo  = floatval($datos['costo_unitario'] ?? ($datos['costo'] ?? 0));
    $stock  = floatval($datos['stock_actual'] ?? ($datos['stock'] ?? 0));
    $minimo = floatval($datos['stock_minimo'] ?? ($datos['minimo'] ?? 0));

    if ($id <= 0 || $nombre === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Datos incompletos para actualizar el insumo.']);
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE public.insumos
        SET nombre = ?, unidad_medida = ?, costo_unitario = ?, stock_actual = ?, stock_minimo = ?
        WHERE id_insumo = ?
    ");
    $stmt->execute([$nombre, $unidad, $costo, $stock, $minimo, $id]);

    echo json_encode(['status' => 'success', 'message' => 'Insumo actualizado correctamente.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>