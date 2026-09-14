<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json');

$db = new Database();
$conn = $db->getConnection();

try {
    // 1. Obtener todas las áreas (sin filtrar la columna 'activo')
    $stmtAreas = $conn->query("SELECT id_area, nombre FROM areas ORDER BY id_area ASC");
    $areas = $stmtAreas->fetchAll(PDO::FETCH_ASSOC);

    // 2. Obtener todas las mesas
    $stmtMesas = $conn->query("
        SELECT 
            m.id_mesa,
            m.numero_mesa,
            m.capacidad,
            m.estado,
            m.id_area
        FROM mesas m
        ORDER BY m.id_mesa ASC
    ");
    $mesas = $stmtMesas->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar las mesas dentro de su respectiva área
    $mapa = array_map(function($area) use ($mesas) {
        $area['mesas'] = array_values(array_filter($mesas, function($m) use ($area) {
            return $m['id_area'] == $area['id_area'];
        }));
        return $area;
    }, $areas);

    http_response_code(200);
    echo json_encode(["status" => "success", "mapa" => $mapa]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
}