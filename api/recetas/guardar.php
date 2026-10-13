<?php
// api/recetas/guardar_receta.php
require_once '../../config/conexion.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['id_producto']) || empty($data['ingredientes'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Datos incompletos para guardar la receta."]);
    exit();
}

$id_producto = intval($data['id_producto']);
$ingredientes = $data['ingredientes'];

try {
    $conn->beginTransaction();

    // 1. Limpiar receta anterior para este producto
    $stmtDel = $conn->prepare("DELETE FROM recetas WHERE id_producto = :id_producto OR producto_id = :id_producto");
    $stmtDel->execute([':id_producto' => $id_producto]);

    // 2. Insertar ingredientes actualizados
    $stmtIns = $conn->prepare("
        INSERT INTO recetas (id_producto, id_insumo, cantidad, rendimiento_factor)
        VALUES (:id_producto, :id_insumo, :cantidad, :rendimiento)
    ");

    foreach ($ingredientes as $ing) {
        $stmtIns->execute([
            ':id_producto'  => $id_producto,
            ':id_insumo'    => intval($ing['id_insumo']),
            ':cantidad'     => floatval($ing['cantidad']),
            ':rendimiento'  => floatval($ing['rendimiento_factor'] ?? 1.00)
        ]);
    }

    $conn->commit();
    echo json_encode(["status" => "success", "message" => "Receta e insumos actualizados correctamente."]);
} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al guardar en base de datos: " . $e->getMessage()]);
}