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

    $id_insumo = intval($datos['id_insumo'] ?? ($datos['insumo_id'] ?? ($datos['id'] ?? 0)));
    $cantidad  = floatval($datos['cantidad'] ?? ($datos['cantidad_merma'] ?? 0));
    $notas     = trim($datos['notas'] ?? ($datos['motivo'] ?? ($datos['observaciones'] ?? 'Merma registrada')));

    if ($id_insumo <= 0 || $cantidad <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Selecciona un insumo válido y una cantidad mayor a 0.']);
        exit;
    }

    $conn->beginTransaction();

    $stmtUpd = $conn->prepare("
        UPDATE public.insumos
        SET stock_actual = GREATEST(0, COALESCE(stock_actual, 0) - ?)
        WHERE id_insumo = ?
    ");
    $stmtUpd->execute([$cantidad, $id_insumo]);

    // Registrar en movimientos_inventario detectando columnas reales
    $colsStmt = $conn->query("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'movimientos_inventario'");
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($cols)) {
        $campos = ['id_insumo', 'tipo', 'cantidad'];
        $valores = [$id_insumo, 'merma', $cantidad];

        if (in_array('notas', $cols)) {
            $campos[] = 'notas';
            $valores[] = $notas;
        } elseif (in_array('motivo', $cols)) {
            $campos[] = 'motivo';
            $valores[] = $notas;
        } elseif (in_array('descripcion', $cols)) {
            $campos[] = 'descripcion';
            $valores[] = $notas;
        }

        $placeholders = implode(', ', array_fill(0, count($campos), '?'));
        $stmtMov = $conn->prepare("INSERT INTO public.movimientos_inventario (" . implode(', ', $campos) . ") VALUES ($placeholders)");
        $stmtMov->execute($valores);
    }

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => 'Merma registrada y descontada del inventario.']);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>