<?php 

// Incluir la conexión centralizada a la base de datos PostgreSQL
require_once '../../config/conexion.php';

header("Content-Type: application/json; charset=UTF-8");

// Obtener los datos JSON enviados en el cuerpo de la petición
$data = json_decode(file_get_contents("php://input"), true);

// Validación de parámetros obligatorios
if (!isset($data['orden_id']) || empty($data['orden_id'])) {
    http_response_code(400);
    echo json_encode([
        "status" => "error", 
        "message" => "El ID de la orden es obligatorio para descontar inventario."
    ]);
    exit();
}

$orden_id = intval($data['orden_id']);

try {
    // Iniciar transacción SQL para garantizar integridad (si falla un descuento, todo se revierte)
    $conn->beginTransaction();

    // --------------------------------------------------------------------------
    // PASO 1: Obtener los detalles de los productos consumidos en la orden
    // --------------------------------------------------------------------------
    // Nota: Consultamos los productos asociados a la orden
    $stmtDetalles = $conn->prepare("
        SELECT id_producto, cantidad 
        FROM orden_detalles 
        WHERE id_orden = :orden_id
    ");
    $stmtDetalles->execute([':orden_id' => $orden_id]);
    $detallesOrden = $stmtDetalles->fetchAll();

    if (empty($detallesOrden)) {
        // Si no hay detalles específicos con id_producto en esa tabla, buscamos en la consulta estándar de comandas
        $stmtDetallesAlt = $conn->prepare("
            SELECT id_producto, cantidad 
            FROM comandas_detalles 
            WHERE orden_id = :orden_id
        ");
        $stmtDetallesAlt->execute([':orden_id' => $orden_id]);
        $detallesOrden = $stmtDetallesAlt->fetchAll();
    }

    $insumosAgotados = []; // Arreglo para acumular productos que se queden sin stock

    // --------------------------------------------------------------------------
    // PASO 2: Recorrer cada producto vendido y procesar su receta
    // --------------------------------------------------------------------------
    foreach ($detallesOrden as $item) {
        $id_producto = intval($item['id_producto']);
        $cant_producto = floatval($item['cantidad']); // Ej. 2 Hamburguesas

        // Obtener la receta del producto desde la tabla 'recetas'
        $stmtReceta = $conn->prepare("
            SELECT id_insumo, cantidad AS cantidad_receta
            FROM recetas
            WHERE id_producto = :id_producto OR producto_id = :id_producto
        ");
        $stmtReceta->execute([':id_producto' => $id_producto]);
        $ingredientes = $stmtReceta->fetchAll();

        // ----------------------------------------------------------------------
        // PASO 3: Descontar cada insumo proporcionalmente a la cantidad vendida
        // ----------------------------------------------------------------------
        foreach ($ingredientes as $ing) {
            $id_insumo = intval($ing['id_insumo']);
            $cant_unitaria = floatval($ing['cantidad_receta']); // Cantidad por 1 porción
            
            // Cantidad total a descontar = (Cantidad en Receta) x (Platillos Vendidos)
            $cant_total_descontar = $cant_unitaria * $cant_producto;

            // A) Actualizar el stock_actual en la tabla 'insumos'
            $stmtUpdateStock = $conn->prepare("
                UPDATE insumos 
                SET stock_actual = stock_actual - :descuento 
                WHERE id_insumo = :id_insumo
                RETURNING stock_actual, stock_minimo, nombre
            ");
            $stmtUpdateStock->execute([
                ':descuento' => $cant_total_descontar,
                ':id_insumo' => $id_insumo
            ]);
            $insumoActualizado = $stmtUpdateStock->fetch();

            // B) Registrar el movimiento en la tabla 'movimientos_inventario' para auditoría
            $stmtMov = $conn->prepare("
                INSERT INTO movimientos_inventario 
                (id_insumo, tipo, cantidad, tipo_movimiento, referencia_id, notas, fecha)
                VALUES 
                (:id_insumo, 'salida_comanda', :cantidad, 'salida_comanda', :referencia, :notas, NOW())
            ");
            $stmtMov->execute([
                ':id_insumo'   => $id_insumo,
                ':cantidad'    => $cant_total_descontar,
                ':referencia'  => $orden_id,
                ':notas'       => "Deducción automática por venta de orden #{$orden_id}"
            ]);

            // C) CONTROL DE AGOTAMIENTO (StockWatch): Verificar si el insumo cayó a cero o menos
            if ($insumoActualizado && floatval($insumoActualizado['stock_actual']) <= 0) {
                // Deshabilitar automáticamente los productos que usen este insumo agotado
                $stmtDeshabilitar = $conn->prepare("
                    UPDATE productos 
                    SET disponible = 0 
                    WHERE id_producto IN (
                        SELECT DISTINCT id_producto 
                        FROM recetas 
                        WHERE id_insumo = :id_insumo
                    )
                ");
                $stmtDeshabilitar->execute([':id_insumo' => $id_insumo]);

                $insumosAgotados[] = $insumoActualizado['nombre'];
            }
        }
    }

    // Confirmar todos los cambios en la base de datos
    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Inventario descontado correctamente.",
        "insumos_agotados" => $insumosAgotados
    ]);

} catch (Exception $e) {
    // Si ocurre un error, revertir todos los cambios realizados en esta transacción
    $conn->rollBack();
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Error al descontar inventario: " . $e->getMessage()
    ]);
}