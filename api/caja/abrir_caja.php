<?php
// api/caja/abrir_turno.php
header('Content-Type: application/json');

if (file_exists(__DIR__ . '/../../config/conexion.php')) {
    require_once __DIR__ . '/../../config/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
}

try {
    $data = json_decode(file_get_contents("php://input"), true);
    $fondo = floatval($data['fondo'] ?? 0);
    $id_usuario = 1;

    // Verificar si ya hay un turno abierto
    $stmtCheck = $conn->query("SELECT COUNT(*) FROM turnos_caja WHERE estado = 'abierto'");
    if ($stmtCheck->fetchColumn() > 0) {
        throw new Exception("Ya existe un turno abierto actualmente.");
    }

    $stmt = $conn->prepare("INSERT INTO turnos_caja (id_usuario, fondo_inicial, estado, fecha_inicio) VALUES (?, ?, 'abierto', CURRENT_TIMESTAMP)");
    $stmt->execute([$id_usuario, $fondo]);

    echo json_encode(['status' => 'success']);
} catch(Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>