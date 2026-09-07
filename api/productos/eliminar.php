<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (empty($input['id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Falta el id del producto"]);
    exit();
}

$id = (int)$input['id'];

$db = new Database();
$conn = $db->getConnection();

try {
    // 1. Revisar si el producto ya está en comandas
    $stmtHistorial = $conn->prepare("SELECT COUNT(*) AS total FROM orden_detalles WHERE producto_id = ?");
    $stmtHistorial->execute([$id]);
    $usos = (int)$stmtHistorial->fetch()['total'];

    if ($usos > 0) {
        // Baja lógica para no romper historial de ventas
        $stmtBaja = $conn->prepare("UPDATE productos SET disponible = 0 WHERE id = ?");
        $stmtBaja->execute([$id]);

        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "El producto tiene historial de ventas previo. Se desactivó del menú (disponible = 0)."
        ]);
        exit();
    }

    // 2. Si no tiene historial, eliminación física
    $stmtDelete = $conn->prepare("DELETE FROM productos WHERE id = ?");
    $stmtDelete->execute([$id]);

    if ($stmtDelete->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Producto no encontrado"]);
        exit();
    }

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Producto eliminado completamente del catálogo"
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al eliminar producto: " . $e->getMessage()]);
}