<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once '../../config/conexion.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("
        SELECT p.id_producto AS id, 
               p.nombre, 
               p.descripcion, 
               p.precio, 
               COALESCE(p.categoria_id, p.id_categoria) AS categoria_id, 
               p.estacion_kds_id,
               c.nombre AS categoria, 
               COALESCE(k.nombre, 'Cocina Principal') AS estacion_kds
        FROM productos p
        LEFT JOIN categorias c ON (c.id_categoria = p.categoria_id OR c.id_categoria = p.id_categoria)
        LEFT JOIN kds_estaciones k ON (k.id_estacion = p.estacion_kds_id OR k.id = p.estacion_kds_id)
        WHERE p.disponible = 1 OR p.activo = true
        ORDER BY p.id_producto ASC
    ");
    
    $stmt->execute();
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($productos);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al listar: " . $e->getMessage()]);
}
?>