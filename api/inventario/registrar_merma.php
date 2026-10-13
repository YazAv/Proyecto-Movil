<?php
// API para registrar mermas de insumos en inventario
require_once '../../config/conexion.php';

header("Content-Type: application/json; charset=UTF-8");

// Leer datos del cliente
$data = json_decode(file_get_contents("php://input"), true);

// Validar datos requeridos
if (empty($data['id_insumo']) || empty($data['cantidad']) || empty($data['motivo'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios."]);
    exit();
}

$id_insumo = intval($data['id_insumo']);
$cantidad = floatval($data['cantidad']);
$motivo = trim($data['motivo']);
$notas = !empty($data['notas']) ? trim($data['notas']) : "Merma registrada";

try {
    $conn->beginTransaction();

    // 1. Descontar stock del insumo
    $stmtStock = $conn->prepare("
        UPDATE insumos 
        SET stock_actual = stock_actual - :cantidad 
        WHERE id_insumo = :id_insumo
    ");
    $stmtStock->execute([
        ':cantidad'  => $cantidad,
        ':id_insumo' => $id_insumo
    ]);

    // 2. Registrar el movimiento de merma
    $stmtMov = $conn->prepare("
        INSERT INTO movimientos_inventario 
        (id_insumo, tipo, cantidad, tipo_movimiento, notas, fecha)
        VALUES 
        (:id_insumo, 'merma', :cantidad, 'merma', :notas, NOW())
    ");
    $stmtMov->execute([
        ':id_insumo' => $id_insumo,
        ':cantidad'  => $cantidad,
        ':notas'     => "[$motivo] " . $notas
    ]);

    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Merma registrada correctamente."
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}