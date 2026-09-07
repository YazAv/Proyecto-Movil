<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['nombre']) || empty($input['unidad_medida'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios (nombre, unidad_medida)"]);
    exit();
}

$unidades_validas = ['kg', 'gr', 'lt', 'ml', 'pz'];
$unidad_medida = trim($input['unidad_medida']);

if (!in_array($unidad_medida, $unidades_validas)) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "Unidad de medida inválida. Opciones: kg, gr, lt, ml, pz"]);
    exit();
}

$codigo = !empty($input['codigo']) ? trim($input['codigo']) : null;
$nombre = trim($input['nombre']);
$stock_actual = isset($input['stock_actual']) ? (float)$input['stock_actual'] : 0.000;
$stock_minimo = isset($input['stock_minimo']) ? (float)$input['stock_minimo'] : 0.000;
$costo_unitario = isset($input['costo_unitario']) ? (float)$input['costo_unitario'] : 0.0000;
$ultimo_costo = isset($input['ultimo_costo']) ? (float)$input['ultimo_costo'] : $costo_unitario;

$db = new Database();
$conn = $db->getConnection();

try {
    if (!empty($codigo)) {
        $stmtCheck = $conn->prepare("SELECT id FROM insumos WHERE codigo = ?");
        $stmtCheck->execute([$codigo]);
        if ($stmtCheck->fetch()) {
            http_response_code(409);
            echo json_encode(["status" => "error", "message" => "El código de insumo ya está registrado."]);
            exit();
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO insumos (codigo, nombre, unidad_medida, stock_actual, stock_minimo, costo_unitario, ultimo_costo)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$codigo, $nombre, $unidad_medida, $stock_actual, $stock_minimo, $costo_unitario, $ultimo_costo]);
    $id = (int)$conn->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "status" => "success",
        "message" => "Insumo registrado correctamente",
        "data" => [
            "id" => $id,
            "codigo" => $codigo,
            "nombre" => $nombre,
            "unidad_medida" => $unidad_medida,
            "stock_actual" => $stock_actual,
            "stock_minimo" => $stock_minimo,
            "costo_unitario" => $costo_unitario
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al registrar insumo: " . $e->getMessage()]);
}