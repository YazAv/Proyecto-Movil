<?php
error_reporting(0);
ini_set('display_errors', 0);
date_default_timezone_set('America/Mexico_City');
header("Content-Type: application/json; charset=UTF-8");

require_once "../../config/conexion.php";

$input = file_get_contents("php://input");
$data = json_decode($input, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = new Database();
        if (method_exists($db, 'getConexion')) { $conexion = $db->getConexion(); }
        elseif (method_exists($db, 'conectar')) { $conexion = $db->conectar(); }
        elseif (method_exists($db, 'getConnection')) { $conexion = $db->getConnection(); }
        elseif (property_exists($db, 'pdo')) { $conexion = $db->pdo; }
        elseif (property_exists($db, 'conexion')) { $conexion = $db->conexion; }
        else { $conexion = $db; }

        $codigoQr = trim($data['codigo_qr'] ?? '');
        $tipo = trim($data['tipo'] ?? 'ENTRADA');

        if (empty($codigoQr)) {
            echo json_encode(["status" => "error", "message" => "Código QR vacío."]);
            exit;
        }

        // Extraer ID (EMP-7 -> 7)
        $idEmpleado = str_replace('EMP-', '', $codigoQr);

        // Buscar empleado en la tabla empleados
        $stmt = $conexion->prepare("SELECT id_empleado, nombre, apellido, puesto, activo FROM empleados WHERE id_empleado = :id OR codigo_qr = :qr");
        $stmt->execute([':id' => is_numeric($idEmpleado) ? intval($idEmpleado) : 0, ':qr' => $codigoQr]);
        $empleado = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$empleado) {
            echo json_encode(["status" => "error", "message" => "Empleado no registrado ($codigoQr)."]);
            exit;
        }

        if (!$empleado['activo']) {
            echo json_encode(["status" => "error", "message" => "El empleado está INACTIVO."]);
            exit;
        }

        $horaActual = date("H:i:s");
        $fechaActual = date("Y-m-d");

        // ====================================================================
        // BLOQUEO ANTI-SPAM: Evitar doble marcaje en menos de 3 minutos
        // ====================================================================
        $stmtLast = $conexion->prepare("SELECT fecha, hora, tipo FROM asistencias WHERE id_empleado = :id ORDER BY id DESC LIMIT 1");
        $stmtLast->execute([':id' => $empleado['id_empleado']]);
        $ultimoRegistro = $stmtLast->fetch(PDO::FETCH_ASSOC);

        if ($ultimoRegistro) {
            $tiempoUltimo = strtotime($ultimoRegistro['fecha'] . ' ' . $ultimoRegistro['hora']);
            $tiempoActual = strtotime("$fechaActual $horaActual");
            $diferenciaSegundos = $tiempoActual - $tiempoUltimo;

            // Si la diferencia es menor a 180 segundos (3 minutos)
            if ($diferenciaSegundos < 180) {
                $minutosRestantes = ceil((180 - $diferenciaSegundos) / 60);
                echo json_encode([
                    "status" => "warning", 
                    "message" => "¡Marcaje muy rápido! Espera $minutosRestantes min para volver a escanear."
                ]);
                exit; // Detiene la ejecución, NO guarda en la base de datos
            }
        }
        // ====================================================================

        // Asegurar columnas y quitar restricciones antiguas
        try { $conexion->exec("ALTER TABLE asistencias DROP CONSTRAINT IF EXISTS fk_usuario_asistencia"); } catch (Exception $ex) {}
        try { $conexion->exec("ALTER TABLE asistencias DROP CONSTRAINT IF EXISTS asistencias_usuario_id_fkey"); } catch (Exception $ex) {}
        try { $conexion->exec("ALTER TABLE asistencias ALTER COLUMN usuario_id DROP NOT NULL"); } catch (Exception $ex) {}

        $checkCol = $conexion->query("SELECT column_name FROM information_schema.columns WHERE table_name='asistencias' AND column_name='id_empleado'");
        if (!$checkCol || $checkCol->rowCount() == 0) {
            $conexion->exec("ALTER TABLE asistencias ADD COLUMN id_empleado INT");
        }

        // Insertar el nuevo marcaje
        $stmtIns = $conexion->prepare("INSERT INTO asistencias (id_empleado, usuario_id, tipo, fecha, hora) VALUES (:id, NULL, :tipo, :fecha, :hora)");
        $stmtIns->execute([
            ':id'    => $empleado['id_empleado'],
            ':tipo'  => $tipo,
            ':fecha' => $fechaActual,
            ':hora'  => $horaActual
        ]);

        echo json_encode([
            "status"  => "success",
            "message" => "Marcaje registrado correctamente.",
            "nombre"  => $empleado['nombre'] . ' ' . $empleado['apellido'],
            "hora"    => $horaActual
        ]);

    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
    }
}