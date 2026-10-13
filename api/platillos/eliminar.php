<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once '../../config/conexion.php'; 

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    if ($id === 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "ID de producto inválido."]);
        exit();
    }

    // Borrado lógico seguro utilizando tanto id_producto como id
    $query = "UPDATE productos SET disponible = 0, activo = false WHERE id_producto = :id OR id = :id";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Producto eliminado correctamente"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "No se pudo eliminar el registro en la base de datos."]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error de BD: " . $e->getMessage()]);
}
?>