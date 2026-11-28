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

    $id = intval($datos['id_producto'] ?? ($datos['id'] ?? 0));
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'ID de producto inválido.']);
        exit;
    }

    $colsStmt = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'productos'");
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
    $colId = in_array('id_producto', $cols) ? 'id_producto' : 'id';

    $updates = [];
    if (in_array('disponible', $cols)) $updates[] = 'disponible = 0';
    if (in_array('activo', $cols)) $updates[] = 'activo = false';

    if (!empty($updates)) {
        $stmt = $conn->prepare("UPDATE public.productos SET " . implode(', ', $updates) . " WHERE $colId = ?");
        $stmt->execute([$id]);
    } else {
        $stmt = $conn->prepare("DELETE FROM public.productos WHERE $colId = ?");
        $stmt->execute([$id]);
    }

    echo json_encode(['status' => 'success', 'message' => 'Producto eliminado del catálogo.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>