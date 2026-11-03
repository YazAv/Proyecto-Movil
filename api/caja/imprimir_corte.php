<?php
// api/caja/imprimir_corte.php
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('America/Mexico_City');

// 1. Conexión a la base de datos
if (file_exists(__DIR__ . '/../../config/conexion.php')) {
    require_once __DIR__ . '/../../config/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
} else {
    die("Error: No se encontró el archivo de conexión a la base de datos.");
}

if (!isset($conn) && class_exists('Database')) {
    $db = new Database();
    $conn = $db->getConnection();
}

if (!$conn) {
    die("Error crítico: No se pudo conectar a la base de datos.");
}

// 2. Detección automática de FPDF
$fpdf_encontrado = false;
$rutas_fpdf = [
    __DIR__ . '/../../fpdf/fpdf.php',
    __DIR__ . '/../fpdf/fpdf.php',
    __DIR__ . '/fpdf/fpdf.php'
];

foreach ($rutas_fpdf as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        $fpdf_encontrado = true;
        break;
    }
}

if (!$fpdf_encontrado) {
    die("Error crítico: No se encontró la librería FPDF. Asegúrate de tener la carpeta 'fpdf'.");
}

define('MONEDA', '$');

$id_turno = isset($_GET['id_turno']) ? intval($_GET['id_turno']) : 0;
$tipo_corte = isset($_GET['tipo']) ? strtoupper($_GET['tipo']) : 'X';

if ($id_turno <= 0) {
    die("Error: ID de turno no válido para la impresión del corte.");
}

try {
    // 3. Consultar datos del turno y del usuario de forma segura (usando id_usuario o id_empleado si existiera)
    $stmtTurno = $conn->prepare("
        SELECT t.*, 
               COALESCE(u.nombre, 'Administrador') AS cajero
        FROM turnos_caja t
        LEFT JOIN usuarios u ON t.id_usuario = u.id
        WHERE t.id_turno = ?
        LIMIT 1
    ");
    $stmtTurno->execute([$id_turno]);
    $turno = $stmtTurno->fetch(PDO::FETCH_ASSOC);

    if (!$turno) {
        // Fallback por si la relación de usuarios utiliza otra columna
        $stmtTurnoAlt = $conn->prepare("SELECT * FROM turnos_caja WHERE id_turno = ? LIMIT 1");
        $stmtTurnoAlt->execute([$id_turno]);
        $turno = $stmtTurnoAlt->fetch(PDO::FETCH_ASSOC);
        if ($turno) {
            $turno['cajero'] = 'Administrador General';
        } else {
            die("Error: No se encontró el turno especificado.");
        }
    }

    // 4. Calcular ventas en efectivo y totales del turno
    $stmtVentas = $conn->prepare("
        SELECT COALESCE(SUM(monto_pagado), 0) AS total_efectivo 
        FROM pagos 
        WHERE id_turno = ? AND LOWER(metodo_pago) = 'efectivo'
    ");
    $stmtVentas->execute([$id_turno]);
    $ventasEfectivo = floatval($stmtVentas->fetchColumn());

    // 5. Calcular Entradas y Salidas de caja
    $stmtMovs = $conn->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN UPPER(tipo) = 'ENTRADA' THEN monto ELSE 0 END), 0) AS entradas,
            COALESCE(SUM(CASE WHEN UPPER(tipo) = 'SALIDA' THEN monto ELSE 0 END), 0) AS salidas
        FROM movimientos_caja 
        WHERE id_turno = ?
    ");
    $stmtMovs->execute([$id_turno]);
    $movs = $stmtMovs->fetch(PDO::FETCH_ASSOC);
    $entradas = floatval($movs['entradas'] ?? 0);
    $salidas = floatval($movs['salidas'] ?? 0);

    $fondoInicial = floatval($turno['monto_inicial'] ?? 0);
    $efectivoEsperado = $fondoInicial + $ventasEfectivo + $entradas - $salidas;
    $montoFinalContado = floatval($turno['monto_final'] ?? $efectivoEsperado);
    $diferencia = $montoFinalContado - $efectivoEsperado;

} catch (Exception $e) {
    die("Error al generar el corte: " . $e->getMessage());
}

