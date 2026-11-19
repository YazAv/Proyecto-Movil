<?php
// api/mesas/listar_mesas.php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../config/conexion.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../conexion.php'
];
foreach ($rutas as $r) { if (file_exists($r)) { require_once $r; break; } }
if (!isset($conn) && isset($pdo)) $conn = $pdo;
if (!isset($conn) && class_exists('Database')) $conn = (new Database())->getConnection();

try {
    $stmt = $conn->query("
        SELECT m.id_mesa, m.numero_mesa, m.capacidad, m.estado, m.id_area,
               COALESCE(a.nombre, 'Salón Principal') AS area_nombre
        FROM public.mesas m
        LEFT JOIN public.areas_mesas a ON m.id_area = a.id_area
        ORDER BY m.id_mesa ASC
    ");
    $mesas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'data' => $mesas], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    // Respaldo si la tabla areas_mesas tiene otro nombre
    $stmt2 = $conn->query("SELECT id_mesa, numero_mesa, capacidad, estado FROM public.mesas ORDER BY id_mesa ASC");
    $mesas = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'data' => $mesas], JSON_UNESCAPED_UNICODE);
}
?>