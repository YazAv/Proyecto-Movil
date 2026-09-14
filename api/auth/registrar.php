<?php
require_once '../../config/conexion.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

$nombre_empleado = trim($input['nombre'] ?? '');
$apellido        = trim($input['apellido'] ?? '');
$username        = trim($input['username'] ?? '');
$password        = trim($input['password'] ?? '');
$pin             = trim($input['pin'] ?? '');
$puesto          = trim($input['puesto'] ?? 'Cajero');

if (empty($nombre_empleado) || empty($username) || empty($password) || empty($pin)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Todos los campos son obligatorios"]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // 1. Crear el registro del empleado
    $stmtEmp = $conn->prepare("
        INSERT INTO empleados (nombre, apellido, puesto, activo) 
        VALUES (:nombre, :apellido, :puesto, TRUE) 
        RETURNING id_empleado
    ");
    $stmtEmp->execute([
        ':nombre'   => $nombre_empleado,
        ':apellido' => $apellido,
        ':puesto'   => $puesto
    ]);
    $empleado = $stmtEmp->fetch(PDO::FETCH_ASSOC);
    $id_empleado = $empleado['id_empleado'];

    // 2. Insertar cuenta de usuario adaptándose al esquema de columnas
    try {
        $stmtUsr = $conn->prepare("
            INSERT INTO usuarios (nombre, pin_hash, password, rol, activo, empleado_id) 
            VALUES (:username, :pin_hash, :password, :rol, TRUE, :empleado_id)
        ");
        $stmtUsr->execute([
            ':username'    => $username,
            ':pin_hash'    => md5($pin),
            ':password'    => md5($password),
            ':rol'         => strtolower($puesto),
            ':empleado_id' => $id_empleado
        ]);
    } catch (PDOException $ex) {
        // Fallback si la columna password no existe en la BD
        $stmtUsr = $conn->prepare("
            INSERT INTO usuarios (nombre, pin_hash, rol, activo, empleado_id) 
            VALUES (:username, :pin_hash, :rol, TRUE, :empleado_id)
        ");
        $stmtUsr->execute([
            ':username'    => $username,
            ':pin_hash'    => md5($pin),
            ':rol'         => strtolower($puesto),
            ':empleado_id' => $id_empleado
        ]);
    }

    $conn->commit();

    http_response_code(201);
    echo json_encode(["status" => "success", "message" => "Cuenta registrada correctamente"]);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al registrar: " . $e->getMessage()]);
}