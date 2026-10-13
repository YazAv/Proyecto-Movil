<?php
// api/caja/listar_historial_turnos.php
header('Content-Type: application/json');

// Ajusta la ruta de conexión según tu estructura
if (file_exists(__DIR__ . '/../../config/conexion.php')) {
    require_once __DIR__ . '/../../config/conexion.php';
} elseif (file_exists(__DIR__ . '/../conexion.php')) {
    require_once __DIR__ . '/../conexion.php';
}

try {
    // Consultamos los turnos que ya se encuentran cerrados
    $stmt = $conn->query("
        SELECT id_turno, 
               TO_CHAR(fecha_inicio, 'DD/MM/YYYY HH24:MI') as apertura, 
               COALESCE(TO_CHAR(fecha_cierre, 'DD/MM/YYYY HH24:MI'), '-') as cierre, 
               COALESCE(fondo_inicial, 0) as fondo, 
               COALESCE(efectivo_declarado, 0) as declarado, 
               COALESCE(diferencia, 0) as dif, 
               COALESCE(estado, 'cerrado') as estado
        FROM turnos_caja 
        WHERE LOWER(estado) = 'cerrado' 
        ORDER BY id_turno DESC 
        LIMIT 20
    ");
    
    $turnos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Aseguramos enviar siempre un array JSON válido
    echo json_encode($turnos ? $turnos : []);

} catch(Exception $e) {
    // Si hay error en la tabla o columnas, devolvemos un array vacío para no congelar la interfaz
    echo json_encode([]);
}
?>