<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

$tipo_login = $input['tipo_login'] ?? 'credenciales';
$pin        = trim($input['pin'] ?? '');
$username   = trim($input['username'] ?? '');
$password   = trim($input['password'] ?? '');

$db = new Database();
$conn = $db->getConnection();

try {
    if ($tipo_login === 'pin') {
        if (empty($pin)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Ingrese su PIN de acceso"]);
            exit();
        }

        $pin_md5 = md5($pin);

        // Buscar PIN contra pin_hash, pin, password o clave en MD5 y texto plano
        $stmt = $conn->prepare("
            SELECT 
                u.*,
                e.id_empleado,
                CONCAT(e.nombre, ' ', e.apellido) AS nombre_empleado,
                e.puesto
            FROM usuarios u
            LEFT JOIN empleados e ON u.empleado_id = e.id_empleado
            WHERE u.pin_hash = :pin_md5 
               OR u.pin_hash = :pin_raw 
               OR u.pin = :pin_raw 
               OR u.password = :pin_md5 
               OR u.password = :pin_raw
            LIMIT 1
        ");
        $stmt->execute([
            ':pin_md5' => $pin_md5, 
            ':pin_raw' => $pin
        ]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "PIN de acceso incorrecto"]);
            exit();
        }
    } else {
        if (empty($username) || empty($password)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Ingrese su usuario y contraseña"]);
            exit();
        }

        $stmt = $conn->prepare("
            SELECT 
                u.*,
                e.id_empleado,
                CONCAT(e.nombre, ' ', e.apellido) AS nombre_empleado,
                e.puesto
            FROM usuarios u
            LEFT JOIN empleados e ON u.empleado_id = e.id_empleado
            WHERE LOWER(u.nombre) = LOWER(:username)
        ");
        $stmt->execute([':username' => $username]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            http_response_code(401);
            echo json_encode(["status" => "error", "message" => "El usuario ingresado no existe"]);
            exit();
        }

        $pass_bd = $usuario['password'] ?? $usuario['contrasena'] ?? $usuario['clave'] ?? $usuario['pin_hash'] ?? '';
        $pass_md5 = md5($password);

        if (!empty($pass_bd)) {
            if ($pass_bd !== $password && $pass_bd !== $pass_md5) {
                http_response_code(401);
                echo json_encode(["status" => "error", "message" => "Contraseña incorrecta"]);
                exit();
            }
        }
    }

    $usr_id = (int)($usuario['id'] ?? $usuario['id_usuario'] ?? 0);

    // Obtener turno activo
    $turnoActivoId = null;
    try {
        $stmtTurno = $conn->prepare("
            SELECT id_turno 
            FROM turnos_caja 
            WHERE id_usuario = :uid AND estado = 'abierto'
            ORDER BY id_turno DESC LIMIT 1
        ");
        $stmtTurno->execute([':uid' => $usr_id]);
        $turno = $stmtTurno->fetch(PDO::FETCH_ASSOC);
        if ($turno) {
            $turnoActivoId = (int)$turno['id_turno'];
        }
    } catch (Exception $e) {
        $turnoActivoId = null;
    }

    http_response_code(200);
    echo json_encode([
        "status"  => "success",
        "message" => "Bienvenido al sistema",
        "session" => [
            "usuario_id"     => $usr_id,
            "username"       => $usuario['nombre'] ?? $usuario['username'] ?? 'Usuario',
            "rol"            => $usuario['rol'] ?? 'Cajero',
            "empleado_id"    => (int)($usuario['id_empleado'] ?? 0),
            "nombre_empleado"=> !empty(trim($usuario['nombre_empleado'] ?? '')) ? $usuario['nombre_empleado'] : ($usuario['nombre'] ?? 'Empleado'),
            "puesto"         => $usuario['puesto'] ?? 'Cajero',
            "turno_activo_id"=> $turnoActivoId
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
}