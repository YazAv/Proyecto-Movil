<?php
// api/caja/corte_caja.php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');
error_reporting(E_ALL);
ini_set('display_errors', 0);

$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../../conexion.php',
    __DIR__ . '/../conexion.php',
    __DIR__ . '/conexion.php'
];

$conexion_ok = false;
foreach ($rutas as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        $conexion_ok = true;
        break;
    }
}

if (!$conexion_ok) {
    echo json_encode(['status' => 'error', 'message' => 'Falla crítica: No se encontró el archivo conexion.php']);
    exit;
}

if (!isset($conn) && class_exists('Database')) {
    $db = new Database();
    $conn = $db->getConnection();
}

try {
    $data = json_decode(file_get_contents("php://input"), true) ?: $_POST;
    $accion = $data['accion'] ?? '';

    if ($accion === 'abrir') {
        $monto_inicial = floatval($data['monto_inicial'] ?? 0);

        $stmtCheck = $conn->query("SELECT id_turno FROM turnos_caja WHERE LOWER(estado) = 'abierto' LIMIT 1");
        if ($stmtCheck->fetchColumn()) {
            throw new Exception("Ya existe un turno abierto. Por favor, ciérralo en Cortes antes de iniciar uno nuevo.");
        }

        $stmtIns = $conn->prepare("
            INSERT INTO turnos_caja (id_usuario, monto_inicial, estado, fecha_apertura) 
            VALUES (1, ?, 'abierto', CURRENT_TIMESTAMP) 
            RETURNING id_turno
        ");
        $stmtIns->execute([$monto_inicial]);
        $id_turno = $stmtIns->fetchColumn();

        echo json_encode([
            'status' => 'success', 
            'message' => 'Caja abierta exitosamente.', 
            'id_turno' => $id_turno
        ]);
        exit;

    } elseif ($accion === 'cerrar') {
        $id_turno = intval($data['id_turno'] ?? 0);
        $monto_final = floatval($data['monto_final'] ?? 0);

        if ($id_turno <= 0) throw new Exception("ID de turno inválido.");

        $stmtUpd = $conn->prepare("
            UPDATE turnos_caja 
            SET estado = 'cerrado', monto_final = ?, fecha_cierre = CURRENT_TIMESTAMP 
            WHERE id_turno = ?
        ");
        $stmtUpd->execute([$monto_final, $id_turno]);

        echo json_encode(['status' => 'success', 'message' => 'Turno cerrado correctamente.']);
        exit;

    } else {
        throw new Exception("La petición llegó vacía o la acción '$accion' no es válida.");
    }

} catch (PDOException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Error de Base de Datos: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>