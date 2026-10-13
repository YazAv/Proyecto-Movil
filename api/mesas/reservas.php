<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json');

$db = new Database();
$conn = $db->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $id_mesa = isset($_GET['id_mesa']) ? (int)$_GET['id_mesa'] : 0;
        
        if ($id_mesa > 0) {
            $stmt = $conn->prepare("
                SELECT r.*, m.numero_mesa 
                FROM reservas r
                JOIN mesas m ON m.id_mesa = r.id_mesa
                WHERE r.id_mesa = :id_mesa 
                  AND r.estado = 'programada'
                  AND (r.fecha_reserva + r.hora_reserva) >= CURRENT_TIMESTAMP
                ORDER BY r.fecha_reserva ASC, r.hora_reserva ASC
            ");
            $stmt->execute([':id_mesa' => $id_mesa]);
        } else {
            $stmt = $conn->query("
                SELECT r.*, m.numero_mesa, a.nombre as area_nombre
                FROM reservas r
                JOIN mesas m ON m.id_mesa = r.id_mesa
                JOIN areas a ON a.id_area = m.id_area
                WHERE r.estado = 'programada'
                  AND (r.fecha_reserva + r.hora_reserva) >= CURRENT_TIMESTAMP
                ORDER BY r.fecha_reserva ASC, r.hora_reserva ASC
            ");
        }
        
        $reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "success", "reservas" => $reservas]);

    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);
        
        $id_mesa        = (int)($input['id_mesa'] ?? 0);
        $nombre_cliente = trim($input['nombre_cliente'] ?? '');
        $telefono       = trim($input['telefono'] ?? '');
        $fecha_reserva  = $input['fecha_reserva'] ?? date('Y-m-d');
        $hora_reserva   = $input['hora_reserva'] ?? date('H:i');
        $num_personas   = (int)($input['num_personas'] ?? 2);
        $tipo_evento    = trim($input['tipo_evento'] ?? 'Normal');
        $alergias_notas = trim($input['alergias_notas'] ?? '');

        if ($id_mesa <= 0 || empty($nombre_cliente)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Por favor complete el nombre del cliente y seleccione la mesa."]);
            exit();
        }

        // Tolerancia de 5 minutos por pequeñas discrepancias de reloj entre cliente y servidor
        $timestamp_reserva = strtotime("$fecha_reserva $hora_reserva");
        if ($timestamp_reserva < (time() - 300)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "No es posible reservar en un horario o fecha que ya transcurrió."]);
            exit();
        }

        $stmt = $conn->prepare("
            INSERT INTO reservas (id_mesa, nombre_cliente, telefono, fecha_reserva, hora_reserva, num_personas, tipo_evento, alergias_notas)
            VALUES (:id_mesa, :nombre, :tel, :fecha, :hora, :personas, :evento, :notas)
        ");

        $stmt->execute([
            ':id_mesa'  => $id_mesa,
            ':nombre'   => $nombre_cliente,
            ':tel'      => $telefono,
            ':fecha'    => $fecha_reserva,
            ':hora'     => $hora_reserva,
            ':personas' => $num_personas,
            ':evento'   => $tipo_evento,
            ':notas'    => $alergias_notas
        ]);

        $conn->prepare("UPDATE mesas SET estado = 'reservada' WHERE id_mesa = :id_mesa")
             ->execute([':id_mesa' => $id_mesa]);

        echo json_encode(["status" => "success", "message" => "Reserva guardada con éxito."]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
}