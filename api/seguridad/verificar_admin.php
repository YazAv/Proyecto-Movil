<?php
// api/seguridad/verificar_admin.php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Validar si hay sesión activa y si el rol corresponde al Administrador
$rol_usuario = isset($_SESSION['rol']) ? strtoupper(trim($_SESSION['rol'])) : '';

if ($rol_usuario !== 'ADMINISTRADOR' && $rol_usuario !== 'ADMIN') {
    http_response_code(403); // Acceso Prohibido
    echo json_encode([
        'status' => 'error',
        'message' => 'Acceso denegado. Este módulo de reportes gerenciales es exclusivo para el Administrador.'
    ]);
    exit;
}
?>