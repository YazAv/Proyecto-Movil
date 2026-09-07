<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true);

if (
    !isset($input['producto_id']) || 
    !isset($input['nuevo_tiempo_min']) || 
    !isset($input['admin_id'])
) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios (producto_id, nuevo_tiempo_min, admin_id)"]);
    exit();
}

$producto_id = (int)$input['producto_id'];
$nuevo_tiempo_min = (int)$input['nuevo_tiempo_min'];
$admin_id = (int)$input['admin_id'];

if ($nuevo_tiempo_min <= 0 || $nuevo_tiempo_min > 180) {
    http_response_code(422);
    echo json_encode(["status" => "error", "message" => "El tiempo debe oscilar entre 1 y 180 minutos."]);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    // 1. Validar rol y estado activo consultando con JOIN a la tabla roles
    $stmtAuth = $conn->prepare("
        SELECT u.id, u.estado, LOWER(r.nombre) AS nombre_rol 
        FROM usuarios u
        INNER JOIN roles r ON u.rol_id = r.id
        WHERE u.id = ?
    ");
    $stmtAuth->execute([$admin_id]);
    $usuario = $stmtAuth->fetch();

    if (!$usuario || $usuario['estado'] !== 'activo') {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Usuario no autorizado o inactivo."]);
        exit();
    }

    // Comprobar rol de administrador (soporta admin, administrador, etc.)
    if ($usuario['nombre_rol'] !== 'admin' && $usuario['nombre_rol'] !== 'administrador') {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Acceso denegado: solo administradores pueden modificar los tiempos de preparación."]);
        exit();
    }

    // 2. Actualizar el tiempo estándar en el platillo
    $stmtUpdate = $conn->prepare("
        UPDATE productos 
        SET tiempo_preparacion_min = ? 
        WHERE id = ?
    ");
    $stmtUpdate->execute([$nuevo_tiempo_min, $producto_id]);

    if ($stmtUpdate->rowCount() === 0) {
        $stmtCheck = $conn->prepare("SELECT id FROM productos WHERE id = ?");
        $stmtCheck->execute([$producto_id]);
        if (!$stmtCheck->fetch()) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "El producto no existe."]);
            exit();
        }
    }

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Tiempo de preparación actualizado con éxito",
        "data" => [
            "producto_id" => $producto_id,
            "nuevo_tiempo_min" => $nuevo_tiempo_min,
            "autorizado_por" => $usuario['id'],
            "rol" => $usuario['nombre_rol']
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error interno: " . $e->getMessage()]);
}