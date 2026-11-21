<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

$tipo_login = $input['tipo_login'] ?? 'credenciales';
$pin        = trim((string)($input['pin'] ?? ''));
$usuario_id = intval($input['usuario_id'] ?? 0);
$username   = trim((string)($input['username'] ?? ''));
$password   = trim((string)($input['password'] ?? ''));

$db = new Database();
$conn = $db->getConnection();

function normalizarRol($puesto, $rolUsuario) {
    $raw = mb_strtolower(trim(!empty($puesto) ? $puesto : $rolUsuario), 'UTF-8');
    if (strpos($raw, 'admin') !== false) return 'Administrador';
    if (strpos($raw, 'caj') !== false) return 'Cajero';
    if (strpos($raw, 'coc') !== false || strpos($raw, 'chef') !== false) return 'Cocina';
    if (strpos($raw, 'bar') !== false) return 'Barra';
    if (strpos($raw, 'mes') !== false) return 'Mesero';
    return !empty($puesto) ? $puesto : (!empty($rolUsuario) ? $rolUsuario : 'Mesero');
}

try {
    $usuario = null;

    if ($tipo_login === 'pin') {
        if ($pin === '') {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Ingrese su PIN de acceso"]);
            exit();
        }

        $pin_md5 = md5($pin);

        // Si ya seleccionó su usuario específico cuando había PIN compartido
        if ($usuario_id > 0) {
            $stmt = $conn->prepare("
                SELECT 
                    u.*,
                    e.id_empleado,
                    TRIM(CONCAT(e.nombre, ' ', COALESCE(NULLIF(e.apellido, '-'), ''))) AS nombre_empleado,
                    e.puesto
                FROM usuarios u
                LEFT JOIN empleados e ON u.empleado_id = e.id_empleado
                WHERE u.id = :uid
                  AND COALESCE(u.activo, true) = true
                  AND COALESCE(e.activo, true) = true
                  AND (
                      TRIM(COALESCE(u.pin, '')) = :pin_raw
                      OR TRIM(COALESCE(u.pin_acceso, '')) = :pin_raw
                      OR TRIM(COALESCE(e.pin_acceso, '')) = :pin_raw
                      OR u.pin_hash = :pin_md5
                      OR u.pin_hash = :pin_raw
                      OR u.password = :pin_md5
                      OR u.password = :pin_raw
                  )
                LIMIT 1
            ");
            $stmt->execute([
                ':uid'     => $usuario_id,
                ':pin_raw' => $pin,
                ':pin_md5' => $pin_md5
            ]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Buscar todos los usuarios/empleados activos con ese PIN
            $stmt = $conn->prepare("
                SELECT 
                    u.*,
                    e.id_empleado,
                    TRIM(CONCAT(e.nombre, ' ', COALESCE(NULLIF(e.apellido, '-'), ''))) AS nombre_empleado,
                    e.puesto
                FROM usuarios u
                LEFT JOIN empleados e ON u.empleado_id = e.id_empleado
                WHERE COALESCE(u.activo, true) = true
                  AND COALESCE(e.activo, true) = true
                  AND (
                      TRIM(COALESCE(u.pin, '')) = :pin_raw
                      OR TRIM(COALESCE(u.pin_acceso, '')) = :pin_raw
                      OR TRIM(COALESCE(e.pin_acceso, '')) = :pin_raw
                      OR u.pin_hash = :pin_md5
                      OR u.pin_hash = :pin_raw
                      OR u.password = :pin_md5
                      OR u.password = :pin_raw
                  )
                ORDER BY e.id_empleado DESC NULLS LAST, u.id DESC
            ");
            $stmt->execute([
                ':pin_raw' => $pin,
                ':pin_md5' => $pin_md5
            ]);
            $coincidencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($coincidencias) === 0) {
                http_response_code(401);
                echo json_encode(["status" => "error", "message" => "PIN de acceso incorrecto o usuario inactivo"]);
                exit();
            }

            // Si hay más de un usuario activo con el mismo PIN, devolver lista para elegir quién entra
            if (count($coincidencias) > 1) {
                $candidatos = [];
                foreach ($coincidencias as $c) {
                    $cid = (int)($c['id'] ?? 0);
                    $cnombre = !empty(trim($c['nombre_empleado'] ?? '')) ? $c['nombre_empleado'] : ($c['nombre'] ?? 'Empleado');
                    $crol = normalizarRol($c['puesto'] ?? '', $c['rol'] ?? '');
                    $candidatos[] = [
                        "usuario_id" => $cid,
                        "nombre"     => $cnombre,
                        "rol"        => $crol
                    ];
                }
                http_response_code(200);
                echo json_encode([
                    "status"     => "multiple",
                    "message"    => "Hay varios empleados con este PIN. Selecciona tu perfil:",
                    "candidatos" => $candidatos
                ]);
                exit();
            }

            $usuario = $coincidencias[0];
        }

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
                TRIM(CONCAT(e.nombre, ' ', COALESCE(NULLIF(e.apellido, '-'), ''))) AS nombre_empleado,
                e.puesto
            FROM usuarios u
            LEFT JOIN empleados e ON u.empleado_id = e.id_empleado
            WHERE LOWER(TRIM(u.nombre)) = LOWER(:username)
              AND COALESCE(u.activo, true) = true
            ORDER BY e.id_empleado DESC NULLS LAST, u.id DESC
            LIMIT 1
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

    $usr_id = (int)($usuario['id'] ?? 0);
    $rol_final = normalizarRol($usuario['puesto'] ?? '', $usuario['rol'] ?? '');
    $nombre_final = !empty(trim($usuario['nombre_empleado'] ?? '')) ? $usuario['nombre_empleado'] : ($usuario['nombre'] ?? 'Empleado');

    // Obtener turno activo global
    $turnoActivoId = null;
    try {
        $stmtTurno = $conn->query("
            SELECT id_turno 
            FROM turnos_caja 
            WHERE estado = 'abierto'
            ORDER BY id_turno DESC LIMIT 1
        ");
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
            "usuario_id"      => $usr_id,
            "username"        => $nombre_final,
            "rol"             => $rol_final,
            "empleado_id"     => (int)($usuario['id_empleado'] ?? 0),
            "nombre_empleado" => $nombre_final,
            "puesto"          => $rol_final,
            "turno_activo_id" => $turnoActivoId
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
}
?>