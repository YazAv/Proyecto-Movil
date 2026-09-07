<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$estado = isset($_GET['estado']) ? trim($_GET['estado']) : null;
$busqueda = isset($_GET['q']) ? trim($_GET['q']) : null;

$db = new Database();
$conn = $db->getConnection();

try {
    $sql = "
        SELECT id, razon_social, contacto_nombre, telefono, correo, rfc, direccion, dias_credito, estado, creado_en 
        FROM proveedores 
        WHERE 1 = 1
    ";
    $params = [];

    if ($estado) {
        $sql .= " AND estado = ?";
        $params[] = $estado;
    }

    if ($busqueda) {
        $sql .= " AND (razon_social LIKE ? OR contacto_nombre LIKE ? OR rfc LIKE ?)";
        $term = "%$busqueda%";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    $sql .= " ORDER BY razon_social ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $proveedores = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total" => count($proveedores),
        "data" => $proveedores
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al listar proveedores: " . $e->getMessage()]);
}