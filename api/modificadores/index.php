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
$accion = $data['accion'] ?? ($_GET['accion'] ?? 'listar_opciones');

try {
    $asegurarGrupoDefault = function () use ($conn) {
        $stmt = $conn->prepare("SELECT id_grupo FROM grupos_modificadores WHERE LOWER(nombre) = LOWER(?) LIMIT 1");
        $stmt->execute(['Extras']);
        $id = $stmt->fetchColumn();

        if (!$id) {
            $ins = $conn->prepare("INSERT INTO grupos_modificadores (nombre, obligatorio) VALUES (?, ?) RETURNING id_grupo");
            $ins->execute(['Extras', 'false']);
            $id = $ins->fetchColumn();
        }
        return intval($id);
    };

    // Listar grupos (se mantiene por compatibilidad)
    if ($accion === 'listar_grupos') {
        $stmt = $conn->query("SELECT * FROM grupos_modificadores ORDER BY id_grupo DESC");
        echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // Listar extras/opciones (sin id_grupo devuelve todos los extras directos)
    if ($accion === 'listar_opciones' || $accion === 'listar_extras' || $accion === 'listar') {
        $id_grupo = intval($_GET['id_grupo'] ?? ($data['id_grupo'] ?? 0));
        if ($id_grupo > 0) {
            $stmt = $conn->prepare("SELECT * FROM opciones_modificadores WHERE id_grupo = ? ORDER BY id_opcion ASC");
            $stmt->execute([$id_grupo]);
        } else {
            $stmt = $conn->query("SELECT * FROM opciones_modificadores ORDER BY id_opcion DESC");
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $rows, 'modificadores' => $rows]);
        exit;
    }

    // Crear extra directo SIN exigir que el usuario cree un grupo
    if (($accion === 'crear_opcion' || $accion === 'crear_extra') && $method === 'POST') {
        $id_grupo = intval($data['id_grupo'] ?? 0);
        if ($id_grupo <= 0) {
            $id_grupo = $asegurarGrupoDefault();
        }

        $nombre = trim($data['nombre'] ?? '');
        $precio_extra = floatval($data['precio_extra'] ?? ($data['precio'] ?? 0.00));

        if (empty($nombre)) {
            throw new Exception("El nombre del extra es obligatorio.");
        }

        $stmt = $conn->prepare("INSERT INTO opciones_modificadores (id_grupo, nombre, precio_extra) VALUES (?, ?, ?) RETURNING id_opcion");
        $stmt->execute([$id_grupo, $nombre, $precio_extra]);
        $id_opcion = $stmt->fetchColumn();

        echo json_encode([
            'status' => 'success',
            'message' => 'Extra agregado correctamente.',
            'id_opcion' => $id_opcion
        ]);
        exit;
    }

    // Editar extra existente
    if (($accion === 'editar_opcion' || $accion === 'editar_extra' || $accion === 'actualizar_opcion') && $method === 'POST') {
        $id_opcion = intval($data['id_opcion'] ?? ($data['id'] ?? 0));
        $nombre = trim($data['nombre'] ?? '');
        $precio_extra = floatval($data['precio_extra'] ?? ($data['precio'] ?? 0.00));

        if ($id_opcion <= 0 || empty($nombre)) {
            throw new Exception("Datos incompletos para editar el extra.");
        }

        $stmt = $conn->prepare("UPDATE opciones_modificadores SET nombre = ?, precio_extra = ? WHERE id_opcion = ?");
        $stmt->execute([$nombre, $precio_extra, $id_opcion]);

        echo json_encode(['status' => 'success', 'message' => 'Extra actualizado correctamente.']);
        exit;
    }

    // Cancelar / eliminar extra
    if (($accion === 'eliminar_opcion' || $accion === 'cancelar_extra' || $accion === 'eliminar_extra') && $method === 'POST') {
        $id_opcion = intval($data['id_opcion'] ?? ($data['id'] ?? 0));
        if ($id_opcion <= 0) {
            throw new Exception("ID de extra no válido.");
        }

        $stmt = $conn->prepare("DELETE FROM opciones_modificadores WHERE id_opcion = ?");
        $stmt->execute([$id_opcion]);

        echo json_encode(['status' => 'success', 'message' => 'Extra cancelado correctamente.']);
        exit;
    }

    throw new Exception("Acción no reconocida.");
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}