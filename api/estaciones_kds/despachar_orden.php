<?php
// api/estaciones_kds/despachar_orden.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../config/conexion.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../conexion.php'
];
foreach ($rutas as $r) { if (file_exists($r)) { require_once $r; break; } }
if (!isset($conn) && isset($pdo)) $conn = $pdo;
if (!isset($conn) && class_exists('Database')) $conn = (new Database())->getConnection();

try {
    $data = json_decode(file_get_contents("php://input"), true) ?: $_POST;
    $id_orden = intval($data['id_orden'] ?? 0);
    $id_detalle = intval($data['id_detalle'] ?? 0);
    $estacion = strtolower(trim($data['estacion'] ?? 'todas'));
    $nuevo_estado = strtoupper(trim($data['estado'] ?? 'LISTO')); // 'PREPARANDO' o 'LISTO'

    if ($id_detalle <= 0 && $id_orden <= 0) {
        throw new Exception("Se requiere id_detalle o id_orden.");
    }

    $conn->beginTransaction();

    // 1. Si pasa a LISTO, descontar insumos de la receta en public.insumos
    if ($nuevo_estado === 'LISTO') {
        if ($id_detalle > 0) {
            $stmtItems = $conn->prepare("
                SELECT id_detalle, id_producto, cantidad 
                FROM public.orden_detalle 
                WHERE id_detalle = ? AND UPPER(COALESCE(estado, 'PENDIENTE')) != 'LISTO'
            ");
            $stmtItems->execute([$id_detalle]);
        } else {
            if ($estacion === 'cocina' || $estacion === 'barra') {
                $stmtItems = $conn->prepare("
                    SELECT id_detalle, id_producto, cantidad 
                    FROM public.orden_detalle 
                    WHERE id_orden = ? 
                      AND LOWER(COALESCE(estacion, 'cocina')) = ?
                      AND UPPER(COALESCE(estado, 'PENDIENTE')) != 'LISTO'
                ");
                $stmtItems->execute([$id_orden, $estacion]);
            } else {
                $stmtItems = $conn->prepare("
                    SELECT id_detalle, id_producto, cantidad 
                    FROM public.orden_detalle 
                    WHERE id_orden = ? AND UPPER(COALESCE(estado, 'PENDIENTE')) != 'LISTO'
                ");
                $stmtItems->execute([$id_orden]);
            }
        }

        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $stmtReceta = $conn->prepare("SELECT insumo_id, cantidad_necesaria FROM public.producto_recetas WHERE producto_id = ?");
        $stmtDesc = $conn->prepare("UPDATE public.insumos SET stock_actual = GREATEST(0, stock_actual - ?) WHERE id_insumo = ?");

        foreach ($items as $item) {
            $stmtReceta->execute([$item['id_producto']]);
            foreach ($stmtReceta->fetchAll(PDO::FETCH_ASSOC) as $ing) {
                $descuento = floatval($ing['cantidad_necesaria']) * intval($item['cantidad']);
                $stmtDesc->execute([$descuento, $ing['insumo_id']]);
            }
        }
    }

    // 2. Actualizar el estado en public.orden_detalle
    if ($id_detalle > 0) {
        $stmtUpd = $conn->prepare("UPDATE public.orden_detalle SET estado = ? WHERE id_detalle = ?");
        $stmtUpd->execute([$nuevo_estado, $id_detalle]);
    } else {
        if ($estacion === 'cocina' || $estacion === 'barra') {
            $stmtUpd = $conn->prepare("
                UPDATE public.orden_detalle 
                SET estado = ? 
                WHERE id_orden = ? 
                  AND LOWER(COALESCE(estacion, 'cocina')) = ?
                  AND UPPER(COALESCE(estado, 'PENDIENTE')) IN ('PENDIENTE', 'PREPARANDO', 'EN_PREPARACION')
            ");
            $stmtUpd->execute([$nuevo_estado, $id_orden, $estacion]);
        } else {
            $stmtUpd = $conn->prepare("
                UPDATE public.orden_detalle 
                SET estado = ? 
                WHERE id_orden = ?
                  AND UPPER(COALESCE(estado, 'PENDIENTE')) IN ('PENDIENTE', 'PREPARANDO', 'EN_PREPARACION')
            ");
            $stmtUpd->execute([$nuevo_estado, $id_orden]);
        }
    }

    $conn->commit();

    echo json_encode([
        'status' => 'success',
        'ok' => true,
        'nuevo_estado' => $nuevo_estado,
        'message' => 'Estado actualizado correctamente.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'ok' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>