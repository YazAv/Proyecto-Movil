<?php
// Desactivar impresión de errores de texto para evitar alterar la respuesta JSON limpia
ini_set('display_errors', 0);
error_reporting(0);

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

// 1. Incluir el archivo de conexión
require_once __DIR__ . '/../../config/conexion.php';

try {
    // 2. Instanciar la clase Database y obtener la conexión PDO activa
    $database = new Database();
    $pdo = $database->getConnection();

    if (!$pdo) {
        throw new Exception("No se pudo obtener la conexión PDO a PostgreSQL.");
    }

    // 3. Consulta estructurada para PostgreSQL
    $sql = "SELECT 
                o.id_orden AS orden_id,
                o.total AS subtotal,
                o.creado_en AS hora_apertura,
                m.id_mesa,
                m.numero_mesa,
                'General' AS area_nombre
            FROM ordenes o
            JOIN mesas m ON m.id_mesa = o.id_mesa
            WHERE o.estado = 'ABIERTA'
            ORDER BY o.creado_en ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $mesas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Retornar JSON válido
    echo json_encode([
        'status' => 'success',
        'mesas' => $mesas
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error de BD: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error General: ' . $e->getMessage()
    ]);
}
?>