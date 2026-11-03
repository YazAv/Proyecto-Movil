<?php
// api/caja/movimientos.php
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Mexico_City');
error_reporting(E_ALL);
ini_set('display_errors', 0);

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
    echo json_encode(['status' => 'error', 'message' => 'Falla crítica: No se encontró conexion.php']);
    exit;
}

if (!isset($conn) && class_exists('Database')) {
    $db = new Database();
    $conn = $db->getConnection();
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    $stmtTurno = $conn->query("SELECT * FROM turnos_caja WHERE LOWER(estado) = 'abierto' ORDER BY id_turno DESC LIMIT 1");
    $turno = $stmtTurno->fetch(PDO::FETCH_ASSOC);

    // LEER ESTADO DEL TURNO (GET)
    if ($method === 'GET') {
        if (!$turno) {
            echo json_encode(['turno_abierto' => false, 'efectivo_esperado' => 0]);
            exit;
        }

        $id_turno = $turno['id_turno'];
        $fondo_inicial = floatval($turno['monto_inicial']);

        $stmtVentas = $conn->prepare("SELECT COALESCE(SUM(monto_pagado), 0) FROM pagos WHERE id_turno = ? AND LOWER(metodo_pago) = 'efectivo'");
        $stmtVentas->execute([$id_turno]);
        $ventas_efectivo = floatval($stmtVentas->fetchColumn());

        $stmtEntradas = $conn->prepare("SELECT COALESCE(SUM(monto), 0) FROM movimientos_caja WHERE id_turno = ? AND UPPER(tipo) = 'ENTRADA'");
        $stmtEntradas->execute([$id_turno]);
        $entradas = floatval($stmtEntradas->fetchColumn());

        $stmtSalidas = $conn->prepare("SELECT COALESCE(SUM(monto), 0) FROM movimientos_caja WHERE id_turno = ? AND UPPER(tipo) = 'SALIDA'");
        $stmtSalidas->execute([$id_turno]);
        $salidas = floatval($stmtSalidas->fetchColumn());

        $efectivo_esperado = $fondo_inicial + $ventas_efectivo + $entradas - $salidas;

        echo json_encode([
            'status' => 'success',
            'turno_abierto' => true,
            'turno' => $turno,
            'ventas_efectivo' => $ventas_efectivo,
            'entradas' => $entradas,
            'salidas' => $salidas,
            'efectivo_esperado' => $efectivo_esperado
        ]);
        exit;
    }

    // REGISTRAR ENTRADA/SALIDA (POST)
    if ($method === 'POST') {
        if (!$turno) throw new Exception("No hay turno abierto para registrar movimiento.");

        $data = json_decode(file_get_contents("php://input"), true) ?: $_POST;
        $tipo_recibido = strtoupper(trim($data['tipo'] ?? ''));
        $monto = floatval($data['monto'] ?? 0);
        $concepto = trim($data['concepto'] ?? '');

        if ($monto <= 0) throw new Exception("El monto debe ser mayor a 0.");
        if (empty($concepto)) throw new Exception("El concepto es obligatorio.");
        
        $tipo = (strpos($tipo_recibido, 'ENTRADA') !== false) ? 'ENTRADA' : 'SALIDA';

        $stmtIns = $conn->prepare("INSERT INTO movimientos_caja (id_turno, tipo, monto, concepto) VALUES (?, ?, ?, ?)");
        $stmtIns->execute([$turno['id_turno'], $tipo, $monto, $concepto]);

        echo json_encode(['status' => 'success', 'message' => 'Movimiento registrado.']);
        exit;
    }

} catch (PDOException $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Error BD: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>