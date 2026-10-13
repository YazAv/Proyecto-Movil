<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

// Aceptar id_mesa_origen o mesa_origen_id o tomar la mesa desde la orden
$mesa_origen_id  = (int)($input['id_mesa_origen'] ?? $input['mesa_origen_id'] ?? 0);
$nueva_mesa_id   = (int)($input['nueva_mesa_id'] ?? $input['id_mesa_destino'] ?? 0);
$orden_id        = (int)($input['orden_id'] ?? 0);

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // 1. Si enviaron orden_id, buscar a qué mesa pertenece
    if ($orden_id > 0 && $mesa_origen_id === 0) {
        $stmtOrd = $conn->prepare("SELECT mesa_id FROM ordenes WHERE id = :oid LIMIT 1");
        $stmtOrd->execute([':oid' => $orden_id]);
        $ord = $stmtOrd->fetch(PDO::FETCH_ASSOC);
        if ($ord) {
            $mesa_origen_id = (int)$ord['mesa_id'];
        }
    }

    if ($nueva_mesa_id <= 0) {
        throw new Exception("Debe seleccionar una mesa destino válida.");
    }

    // 2. Si hay órdenes activas asociadas a la mesa origen, moverlas a la nueva mesa
    if ($mesa_origen_id > 0) {
        $stmtMover = $conn->prepare("
            UPDATE ordenes 
            SET mesa_id = :nueva 
            WHERE mesa_id = :origen AND estado NOT IN ('pagada', 'cancelada')
        ");
        $stmtMover->execute([
            ':nueva'  => $nueva_mesa_id,
            ':origen' => $mesa_origen_id
        ]);
    }

    // 3. Cambiar estados de las mesas en PostgreSQL
    if ($mesa_origen_id > 0) {
        $conn->prepare("UPDATE mesas SET estado = 'por_limpiar' WHERE id_mesa = :id OR id = :id")
             ->execute([':id' => $mesa_origen_id]);
    }

    $conn->prepare("UPDATE mesas SET estado = 'ocupada' WHERE id_mesa = :id OR id = :id")
         ->execute([':id' => $nueva_mesa_id]);

    $conn->commit();

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Mesa y comanda transferidas correctamente"
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}