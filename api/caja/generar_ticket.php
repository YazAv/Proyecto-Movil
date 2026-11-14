<?php
// api/caja/generar_ticket.php
header('Content-Type: text/html; charset=utf-8');

$rutas_conexion = [
    __DIR__ . '/../../config/conexion.php',
    __DIR__ . '/../config/conexion.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../conexion.php'
];

$conexion_encontrada = false;
foreach ($rutas_conexion as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        $conexion_encontrada = true;
        break;
    }
}

if (!$conexion_encontrada) die("Error crítico: No se encontró conexión a BD.");
if (!isset($conn) && isset($pdo)) $conn = $pdo;
if (!isset($conn) && class_exists('Database')) $conn = (new Database())->getConnection();

// Detección FPDF
$fpdf_encontrado = false;
$rutas_fpdf = [
    __DIR__ . '/../../fpdf/fpdf.php',
    __DIR__ . '/../fpdf/fpdf.php',
    __DIR__ . '/fpdf/fpdf.php',
    'C:/xampp/htdocs/restaurante_app/fpdf/fpdf.php'
];
foreach ($rutas_fpdf as $ruta) {
    if (file_exists($ruta)) { require_once $ruta; $fpdf_encontrado = true; break; }
}
if (!$fpdf_encontrado) die("Error crítico: No se encontró la librería FPDF.");

define('MONEDA', '$');
$orden_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['id_orden']) ? intval($_GET['id_orden']) : 0);
if ($orden_id <= 0) die("Error: ID de venta inválido.");

