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

    $nombre       = trim($datos['nombre'] ?? '');
    $descripcion  = trim($datos['descripcion'] ?? '');
    $categoria_id = intval($datos['categoria_id'] ?? ($datos['id_categoria'] ?? 0));
    $precio       = floatval($datos['precio'] ?? 0);
    $estacion_kds = intval($datos['estacion_kds_id'] ?? ($categoria_id === 5 ? 2 : 1));

    if ($nombre === '' || $categoria_id <= 0 || $precio <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Nombre, categoría y precio mayor a 0 son obligatorios.']);
        exit;
    }

    $colsStmt = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'productos'");
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);

    $campos = ['nombre', 'descripcion', 'precio'];
    $valores = [$nombre, $descripcion, $precio];

    if (in_array('id_categoria', $cols)) {
        $campos[] = 'id_categoria';
        $valores[] = $categoria_id;
    }
    if (in_array('categoria_id', $cols)) {
        $campos[] = 'categoria_id';
        $valores[] = $categoria_id;
    }
    if (in_array('estacion_kds_id', $cols)) {
        $campos[] = 'estacion_kds_id';
        $valores[] = $estacion_kds;
    }
    if (in_array('disponible', $cols)) {
        $campos[] = 'disponible';
        $valores[] = 1;
    }
    if (in_array('activo', $cols)) {
        $campos[] = 'activo';
        $valores[] = true;
    }

    $placeholders = implode(', ', array_fill(0, count($campos), '?'));
    $sql = "INSERT INTO public.productos (" . implode(', ', $campos) . ") VALUES ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->execute($valores);

    echo json_encode(['status' => 'success', 'message' => 'Producto registrado correctamente.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>