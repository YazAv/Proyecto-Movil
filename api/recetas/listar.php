<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$producto_id = isset($_GET['producto_id']) ? (int)$_GET['producto_id'] : null;

$db = new Database();
$conn = $db->getConnection();

try {
    $sql = "
        SELECT 
            r.id,
            r.producto_id,
            p.nombre AS producto_nombre,
            r.insumo_id,
            i.nombre AS insumo_nombre,
            i.unidad_medida,
            r.cantidad AS porcion_necesaria,
            r.rendimiento_factor,
            i.costo_unitario,
            ROUND((r.cantidad * i.costo_unitario), 4) AS costo_porcion
        FROM recetas r
        INNER JOIN productos p ON r.producto_id = p.id
        INNER JOIN insumos i ON r.insumo_id = i.id
    ";

    if ($producto_id) {
        $sql .= " WHERE r.producto_id = :producto_id";
    }

    $sql .= " ORDER BY p.nombre ASC, i.nombre ASC";

    $stmt = $conn->prepare($sql);
    if ($producto_id) {
        $stmt->bindParam(':producto_id', $producto_id, PDO::PARAM_INT);
    }
    $stmt->execute();
    $receta = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total" => count($receta),
        "data" => $receta
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al consultar receta: " . $e->getMessage()]);
}