// ==================== GENERACIÓN DEL PDF CORTE X / Z ====================
$pdf = new FPDF('P', 'mm', array(80, 220));
$pdf->AddPage();
$pdf->SetMargins(5, 5, 5);
$pdf->SetAutoPageBreak(true, 5);

// Encabezado
$pdf->SetFont('Arial', 'B', 13);
$pdf->Cell(70, 5, 'SazonTrack', 0, 1, 'C');

$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(70, 5, mb_convert_encoding('CORTE DE CAJA (' . $tipo_corte . ')', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(90, 90, 90);
$pdf->MultiCell(70, 3, mb_convert_encoding('Reporte Oficial de Arqueo y Turno', 'ISO-8859-1', 'UTF-8'), 0, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

// Datos generales
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(25, 4, 'Num Turno:', 0, 0, 'L');
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(45, 4, '#' . $turno['id_turno'], 0, 1, 'L');

$pdf->Cell(25, 4, 'Estado:', 0, 0, 'L');
$pdf->Cell(45, 4, ucfirst($turno['estado']), 0, 1, 'L');

$pdf->Cell(25, 4, 'Apertura:', 0, 0, 'L');
$pdf->Cell(45, 4, $turno['fecha_apertura'], 0, 1, 'L');

if (!empty($turno['fecha_cierre'])) {
    $pdf->Cell(25, 4, 'Cierre:', 0, 0, 'L');
    $pdf->Cell(45, 4, $turno['fecha_cierre'], 0, 1, 'L');
}

$pdf->Cell(70, 4, mb_convert_encoding('Cajero: ' . $turno['cajero'], 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');

$pdf->Cell(70, 2, '-------------------------------------------------------------------------', 0, 1, 'L');

// Detalle financiero
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(70, 4, 'RESUMEN FINANCIERO', 0, 1, 'L');
$pdf->SetFont('Arial', '', 8);

$pdf->Cell(45, 4, 'Fondo Inicial en Caja:', 0, 0, 'L');
$pdf->Cell(25, 4, MONEDA . number_format($fondoInicial, 2), 0, 1, 'R');

$pdf->Cell(45, 4, 'Ventas en Efectivo:', 0, 0, 'L');
$pdf->Cell(25, 4, MONEDA . number_format($ventasEfectivo, 2), 0, 1, 'R');

$pdf->Cell(45, 4, 'Entradas de Efectivo:', 0, 0, 'L');
$pdf->Cell(25, 4, MONEDA . number_format($entradas, 2), 0, 1, 'R');

$pdf->Cell(45, 4, 'Salidas de Efectivo:', 0, 0, 'L');
$pdf->Cell(25, 4, MONEDA . number_format($salidas, 2), 0, 1, 'R');

$pdf->Cell(70, 2, '-------------------------------------------------------------------------', 0, 1, 'L');

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(45, 5, 'Efectivo Esperado:', 0, 0, 'L');
$pdf->Cell(25, 5, MONEDA . number_format($efectivoEsperado, 2), 0, 1, 'R');

$pdf->SetFont('Arial', '', 8);
$pdf->Cell(45, 4, 'Efectivo Contado (Arqueo):', 0, 0, 'L');
$pdf->Cell(25, 4, MONEDA . number_format($montoFinalContado, 2), 0, 1, 'R');

$pdf->SetFont('Arial', 'B', 9);
$pdf->Cell(45, 5, 'Diferencia:', 0, 0, 'L');
$pdf->Cell(25, 5, ($diferencia >= 0 ? '+' : '') . MONEDA . number_format($diferencia, 2), 0, 1, 'R');

$pdf->Cell(70, 2, '-------------------------------------------------------------------------', 0, 1, 'L');

// Pie de página
$pdf->SetFont('Arial', '', 7);
$pdf->Cell(70, 4, mb_convert_encoding('Impreso desde SazónTrack POS', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');

// Salida automática al navegador para previsualizar e imprimir
$pdf->Output('I', 'corte_' . $tipo_corte . '_turno_' . $id_turno . '.pdf');
?>