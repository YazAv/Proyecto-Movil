<?php
// api/reservas/index.php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$rutas = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../config/conexion.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../conexion.php'
];
foreach ($rutas as $r) {
    if (file_exists($r)) {
        require_once $r;
        break;
    }
}
if (!isset($conn) && isset($pdo)) $conn = $pdo;
if (!isset($conn) && class_exists('Database')) $conn = (new Database())->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

try {
    // 1. LISTAR RESERVACIONES Y MESAS DISPONIBLES (GET)
    if ($method === 'GET') {
        $fecha = $_GET['fecha'] ?? '';

        $sql = "
            SELECT 
                r.id_reserva,
                r.id_mesa,
                COALESCE(m.numero_mesa::text, 'Sin asignar') AS numero_mesa,
                COALESCE(a.nombre, 'General') AS area_nombre,
                r.nombre_cliente,
                COALESCE(r.telefono, '') AS telefono,
                TO_CHAR(r.fecha_reserva, 'YYYY-MM-DD') AS fecha_reserva,
                TO_CHAR(r.fecha_reserva, 'DD/MM/YYYY') AS fecha_fmt,
                TO_CHAR(r.hora_reserva, 'HH24:MI') AS hora_reserva,
                COALESCE(r.num_personas, 2) AS num_personas,
                COALESCE(r.tipo_evento, 'Normal') AS tipo_evento,
                COALESCE(r.alergias_notas, '') AS alergias_notas,
                LOWER(COALESCE(r.estado, 'programada')) AS estado
            FROM public.reservas r
            LEFT JOIN public.mesas m ON r.id_mesa = m.id_mesa
            LEFT JOIN public.areas a ON m.id_area = a.id_area
        ";

        $params = [];
        if ($fecha !== '') {
            $sql .= " WHERE r.fecha_reserva = ? ";
            $params[] = $fecha;
        }
        $sql .= " ORDER BY r.fecha_reserva DESC, r.hora_reserva ASC ";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Lista de mesas para el selector del formulario
        $stmtMesas = $conn->query("
            SELECT m.id_mesa, m.numero_mesa, m.capacidad, m.estado, COALESCE(a.nombre, 'Salón') AS area
            FROM public.mesas m
            LEFT JOIN public.areas a ON m.id_area = a.id_area
            ORDER BY m.id_mesa ASC
        ");
        $mesas = $stmtMesas->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => $reservas,
            'mesas' => $mesas
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2. CREAR O EDITAR RESERVACIÓN (POST / PUT)
    if ($method === 'POST' || $method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) $input = $_POST;

        // Cambio rápido de estado (ej. Sentar cliente, Completar, Cancelar)
        if (isset($input['accion']) && $input['accion'] === 'cambiar_estado') {
            $idReserva = intval($input['id_reserva'] ?? 0);
            $nuevoEstado = strtolower(trim($input['estado'] ?? 'programada'));

            $stmtGet = $conn->prepare("SELECT id_mesa FROM public.reservas WHERE id_reserva = ?");
            $stmtGet->execute([$idReserva]);
            $idMesa = $stmtGet->fetchColumn();

            $stmtUpd = $conn->prepare("UPDATE public.reservas SET estado = ? WHERE id_reserva = ?");
            $stmtUpd->execute([$nuevoEstado, $idReserva]);

            if ($idMesa) {
                if ($nuevoEstado === 'en_mesa') {
                    $conn->prepare("UPDATE public.mesas SET estado = 'ocupada' WHERE id_mesa = ?")->execute([$idMesa]);
                } elseif (in_array($nuevoEstado, ['cancelada', 'completada'])) {
                    $conn->prepare("UPDATE public.mesas SET estado = 'disponible' WHERE id_mesa = ? AND LOWER(estado) = 'reservada'")->execute([$idMesa]);
                }
            }

            echo json_encode(['status' => 'success', 'message' => 'Estado actualizado correctamente']);
            exit;
        }

        $idReserva     = !empty($input['id_reserva']) ? intval($input['id_reserva']) : null;
        $nombreCliente = trim($input['nombre_cliente'] ?? '');
        $telefono      = trim($input['telefono'] ?? '');
        $fechaReserva  = trim($input['fecha_reserva'] ?? date('Y-m-d'));
        $horaReserva   = trim($input['hora_reserva'] ?? '14:00');
        $numPersonas   = max(1, intval($input['num_personas'] ?? 2));
        $idMesa        = !empty($input['id_mesa']) ? intval($input['id_mesa']) : null;
        $tipoEvento    = trim($input['tipo_evento'] ?? 'Normal');
        $alergiasNotas = trim($input['alergias_notas'] ?? '');
        $estado        = strtolower(trim($input['estado'] ?? 'programada'));

        if ($nombreCliente === '') {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'El nombre del cliente es obligatorio']);
            exit;
        }

        if ($idReserva) {
            $stmt = $conn->prepare("
                UPDATE public.reservas
                SET id_mesa = ?, nombre_cliente = ?, telefono = ?, fecha_reserva = ?,
                    hora_reserva = ?, num_personas = ?, tipo_evento = ?, alergias_notas = ?, estado = ?
                WHERE id_reserva = ?
            ");
            $stmt->execute([
                $idMesa, $nombreCliente, $telefono, $fechaReserva,
                $horaReserva, $numPersonas, $tipoEvento, $alergiasNotas, $estado, $idReserva
            ]);
            $msg = 'Reservación actualizada';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO public.reservas 
                    (id_mesa, nombre_cliente, telefono, fecha_reserva, hora_reserva, num_personas, tipo_evento, alergias_notas, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                RETURNING id_reserva
            ");
            $stmt->execute([
                $idMesa, $nombreCliente, $telefono, $fechaReserva,
                $horaReserva, $numPersonas, $tipoEvento, $alergiasNotas, $estado
            ]);
            $idReserva = $stmt->fetchColumn();
            $msg = 'Reservación registrada correctamente';
        }

        // Si la reserva es para hoy y tiene mesa asignada, marcarla como reservada si estaba disponible
        if ($idMesa && $fechaReserva === date('Y-m-d') && $estado === 'programada') {
            $conn->prepare("UPDATE public.mesas SET estado = 'reservada' WHERE id_mesa = ? AND LOWER(estado) = 'disponible'")
                 ->execute([$idMesa]);
        }

        echo json_encode([
            'status' => 'success',
            'message' => $msg,
            'id_reserva' => $idReserva
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3. ELIMINAR RESERVACIÓN (DELETE)
    if ($method === 'DELETE') {
        $idReserva = intval($_GET['id'] ?? 0);
        $conn->prepare("DELETE FROM public.reservas WHERE id_reserva = ?")->execute([$idReserva]);
        echo json_encode(['status' => 'success', 'message' => 'Reservación eliminada']);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>