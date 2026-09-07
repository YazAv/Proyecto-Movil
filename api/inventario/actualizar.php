<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El campo 'id' es obligatorio"]);
    exit();
}

$id = (int)$input['id'];

$db = new Database();
$conn = $db->getConnection();

try {
    $stmtInsumo = $conn->prepare("SELECT * FROM insumos WHERE id = ?");
    $stmtInsumo->execute([$id]);
    $actual = $stmtInsumo->fetch();

    if (!$actual) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Insumo no encontrado."]);
        exit();
    }

    $codigo = array_key_exists('codigo', $input) ? trim($input['codigo']) : $actual['codigo'];
    $nombre = isset($input['nombre']) ? trim($input['nombre']) : $actual['nombre'];
    $unidad_medida = isset($input['unidad_medida']) ? trim($input['unidad_medida']) : $actual['unidad_medida'];
    $stock_minimo = isset($input['stock_minimo']) ? (float)$input['stock_minimo'] : (float)$actual['stock_minimo'];
    $costo_unitario = isset($input['costo_unitario']) ? (float)$input['costo_unitario'] : (float)$actual['costo_unitario'];
    $ultimo_costo = isset($input['ultimo_costo']) ? (float)$input['ultimo_costo'] : (float)$actual['ultimo_costo'];

    $unidades_validas = ['kg', 'gr', 'lt', 'ml', 'pz'];
    if (!in_array($unidad_medida, $unidades_validas)) {
        http_response_code(422);
        echo json_encode(["status" => "error", "message" => "Unidad de medida no válida"]);
        exit();
    }

    $stmtUpdate = $conn->prepare("
        UPDATE insumos 
        SET codigo = ?, nombre = ?, unidad_medida = ?, stock_minimo = ?, costo_unitario = ?, ultimo_costo = ?
        WHERE id = ?
    ");
    $stmtUpdate->execute([$codigo, $nombre, $unidad_medida, $stock_minimo, $costo_unitario, $ultimo_costo, $id]);

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Insumo actualizado exitosamente",
        "data" => [
            "id" => $id,
            "codigo" => $codigo,
            "nombre" => $nombre,
            "unidad_medida" => $unidad_medida,
            "stock_minimo" => $stock_minimo,
            "costo_unitario" => $costo_unitario
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al actualizar insumo: " . $e->getMessage()]);
}