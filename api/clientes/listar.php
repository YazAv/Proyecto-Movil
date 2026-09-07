<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$busqueda = isset($_GET['q']) ? trim($_GET['q']) : '';

$db = new Database();
$conn = $db->getConnection();

try {
    if (!empty($busqueda)) {
        $stmt = $conn->prepare("
            SELECT id, nombre, telefono, correo, rfc, direccion, creado_en 
            FROM clientes 
            WHERE nombre LIKE ? OR telefono LIKE ? OR rfc LIKE ?
            ORDER BY nombre ASC
        ");
        $term = "%$busqueda%";
        $stmt->execute([$term, $term, $term]);
    } else {
        $stmt = $conn->query("
            SELECT id, nombre, telefono, correo, rfc, direccion, creado_en 
            FROM clientes 
            ORDER BY id DESC
        ");
    }

    $clientes = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total" => count($clientes),
        "data" => $clientes
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al consultar clientes: " . $e->getMessage()]);
}