try {
    $stmtOrden = $conn->prepare("
        SELECT o.folio, o.total, o.descuento, o.propina,
               TO_CHAR(o.creado_en, 'DD/MM/YYYY') AS fecha_venta, 
               TO_CHAR(o.creado_en, 'HH24:MI') AS hora, 
               COALESCE(p.metodo_pago, o.metodo_pago, 'Efectivo') AS metodo_pago, 
               COALESCE(u.nombre, 'Cajero Principal') AS cajero,
               COALESCE(p.monto_pagado, 0) AS monto_recibido,
               COALESCE(p.cambio, 0) AS cambio_registrado
        FROM public.ordenes o 
        LEFT JOIN public.pagos p ON o.id_orden = p.id_orden 
        LEFT JOIN public.usuarios u ON o.id_usuario = u.id 
        WHERE o.id_orden = ? 
        LIMIT 1
    ");
    $stmtOrden->execute([$orden_id]);
    $orden = $stmtOrden->fetch(PDO::FETCH_ASSOC);

    if (!$orden) die("Orden no encontrada.");

    // Consultar detalles incluyendo notas (modificadores/extras)
    $stmtDetalles = $conn->prepare("
        SELECT nombre_producto AS producto, cantidad, precio_unitario, subtotal, notas 
        FROM public.detalle_orden 
        WHERE id_orden = ?
    ");
    $stmtDetalles->execute([$orden_id]);
    $articulos = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

    if (empty($articulos)) {
        $stmtAlt = $conn->prepare("SELECT nombre_producto AS producto, cantidad, precio_unitario, subtotal, notas FROM public.orden_detalle WHERE id_orden = ?");
        $stmtAlt->execute([$orden_id]);
        $articulos = $stmtAlt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

$total = floatval($orden['total']);
$descuento = floatval($orden['descuento']);
$propina = floatval($orden['propina']);
$monto_recibido = floatval($orden['monto_recibido']);
$cambio_real = floatval($orden['cambio_registrado']);

if ($monto_recibido > 0 && $monto_recibido >= $total && $cambio_real <= 0) {
    $cambio_real = $monto_recibido - $total;
}

// Generación PDF FPDF
$pdf = new FPDF('P', 'mm', array(80, 250));
$pdf->AddPage();
$pdf->SetMargins(5, 5, 5);
$pdf->SetAutoPageBreak(true, 5);

// Encabezado
$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(70, 5, 'SazonTrack', 0, 1, 'C');

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(90, 90, 90);
$pdf->MultiCell(70, 4, mb_convert_encoding('CONTROL & PUNTO DE VENTA', 'ISO-8859-1', 'UTF-8'), 0, 'C');
$pdf->SetTextColor(0, 0, 0);
$pdf->Ln(2);

$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(18, 5, mb_convert_encoding('Núm ticket: ', 'ISO-8859-1', 'UTF-8'), 0, 0, 'L');
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(52, 5, $orden['folio'], 0, 1, 'L');

$pdf->Cell(35, 4, 'Fecha: ' . $orden['fecha_venta'], 0, 0, 'L');
$pdf->Cell(35, 4, 'Hora: ' . $orden['hora'], 0, 1, 'R');

$pdf->Cell(70, 4, mb_convert_encoding('Cajero: ' . $orden['cajero'], 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
$pdf->Cell(70, 4, mb_convert_encoding('Método de Pago: ' . $orden['metodo_pago'], 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
$pdf->Cell(70, 2, '-------------------------------------------------------------------------', 0, 1, 'L');

// Tabla
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(8, 4, 'Cant', 0, 0, 'L');
$pdf->Cell(34, 4, mb_convert_encoding('Descripción', 'ISO-8859-1', 'UTF-8'), 0, 0, 'L');
$pdf->Cell(14, 4, 'P.U.', 0, 0, 'C');
$pdf->Cell(14, 4, 'Importe', 0, 1, 'R');
$pdf->Cell(70, 2, '-------------------------------------------------------------------------', 0, 1, 'L');

$totalProductos = 0;
$pdf->SetFont('Arial', '', 7);

if (!empty($articulos)) {
    foreach ($articulos as $item) {
        $cantidad = intval($item['cantidad']);
        $precio = floatval($item['precio_unitario']);
        $importe = floatval($item['subtotal'] ?? ($cantidad * $precio));
        $notas = trim($item['notas'] ?? '');
        $totalProductos += $cantidad;

        $pdf->Cell(8, 4, $cantidad, 0, 0, 'L');
        $yInicio = $pdf->GetY();
        $pdf->MultiCell(34, 4, mb_convert_encoding($item['producto'], 'ISO-8859-1', 'UTF-8'), 0, 'L');
        $yFin = $pdf->GetY();
        
        $pdf->SetXY(47, $yInicio);
        $pdf->Cell(14, 4, MONEDA . number_format($precio, 2), 0, 0, 'C');
        $pdf->Cell(14, 4, MONEDA . number_format($importe, 2), 0, 1, 'R');
        
        if ($yFin > $pdf->GetY()) { $pdf->SetY($yFin); }

        // Imprimir modificadores/extras impresos debajo del platillo
        if (!empty($notas)) {
            $pdf->SetFont('Arial', 'I', 6);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->Cell(8, 3, '', 0, 0, 'L');
            $pdf->MultiCell(62, 3, mb_convert_encoding('> ' . $notas, 'ISO-8859-1', 'UTF-8'), 0, 'L');
            $pdf->SetFont('Arial', '', 7);
            $pdf->SetTextColor(0, 0, 0);
        }
    }
}

$pdf->Cell(70, 2, '-------------------------------------------------------------------------', 0, 1, 'L');

// Totales
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(40, 4, mb_convert_encoding('Número de artículos:', 'ISO-8859-1', 'UTF-8'), 0, 0, 'L');
$pdf->Cell(30, 4, $totalProductos, 0, 1, 'R');

if ($descuento > 0) {
    $pdf->Cell(40, 4, 'Descuento:', 0, 0, 'L');
    $pdf->Cell(30, 4, '-' . MONEDA . number_format($descuento, 2), 0, 1, 'R');
}

if ($propina > 0) {
    $pdf->Cell(40, 4, 'Propina:', 0, 0, 'L');
    $pdf->Cell(30, 4, MONEDA . number_format($propina, 2), 0, 1, 'R');
}

$pdf->Ln(1);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(30, 5, 'TOTAL:', 0, 0, 'L');
$pdf->Cell(40, 5, MONEDA . number_format($total, 2), 0, 1, 'R');

// Cambio
if (strtolower(trim($orden['metodo_pago'])) == 'efectivo' && $monto_recibido > 0) {
    $pdf->SetFont('Arial', '', 8);
    $pdf->Cell(40, 4, 'Recibido:', 0, 0, 'L');
    $pdf->Cell(30, 4, MONEDA . number_format($monto_recibido, 2), 0, 1, 'R');
    
    $pdf->Cell(40, 4, 'Cambio devuelto:', 0, 0, 'L');
    $pdf->Cell(30, 4, MONEDA . number_format($cambio_real, 2), 0, 1, 'R');
}

$pdf->Ln(2);
$pdf->Cell(70, 2, '-------------------------------------------------------------------------', 0, 1, 'L');

$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(70, 5, mb_convert_encoding('¡GRACIAS POR SU PREFERENCIA!', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 7);
$pdf->Cell(70, 4, 'Conserve su ticket. Vuelva pronto.', 0, 1, 'C');

$pdf->Output('I', 'ticket_' . $orden['folio'] . '.pdf');
?>