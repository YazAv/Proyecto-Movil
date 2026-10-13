<?php
// api/caja/generar_ticket.php

// 1. Conexión a la base de datos PostgreSQL
if (file_exists(__DIR__ . '/../../config/conexion.php')) {
    require_once __DIR__ . '/../../config/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
} else {
    die("Error: No se encontró el archivo de conexión a la base de datos.");
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

// 3. Capturar el ID de la URL (ej. ?id=39)
$orden_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($orden_id <= 0) {
    die("Error: No se proporcionó un ID de venta válido para imprimir.");
}

// 4. Consultar los datos generales de la orden
try {
    $stmtOrden = $conn->prepare("
        SELECT o.folio, o.total, 
               TO_CHAR(o.creado_en, 'DD/MM/YYYY') AS fecha_venta,
               TO_CHAR(o.creado_en, 'HH24:MI') AS hora,
               COALESCE(p.metodo_pago, 'Efectivo') AS metodo_pago,
               COALESCE(u.nombre, 'Cajero Principal') AS cajero
        FROM ordenes o 
        LEFT JOIN pagos p ON o.id_orden = p.id_orden
        LEFT JOIN usuarios u ON o.id_usuario = u.id
        WHERE o.id_orden = ?
        LIMIT 1
    ");
    $stmtOrden->execute([$orden_id]);
    $orden = $stmtOrden->fetch(PDO::FETCH_ASSOC);

    if (!$orden) {
        die("Error: No se encontró la orden número {$orden_id} en la base de datos.");
    }

    // 5. Consultar los detalles (platillos) de esta orden
    $articulos = [];
    try {
        // Intento 1: Asumiendo que la PK de productos es id_producto
        $stmtDetalles = $conn->prepare("
            SELECT pr.nombre AS producto, od.cantidad, od.precio_unitario, od.subtotal
            FROM orden_detalles od
            JOIN productos pr ON od.producto_id = pr.id_producto
            WHERE od.orden_id = ?
        ");
        $stmtDetalles->execute([$orden_id]);
        $articulos = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Intento 2: Asumiendo que la PK de productos es simplemente id
        $stmtDetalles = $conn->prepare("
            SELECT pr.nombre AS producto, od.cantidad, od.precio_unitario, od.subtotal
            FROM orden_detalles od
            JOIN productos pr ON od.producto_id = pr.id
            WHERE od.orden_id = ?
        ");
        $stmtDetalles->execute([$orden_id]);
        $articulos = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {
    die("Error en la base de datos al buscar el ticket: " . $e->getMessage());
}

$total = floatval($orden['total']);
$iva = $total - ($total / 1.16); // Desglose de IVA
$baseImponible = $total - $iva;

// ==================== GENERACIÓN DEL TICKET PDF ====================
$pdf = new FPDF('P', 'mm', array(80, 200));
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

// Datos del ticket
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(18, 5, mb_convert_encoding('Núm ticket: ', 'ISO-8859-1', 'UTF-8'), 0, 0, 'L');
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(52, 5, $orden['folio'], 0, 1, 'L');

$pdf->Cell(35, 4, 'Fecha: ' . ($orden['fecha_venta'] ?? date('d/m/Y')), 0, 0, 'L');
$pdf->Cell(35, 4, 'Hora: ' . ($orden['hora'] ?? date('H:i')), 0, 1, 'R');

$pdf->Cell(70, 4, mb_convert_encoding('Cajero: ' . $orden['cajero'], 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');
$pdf->Cell(70, 4, mb_convert_encoding('Método de Pago: ' . $orden['metodo_pago'], 'ISO-8859-1', 'UTF-8'), 0, 1, 'L');

$pdf->Cell(70, 2, '-------------------------------------------------------------------------', 0, 1, 'L');

// Encabezados de tabla
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(8, 4, 'Cant', 0, 0, 'L');
$pdf->Cell(34, 4, mb_convert_encoding('Descripción', 'ISO-8859-1', 'UTF-8'), 0, 0, 'L');
$pdf->Cell(14, 4, 'P.U.', 0, 0, 'C');
$pdf->Cell(14, 4, 'Importe', 0, 1, 'R');

$pdf->Cell(70, 2, '-------------------------------------------------------------------------', 0, 1, 'L');

// Detalle de productos
$totalProductos = 0;
$pdf->SetFont('Arial', '', 7);

if (!empty($articulos)) {
    foreach ($articulos as $item) {
        $cantidad = intval($item['cantidad']);
        $precio = floatval($item['precio_unitario']);
        $importe = floatval($item['subtotal']);
        $totalProductos += $cantidad;

        $pdf->Cell(8, 4, $cantidad, 0, 0, 'L');
        
        $yInicio = $pdf->GetY();
        $pdf->MultiCell(34, 4, mb_convert_encoding($item['producto'], 'ISO-8859-1', 'UTF-8'), 0, 'L');
        $yFin = $pdf->GetY();
        
        $pdf->SetXY(47, $yInicio);
        $pdf->Cell(14, 4, MONEDA . number_format($precio, 2), 0, 0, 'C');
        $pdf->Cell(14, 4, MONEDA . number_format($importe, 2), 0, 1, 'R');
        
        if ($yFin > $pdf->GetY()) {
            $pdf->SetY($yFin);
        }
    }
} else {
    $pdf->Cell(70, 5, 'Sin detalles de platillos', 0, 1, 'C');
}

$pdf->Cell(70, 2, '-------------------------------------------------------------------------', 0, 1, 'L');

// Totales
$pdf->SetFont('Arial', '', 8);
$pdf->Cell(40, 4, mb_convert_encoding('Número de artículos:', 'ISO-8859-1', 'UTF-8'), 0, 0, 'L');
$pdf->Cell(30, 4, $totalProductos, 0, 1, 'R');

$pdf->Cell(40, 4, 'Subtotal (Base):', 0, 0, 'L');
$pdf->Cell(30, 4, MONEDA . number_format($baseImponible, 2), 0, 1, 'R');

$pdf->Cell(40, 4, 'IVA (16%):', 0, 0, 'L');
$pdf->Cell(30, 4, MONEDA . number_format($iva, 2), 0, 1, 'R');

$pdf->Ln(1);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(30, 5, 'TOTAL:', 0, 0, 'L');
$pdf->Cell(40, 5, MONEDA . number_format($total, 2), 0, 1, 'R');

$pdf->Ln(2);
$pdf->Cell(70, 2, '-------------------------------------------------------------------------', 0, 1, 'L');

// Pie de página
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell(70, 5, mb_convert_encoding('¡GRACIAS POR SU PREFERENCIA!', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
$pdf->SetFont('Arial', '', 7);
$pdf->Cell(70, 4, 'Conserve su ticket. Vuelva pronto.', 0, 1, 'C');

// Salida al navegador
$pdf->Output('I', 'ticket_venta_' . $orden_id . '.pdf');
?>