<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$rol_id = isset($_GET['rol_id']) ? (int)$_GET['rol_id'] : null;
$estado = isset($_GET['estado']) ? trim($_GET['estado']) : null;

$db = new Database();
$conn = $db->getConnection();

try {
    $sql = "
        SELECT 
            u.id, 
            u.nombre, 
            u.correo, 
            u.pin_acceso, 
            u.estado, 
            u.creado_en,
            r.id AS rol_id, 
            r.nombre AS rol_nombre
        FROM usuarios u
        INNER JOIN roles r ON u.rol_id = r.id
        WHERE 1 = 1
    ";

    $params = [];

    if ($rol_id) {
        $sql .= " AND u.rol_id = ?";
        $params[] = $rol_id;
    }

    if ($estado) {
        $sql .= " AND u.estado = ?";
        $params[] = $estado;
    }

    $sql .= " ORDER BY r.id ASC, u.nombre ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll();

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "total" => count($usuarios),
        "data" => $usuarios
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al listar usuarios: " . $e->getMessage()]);
}