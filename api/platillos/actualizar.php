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
    $nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
    $descripcion = isset($_POST['descripcion']) ? trim($_POST['descripcion']) : null;
    $categoria_id = isset($_POST['categoria_id']) ? intval($_POST['categoria_id']) : 0;
    
    // Sanitiza el precio reemplazando comas por puntos por si el navegador envía formato regional
    $precio_raw = isset($_POST['precio']) ? str_replace(',', '.', trim($_POST['precio'])) : '0';
    $precio = floatval($precio_raw);
    
    $estacion_kds_id = isset($_POST['estacion_kds_id']) ? intval($_POST['estacion_kds_id']) : 0;

    // Validación flexible (si la estación KDS viene vacía por defecto le asignamos 1)
    if ($id === 0 || $nombre === '' || $categoria_id === 0 || $precio <= 0) {
        http_response_code(400);
        echo json_encode([
            "status" => "error", 
            "message" => "Faltan datos obligatorios para actualizar.",
            "debug" => ["id" => $id, "nombre" => $nombre, "cat" => $categoria_id, "precio" => $precio]
        ]);
        exit();
    }

    if ($estacion_kds_id === 0) {
        $estacion_kds_id = 1; // Estación por defecto (Cocina Principal)
    }

    $query = "UPDATE productos 
              SET nombre = :nombre, 
                  descripcion = :descripcion, 
                  precio = :precio, 
                  id_categoria = :categoria_id,
                  categoria_id = :categoria_id, 
                  estacion_kds_id = :estacion_kds_id
              WHERE id_producto = :id OR id = :id";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->bindParam(':nombre', $nombre);
    $stmt->bindParam(':descripcion', $descripcion);
    $stmt->bindParam(':precio', $precio);
    $stmt->bindParam(':categoria_id', $categoria_id, PDO::PARAM_INT);
    $stmt->bindParam(':estacion_kds_id', $estacion_kds_id, PDO::PARAM_INT);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Producto actualizado con éxito"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error al ejecutar la actualización en la BD."]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error de BD: " . $e->getMessage()]);
}
?>