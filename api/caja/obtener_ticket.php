<?php
require_once '../../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Método no permitido"]);
    exit();
}

if (empty($_GET['orden_id'])) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "El parámetro 'orden_id' es obligatorio"]);
    exit();
}

$orden_id = (int)$_GET['orden_id'];

$db = new Database();
$conn = $db->getConnection();

try {
    // 1. Datos generales de la orden, mesa y mesero
    $stmtOrden = $conn->prepare("
        SELECT 
            o.id AS orden_id,
            COALESCE(o.folio, CONCAT('ORD-', LPAD(o.id, 5, '0'))) AS folio,
            COALESCE(o.creado_en, NOW()) AS fecha_hora,
            o.total,
            o.estado,
            m.numero AS mesa_numero,
            u.nombre AS mesero_nombre
        FROM ordenes o
        INNER JOIN mesas m ON o.mesa_id = m.id
        LEFT JOIN usuarios u ON o.mesero_id = u.id
        WHERE o.id = ?
    ");
    $stmtOrden->execute([$orden_id]);
    $orden = $stmtOrden->fetch(PDO::FETCH_ASSOC);

    if (!$orden) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Orden no encontrada"]);
        exit();
    }

    // 2. Artículos consumidos
    $stmtDetalles = $conn->prepare("
        SELECT 
            p.nombre AS producto,
            od.cantidad,
            od.precio_unitario,
            od.subtotal
        FROM orden_detalles od
        INNER JOIN productos p ON od.producto_id = p.id
       WHERE od.orden_id = ? AND od.estado_kds != 'cancelado'
    ");
    $stmtDetalles->execute([$orden_id]);
    $articulos = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

    // 3. Historial de pagos
    $stmtPagos = $conn->prepare("
        SELECT 
            metodo_pago,
            monto,
            monto_recibido,
            cambio,
            referencia,
            fecha_pago
        FROM pagos
        WHERE orden_id = ?
    ");
    $stmtPagos->execute([$orden_id]);
    $pagos = $stmtPagos->fetchAll(PDO::FETCH_ASSOC);

    // 4. Cálculos fiscales e importes
    $total = (float)$orden['total'];
    $subtotal_sin_iva = round($total / 1.16, 2);
    $iva = round($total - $subtotal_sin_iva, 2);

    $total_pagado = 0.0;
    $total_cambio = 0.0;
    $pagos_formateados = [];

    foreach ($pagos as $p) {
        $monto = (float)$p['monto'];
        $recibido = (float)$p['monto_recibido'];
        $cambio = (float)$p['cambio'];

        $total_pagado += $monto;
        $total_cambio += $cambio;

        $pagos_formateados[] = [
            "metodo_pago" => $p['metodo_pago'],
            "monto" => $monto,
            "monto_recibido" => $recibido,
            "cambio" => $cambio,
            "referencia" => $p['referencia'],
            "fecha_pago" => $p['fecha_pago']
        ];
    }

    // 5. Payload final del ticket
    $ticket = [
        "encabezado" => [
            "nombre_negocio" => "RESTAURANTE SAZÓN TRACK",
            "rfc" => "XAXX010101000",
            "direccion" => "Av. Principal #123, Centro",
            "telefono" => "33 0000 0000",
            "mensaje_bienvenida" => "¡Gracias por su preferencia!"
        ],
        "datos_venta" => [
            "orden_id" => (int)$orden['orden_id'],
            "folio" => $orden['folio'],
            "fecha_hora" => $orden['fecha_hora'],
            "mesa" => "Mesa " . $orden['mesa_numero'],
            "mesero" => $orden['mesero_nombre'] ?? "Caja General",
            "estado_orden" => $orden['estado']
        ],
        "productos" => array_map(function($item) {
            return [
                "descripcion" => $item['producto'],
                "cantidad" => (int)$item['cantidad'],
                "precio_unitario" => (float)$item['precio_unitario'],
                "importe" => (float)$item['subtotal']
            ];
        }, $articulos),
        "totales" => [
            "subtotal" => $subtotal_sin_iva,
            "iva" => $iva,
            "total" => $total,
            "pagado" => round($total_pagado, 2),
            "cambio" => round($total_cambio, 2)
        ],
        "metodos_pago" => $pagos_formateados,
        "pie_ticket" => [
            "leyenda" => "Este comprobante no es deducible fiscalmente",
            "solicitud_factura" => "Facture en: https://sazontrack.local/facturacion"
        ]
    ];

    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "ticket" => $ticket
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error al generar ticket: " . $e->getMessage()]);
}