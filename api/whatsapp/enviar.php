<?php
error_reporting(0);
ini_set('display_errors', 0);

header("Content-Type: application/json; charset=UTF-8");

$input = file_get_contents("php://input");
$data = json_decode($input, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $telefono = $data['telefono'] ?? '';
    $mensaje = $data['mensaje'] ?? '';
    $qrBase64 = $data['qrBase64'] ?? '';
    $nombre = $data['nombre'] ?? 'Empleado';

    if (empty($telefono)) {
        echo json_encode(["status" => "error", "message" => "El empleado no tiene número de teléfono registrado."]);
        exit;
    }

    $payload = json_encode([
        'telefono' => $telefono,
        'mensaje' => $mensaje,
        'qrBase64' => $qrBase64,
        'nombre' => $nombre
    ]);

    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n",
            'method'  => 'POST',
            'content' => $payload,
            'timeout' => 20
        ]
    ];

    $context  = stream_context_create($options);
    $response = @file_get_contents('http://localhost:3001/api/whatsapp/enviar-qr', false, $context);

    if ($response === false) {
        echo json_encode([
            "status" => "error",
            "message" => "No se pudo conectar con el bot de WhatsApp. Verifica que 'node assets/js/server-wa.js' esté activo en la terminal."
        ]);
        exit;
    }

    echo $response;
} else {
    echo json_encode(["status" => "error", "message" => "Método no permitido."]);
}