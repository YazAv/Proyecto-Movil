<?php
header("Content-Type: application/json; charset=UTF-8");
require_once "../../config/conexion.php";

$data = json_decode(file_get_contents("php://input"), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = new Database();
        
        if (method_exists($db, 'getConexion')) { $conexion = $db->getConexion(); }
        elseif (method_exists($db, 'conectar')) { $conexion = $db->conectar(); }
        elseif (method_exists($db, 'getConnection')) { $conexion = $db->getConnection(); }
        elseif (property_exists($db, 'pdo')) { $conexion = $db->pdo; }
        elseif (property_exists($db, 'conexion')) { $conexion = $db->conexion; }
        else { $conexion = $db; }

        $idEmpleado = !empty($data['id_empleado']) ? intval($data['id_empleado']) : null;
        $accion = $data['accion'] ?? '';

        // ACCIÓN: ELIMINAR
        if ($accion === 'eliminar' && $idEmpleado) {
            $query = "DELETE FROM empleados WHERE id_empleado = :id";
            $stmt = $conexion->prepare($query);
            $stmt->execute([':id' => $idEmpleado]);
            echo json_encode(["status" => "success", "message" => "Empleado eliminado correctamente."]);
            exit;
        }

        // ACCIÓN: CAMBIAR ESTADO (ACTIVO / INACTIVO)
        if ($accion === 'cambiar_estado' && $idEmpleado) {
            $esActivo = ($data['estado'] === 'Activo');
            $query = "UPDATE empleados SET activo = :activo WHERE id_empleado = :id";
            $stmt = $conexion->prepare($query);
            $stmt->execute([':activo' => $esActivo ? 'true' : 'false', ':id' => $idEmpleado]);
            echo json_encode(["status" => "success", "message" => "Estado actualizado."]);
            exit;
        }

        // ACCIÓN: GUARDAR O EDITAR
        $nombre = trim($data['nombre']);
        $apellido = trim($data['apellido']);
        $puesto = trim($data['puesto']);
        $telefono = trim($data['telefono'] ?? '');
        $salario = floatval($data['salario_por_hora'] ?? 0.00);

        if ($idEmpleado) {
            // EDITAR
            $codigoQr = "EMP-" . $idEmpleado;
            $query = "UPDATE empleados SET nombre = :nom, apellido = :ape, puesto = :puesto, 
                                           telefono = :tel, salario_por_hora = :sal, codigo_qr = :qr 
                      WHERE id_empleado = :id";
            $stmt = $conexion->prepare($query);
            $stmt->execute([
                ':nom' => $nombre, 
                ':ape' => $apellido, 
                ':puesto' => $puesto,
                ':tel' => $telefono, 
                ':sal' => $salario, 
                ':qr' => $codigoQr, 
                ':id' => $idEmpleado
            ]);

            echo json_encode([
                "status" => "success", 
                "id_empleado" => $idEmpleado, 
                "codigo_qr" => $codigoQr, 
                "message" => "Empleado actualizado con éxito."
            ]);
        } else {
            // CREAR NUEVO
            $query = "INSERT INTO empleados (nombre, apellido, puesto, telefono, salario_por_hora, activo) 
                      VALUES (:nom, :ape, :puesto, :tel, :sal, true) RETURNING id_empleado";
            $stmt = $conexion->prepare($query);
            $stmt->execute([
                ':nom' => $nombre, 
                ':ape' => $apellido, 
                ':puesto' => $puesto, 
                ':tel' => $telefono, 
                ':sal' => $salario
            ]);
            
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            $nuevoId = $res ? $res['id_empleado'] : $conexion->lastInsertId();
            $codigoQr = "EMP-" . $nuevoId;

            // Actualizar el código QR asignado
            $queryUpdate = "UPDATE empleados SET codigo_qr = :qr WHERE id_empleado = :id";
            $stmtUp = $conexion->prepare($queryUpdate);
            $stmtUp->execute([':qr' => $codigoQr, ':id' => $nuevoId]);

            echo json_encode([
                "status" => "success", 
                "id_empleado" => $nuevoId, 
                "codigo_qr" => $codigoQr, 
                "message" => "Empleado registrado con éxito."
            ]);
        }

    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => "Error BD: " . $e->getMessage()]);
    }
}