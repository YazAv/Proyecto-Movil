<?php
// api/empleados/index.php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// 1. Conexión a la base de datos
$rutas_conexion = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../../conexion.php',
    __DIR__ . '/../conexion.php',
    __DIR__ . '/conexion.php'
];

$conexion_ok = false;
foreach ($rutas_conexion as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        $conexion_ok = true;
        break;
    }
}

if (!$conexion_ok) {
    echo json_encode(['status' => 'error', 'message' => 'Falla crítica: No se encontró conexion.php']);
    exit;
}

if (!isset($conn) && class_exists('Database')) {
    $db = new Database();
    $conn = $db->getConnection();
}

// 2. Obtener datos de la petición
$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"), true) ?: $_POST;
$accion = $data['accion'] ?? ($_GET['accion'] ?? '');

try {
    // ==========================================
    // A) LISTAR EMPLEADOS
    // ==========================================
    if ($method === 'GET' || $accion === 'listar') {
        $stmt = $conn->query("SELECT * FROM empleados ORDER BY id_empleado DESC");
        $empleados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $empleados]);
        exit;
    }

    // ==========================================
    // B) ELIMINAR EMPLEADO
    // ==========================================
    if ($accion === 'eliminar' || $method === 'DELETE') {
        $id_empleado = intval($data['id_empleado'] ?? ($_GET['id_empleado'] ?? 0));
        if ($id_empleado > 0) {
            $stmt = $conn->prepare("DELETE FROM empleados WHERE id_empleado = ?");
            $stmt->execute([$id_empleado]);
            echo json_encode(['status' => 'success', 'message' => 'Empleado eliminado correctamente.']);
        } else {
            throw new Exception("ID de empleado inválido para eliminar.");
        }
        exit;
    }

    // ==========================================
    // C) CREAR O EDITAR EMPLEADO
    // ==========================================
    if ($method === 'POST' || $accion === 'guardar' || $accion === 'crear') {
        
        // Sincronizar secuencias (Ignorar si el driver no lo soporta)
        try {
            $conn->exec("SELECT setval('empleados_id_empleado_seq', COALESCE((SELECT MAX(id_empleado) FROM empleados), 1), false)");
        } catch (Exception $e) {}
        
        $id_empleado = intval($data['id_empleado'] ?? ($data['id'] ?? 0));
        $nombre      = trim($data['nombre'] ?? '');
        $apellido    = trim($data['apellido'] ?? '');
        $puesto      = trim($data['puesto'] ?? ($data['rol'] ?? 'Mesero'));
        $telefono    = trim($data['telefono'] ?? '');
        $pin         = trim($data['pin_acceso'] ?? ($data['pin'] ?? '1234'));
        $salario     = floatval($data['salario_por_hora'] ?? ($data['salario'] ?? 0.00));

        // Validaciones de nombre
        if (!empty($nombre) && empty($apellido)) {
            $partes = preg_split('/\s+/', $nombre, 2);
            $nombre = $partes[0];
            $apellido = $partes[1] ?? '-';
        }

        if (empty($nombre)) {
            throw new Exception("El nombre del empleado es obligatorio.");
        }

        if (empty($pin)) {
            $pin = '1234';
        }

        if ($id_empleado > 0) {
            // ==========================================
            // ACTUALIZAR EMPLEADO EXISTENTE
            // ==========================================
            $stmtUpdate = $conn->prepare("
                UPDATE empleados 
                SET nombre = ?, apellido = ?, puesto = ?, telefono = ?, pin_acceso = ?, salario_por_hora = ? 
                WHERE id_empleado = ?
            ");
            $stmtUpdate->execute([$nombre, $apellido, $puesto, $telefono, $pin, $salario, $id_empleado]);
            
            echo json_encode(['status' => 'success', 'message' => 'Empleado actualizado correctamente.']);
            exit;

        } else {
            // ==========================================
            // INSERTAR NUEVO EMPLEADO
            // ==========================================
            $codigo_marcaje_temp = 'EMP-' . rand(1000, 9999);
            
            $stmtInsert = $conn->prepare("
                INSERT INTO empleados (nombre, apellido, puesto, telefono, pin_acceso, salario_por_hora, codigo_marcaje) 
                VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id_empleado
            ");
            $stmtInsert->execute([$nombre, $apellido, $puesto, $telefono, $pin, $salario, $codigo_marcaje_temp]);
            
            // Obtener el ID insertado
            $nuevo_id = $stmtInsert->fetchColumn();
            if (!$nuevo_id) $nuevo_id = $conn->lastInsertId(); // Fallback
            
            // Actualizar el código de marcaje real basado en su ID definitivo
            $codigo_marcaje_real = 'EMP-' . $nuevo_id;
            $conn->exec("UPDATE empleados SET codigo_marcaje = '{$codigo_marcaje_real}' WHERE id_empleado = {$nuevo_id}");

            // NOTA PARA QR: Si generas tu QR en PHP, pon aquí el código (ej. QRcode::png(...))
            // para que la imagen se guarde en 'assets/qr/qr_X.png' antes de enviarla.

            // ==========================================
            // ENVÍO AUTOMÁTICO WHATSAPP (API OFICIAL META)
            // ==========================================
            if (!empty($telefono)) {
                // --- REEMPLAZA ESTAS CREDENCIALES ---
                $token_meta = 'TU_ACCESS_TOKEN_DE_META';
                $id_telefono_meta = 'TU_PHONE_NUMBER_ID';
                // ------------------------------------
                
                // Formatear el teléfono (Añade '52' si es de México y tiene 10 dígitos)
                $telefono_limpio = preg_replace('/[^0-9]/', '', $telefono);
                if (strlen($telefono_limpio) == 10) {
                    $telefono_limpio = '52' . $telefono_limpio; 
                }

                // IMPORTANTE: URL Pública para la imagen. En local usa Ngrok.
                // Cambia 'tu-dominio.com' por tu URL pública.
                $urlImagenPublica = "https://tu-dominio.com/restaurante_app/assets/qr/qr_" . $nuevo_id . ".png";

                // Texto con diseño formal de SazónTrack
                $mensajeWhatsApp = "Hola *" . $nombre . " " . $apellido . "*, bienvenido(a) a *SazónTrack POS*.\n\n" .
                                   "📌 *Puesto:* " . $puesto . "\n" .
                                   "🔑 *Código de Marcaje:* " . $codigo_marcaje_real . "\n\n" .
                                   "Te adjuntamos tu imagen de código QR personal. Muestra esta imagen frente a la cámara del reloj checador para registrar tu ENTRADA y SALIDA.";

                $payload = [
                    "messaging_product" => "whatsapp",
                    "recipient_type" => "individual",
                    "to" => $telefono_limpio,
                    "type" => "image",
                    "image" => [
                        "link" => $urlImagenPublica,
                        "caption" => $mensajeWhatsApp
                    ]
                ];

                $url_meta_api = "https://graph.facebook.com/v17.0/{$id_telefono_meta}/messages";
                
                $ch = curl_init($url_meta_api);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $token_meta,
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch, CURLOPT_TIMEOUT, 3); // Limitar a 3 segundos
                curl_exec($ch);
                curl_close($ch);
            }

            echo json_encode([
                'status' => 'success', 
                'message' => 'Empleado registrado y credenciales enviadas por WhatsApp.',
                'id_empleado' => $nuevo_id
            ]);
            exit;
        }
    }

} catch (PDOException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Error de BD: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>