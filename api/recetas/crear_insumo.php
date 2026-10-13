<?php
// Configuración de encabezados HTTP y compatibilidad CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Inclusión del archivo de conexión a la base de datos PostgreSQL
require_once "../../config/conexion.php";

// Recepción del cuerpo de la petición enviada en JSON por el cliente
$data = json_decode(file_get_contents("php://input"), true);

// Validación de campos obligatorios
if (!isset($data['nombre']) || !isset($data['unidad_medida']) || !isset($data['costo_unitario'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios para crear el insumo."]);
    exit();
}

try {
    // Preparar la consulta SQL de inserción en PostgreSQL
    $sql = "INSERT INTO insumos (nombre, unidad_medida, costo_unitario, stock_actual) 
            VALUES (:nombre, :unidad, :costo, :stock)";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':nombre' => trim($data['nombre']),
        ':unidad' => $data['unidad_medida'],
        ':costo'  => floatval($data['costo_unitario']),
        ':stock'  => floatval($data['stock_actual'] ?? 0)
    ]);

    echo json_encode([
        "status" => "success",
        "message" => "Insumo registrado correctamente en la base de datos."
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Error al guardar el insumo: " . $e->getMessage()
    ]);
}
?>