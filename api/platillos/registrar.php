<?php
// Permitir el acceso desde el frontend
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

// Reemplaza con la ruta real de tu archivo de conexión a PostgreSQL
require_once '../../config/conexion.php'; 

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

try {
    // 1. Instanciar la conexión (asegúrate de que tu clase Database esté configurada para PostgreSQL con PDO)
    $db = new Database();
    $conn = $db->getConnection();

    // 2. Recibir y limpiar los datos del formulario (FormData)
    $nombre = filter_input(INPUT_POST, 'nombre', FILTER_SANITIZE_STRING);
    $descripcion = filter_input(INPUT_POST, 'descripcion', FILTER_SANITIZE_STRING) ?: null;
    $categoria_id = filter_input(INPUT_POST, 'categoria_id', FILTER_VALIDATE_INT);
    $precio = filter_input(INPUT_POST, 'precio', FILTER_VALIDATE_FLOAT);
    $estacion_kds_id = filter_input(INPUT_POST, 'estacion_kds_id', FILTER_VALIDATE_INT);
    
    // Por defecto, lo marcamos como disponible (1 o true en boolean)
    $disponible = 1; 

    // 3. Validar campos obligatorios
    if (!$nombre || !$categoria_id || !$precio || !$estacion_kds_id) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Faltan campos obligatorios o formato inválido."]);
        exit();
    }

    // 4. Preparar la consulta SQL (INSERT) para PostgreSQL
    // IMPORTANTE: Asegúrate de que los nombres de las columnas coincidan con tu tabla 'productos'
    // Asumiendo que tu tabla se llama 'productos' y las columnas son: nombre, descripcion, precio, categoria_id, estacion_kds_id, disponible
    $query = "INSERT INTO productos (nombre, descripcion, precio, categoria_id, estacion_kds_id, disponible) 
              VALUES (:nombre, :descripcion, :precio, :categoria_id, :estacion_kds_id, :disponible)";
    
    $stmt = $conn->prepare($query);

    // 5. Vincular parámetros
    $stmt->bindParam(':nombre', $nombre);
    $stmt->bindParam(':descripcion', $descripcion);
    $stmt->bindParam(':precio', $precio);
    $stmt->bindParam(':categoria_id', $categoria_id);
    $stmt->bindParam(':estacion_kds_id', $estacion_kds_id);
    $stmt->bindParam(':disponible', $disponible, PDO::PARAM_INT); // O PARAM_BOOL si tu columna es boolean

    // 6. Ejecutar y verificar
    if ($stmt->execute()) {
        http_response_code(201);
        echo json_encode([
            "status" => "success", 
            "message" => "Producto registrado exitosamente."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "status" => "error", 
            "message" => "Error al guardar en la base de datos."
        ]);
    }

} catch (PDOException $e) {
    // Si hay un error de base de datos, devolvemos el mensaje (útil para depurar)
    http_response_code(500);
    echo json_encode([
        "status" => "error", 
        "message" => "Error de BD: " . $e->getMessage()
    ]);
}
?>