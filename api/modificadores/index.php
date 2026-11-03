<?php
// api/modificadores/index.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

$rutas_conexion = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../../conexion.php',
    __DIR__ . '/../conexion.php'
];

foreach ($rutas_conexion as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        break;
    }
}

if (!isset($conn) && class_exists('Database')) {
    $db = new Database();
    $conn = $db->getConnection();
}

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"), true) ?: $_POST;
$accion = $data['accion'] ?? ($_GET['accion'] ?? 'listar_grupos');

try {
    // ==========================================
    // 1. LISTAR GRUPOS DE MODIFICADORES
    // ==========================================
    if ($accion === 'listar_grupos') {
        $stmt = $conn->query("SELECT * FROM grupos_modificadores ORDER BY id_grupo DESC");
        $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $grupos]);
        exit;
    }

    // ==========================================
    // 2. LISTAR OPCIONES DE UN GRUPO ESPECÍFICO
    // ==========================================
    if ($accion === 'listar_opciones') {
        $id_grupo = intval($_GET['id_grupo'] ?? 0);
        $stmt = $conn->prepare("SELECT * FROM opciones_modificadores WHERE id_grupo = ? ORDER BY id_opcion ASC");
        $stmt->execute([$id_grupo]);
        $opciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $opciones]);
        exit;
    }

    // ==========================================
    // 3. CREAR NUEVO GRUPO (Corregido para booleanos)
    // ==========================================
   if ($accion === 'crear_grupo' && $method === 'POST') {
        $nombre = trim($data['nombre'] ?? '');
        
        // Convertimos explícitamente a texto 'true' o 'false' para evitar el error "" de Postgres
        $es_obligatorio = filter_var($data['obligatorio'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $obligatorio_pg = $es_obligatorio ? 'true' : 'false';

        if (empty($nombre)) {
            throw new Exception("El nombre del grupo es obligatorio.");
        }

        $stmt = $conn->prepare("INSERT INTO grupos_modificadores (nombre, obligatorio) VALUES (?, ?) RETURNING id_grupo");
        // Pasamos la variable $obligatorio_pg que ya es segura para Postgres
        $stmt->execute([$nombre, $obligatorio_pg]);
        $id_nuevo = $stmt->fetchColumn();

        echo json_encode(['status' => 'success', 'message' => 'Grupo creado correctamente.', 'id_grupo' => $id_nuevo]);
        exit;
    }

    // ==========================================
    // 4. CREAR NUEVA OPCIÓN DENTRO DE UN GRUPO
    // ==========================================
    if ($accion === 'crear_opcion' && $method === 'POST') {
        $id_grupo = intval($data['id_grupo'] ?? 0);
        $nombre = trim($data['nombre'] ?? '');
        $precio_extra = floatval($data['precio_extra'] ?? 0.00);

        if ($id_grupo <= 0 || empty($nombre)) {
            throw new Exception("Datos incompletos para la opción.");
        }

        $stmt = $conn->prepare("INSERT INTO opciones_modificadores (id_grupo, nombre, precio_extra) VALUES (?, ?, ?) RETURNING id_opcion");
        $stmt->execute([$id_grupo, $nombre, $precio_extra]);
        $id_opcion = $stmt->fetchColumn();

        echo json_encode(['status' => 'success', 'message' => 'Opción agregada correctamente.', 'id_opcion' => $id_opcion]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
// ==========================================
    // 5. ELIMINAR GRUPO U OPCIÓN
    // ==========================================
    if ($accion === 'eliminar_grupo' && $method === 'POST') {
        $id_grupo = intval($data['id_grupo'] ?? 0);
        $conn->exec("DELETE FROM grupos_modificadores WHERE id_grupo = $id_grupo");
        echo json_encode(['status' => 'success', 'message' => 'Grupo eliminado']);
        exit;
    }

    if ($accion === 'eliminar_opcion' && $method === 'POST') {
        $id_opcion = intval($data['id_opcion'] ?? 0);
        $conn->exec("DELETE FROM opciones_modificadores WHERE id_opcion = $id_opcion");
        echo json_encode(['status' => 'success', 'message' => 'Opción eliminada']);
        exit;
    }

?>