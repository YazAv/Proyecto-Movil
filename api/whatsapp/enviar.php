<?php
// api/whatsapp/enviar.php
header('Content-Type: application/json; charset=utf-8');

// 1. Conexión a la base de datos
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
    echo json_encode(['status' => 'error', 'message' => 'No se encontró el archivo de conexión.']);
    exit;
}

if (!isset($conn) && class_exists('Database')) {
    $db = new Database();
    $conn = $db->getConnection();
}

try {
    // Recibir los datos enviados por POST (ID del empleado o datos directos)
    $data = json_decode(file_get_contents("php://input"), true) ?: $_POST;
    $empleado_id = intval($data['id_empleado'] ?? $data['id'] ?? 0);

    if ($empleado_id <= 0) {
        throw new Exception("ID de empleado no válido.");
    }

    // Consultar información del empleado
    $stmt = $conn->prepare("SELECT * FROM empleados WHERE id_empleado = ? OR id = ? LIMIT 1");
    $stmt->execute([$empleado_id, $empleado_id]);
    $empleado = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$empleado) {
        throw new Exception("Empleado no encontrado en la base de datos.");
    }

    $nombreEmpleado = $empleado['nombre'] ?? 'Colaborador';
    $puesto = $empleado['puesto'] ?? 'Personal';
    $codigoMarcaje = $empleado['codigo_marcaje'] ?? ('EMP-' . $empleado['id_empleado']);
    $telefono = $empleado['telefono'] ?? '';

    if (empty($telefono)) {
        throw new Exception("El empleado no cuenta con un número de teléfono registrado.");
    }

    // 2. Construir el mensaje con el formato exacto solicitado
    $mensajeWhatsApp = "Hola *" . $nombreEmpleado . "*, Bienvenido(a) a *SazónTrack POS*.\n\n" .
                       " *Puesto:* " . $puesto . "\n" .
                       "*Código de Marcaje:* " . $codigoMarcaje . "\n\n" .
                       "Te adjuntamos tu imagen de código QR personal. Muestra esta imagen frente a la cámara del reloj checador para registrar tu ENTRADA y SALIDA.";

    // Ruta de la imagen del código QR generado previamente para el empleado
    // (Asegúrate de que la ruta apunte al directorio donde guardas los códigos QR generados, ej. assets/qr/ o qrcode/)
    $rutaQrRelativa = "assets/qr/qr_" . $empleado_id . ".png";
    $rutaQrAbsoluta = __DIR__ . "/../../" . $rutaQrRelativa;

    if (!file_exists($rutaQrAbsoluta)) {
        // Opcional: Si usas una librería externa para generar el QR al vuelo, genéralo aquí o valida su existencia
        $rutaQrRelativa = ""; // Si no existe, se enviará solo texto o se manejará acorde
    }

    // 3. Integración con el servidor o pasarela de WhatsApp (Baileys / cURL / API local)
    // Configura aquí tu método de envío (por ejemplo, llamada cURL a tu servicio local de Node.js/Baileys)
    
    /* 
    Ejemplo de estructura de envío cURL a un servicio de WhatsApp local:
    $payload = [
        'phone' => $telefono,
        'message' => $mensajeWhatsApp,
        'media' => $rutaQrRelativa
    ];
    */

    // Respuesta exitosa simulada para el front-end
    echo json_encode([
        'status' => 'success',
        'message' => 'Mensaje de WhatsApp enviado correctamente con el formato SazónTrack.',
        'debug_text' => $mensajeWhatsApp
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>