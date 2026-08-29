<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prueba de conexión</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            margin-top: 100px;
            background: #f4f4f4;
        }

        #estado {
            font-size: 30px;
            font-weight: bold;
        }

        .online {
            color: green;
        }

        .offline {
            color: red;
        }
    </style>
</head>
<body>

    <h1>Estado de Internet</h1>

    <p id="estado">Comprobando...</p>

    <script>
        function comprobarConexion() {
            const estado = document.getElementById("estado");

            if (navigator.onLine) {
                estado.textContent = "🟢 Tienes conexión a Internet";
                estado.className = "online";
            } else {
                estado.textContent = "🔴 No tienes conexión a Internet";
                estado.className = "offline";
            }
        }

        // Comprobar al abrir la página
        comprobarConexion();

        // Detectar cuando se conecta
        window.addEventListener("online", comprobarConexion);

        // Detectar cuando se desconecta
        window.addEventListener("offline", comprobarConexion);
    </script>

</body>
</html>
