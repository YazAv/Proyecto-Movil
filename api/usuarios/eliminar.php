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
    echo json_encode(["status" => "error", "message" => "Falta el id del usuario"]);
    exit();
}

$id = (int)$input['id'];

$db = new Database();
$conn = $db->getConnection();

try {
    // 1. Validar si el usuario tiene historial de comandas o turnos de caja
    $stmtComandas = $conn->prepare("SELECT COUNT(*) AS total FROM ordenes WHERE mesero_id = ?");
    $stmtComandas->execute([$id]);
    $totalOrdenes = (int)$stmtComandas->fetch()['total'];

    if ($totalOrdenes > 0) {
        // Baja lógica obligatoria
        $stmtBaja = $conn->prepare("UPDATE usuarios SET estado = 'inactivo' WHERE id = ?");
        $stmtBaja->execute([$id]);

        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "El usuario tiene órdenes registradas. Se cambió su estado a 'inactivo' para preservar el historial contable."
        ]);
        exit();
    }

    // 2. Si es un usuario recién creado sin historial, eliminación física completa
    $stmtDelete = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
    $stmtDelete->execute([$id]);

    if ($stmtDelete->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Usuario no encontrado"]);
        exit();
    }

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Usuario eliminado permanentemente del sistema"
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al procesar eliminación: " . $e->getMessage()]);
}