<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SazónTrack - Monitor de Cocina (KDS)</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body {
            background-color: #121212;
            color: #ffffff;
            font-family: 'Plus Jakarta Sans', sans-serif;
            overflow-x: hidden;
        }

        .kds-header {
            background-color: #1e1e1e;
            border-bottom: 2px solid #333;
            padding: 15px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .ticket-card {
            background-color: #1e1e1e;
            border-radius: 12px;
            border-top: 5px solid #0d6efd; /* Color normal */
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            display: flex;
            flex-direction: column;
            height: 100%;
            transition: all 0.3s;
        }

        /* Colores por tiempo de espera */
        .ticket-card.warning { border-top-color: #ffc107; } /* +10 min */
        .ticket-card.danger { border-top-color: #dc3545; background-color: #2c1114; } /* +20 min */

        .ticket-header {
            padding: 12px 15px;
            border-bottom: 1px solid #333;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 800;
        }

        .ticket-body {
            padding: 0;
            flex-grow: 1;
        }

        .kds-item {
            padding: 12px 15px;
            border-bottom: 1px solid #2a2a2a;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .kds-item:hover {
            background-color: #2a2a2a;
        }

        .item-qty {
            background-color: #333;
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 800;
            margin-right: 12px;
            font-size: 1.1rem;
        }

        .item-name {
            font-size: 1.05rem;
            font-weight: 600;
        }

        .item-notes {
            font-size: 0.85rem;
            color: #ffc107;
            margin-top: 4px;
            font-weight: 600;
        }

        .btn-ready {
            background-color: #198754;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 5px 12px;
            font-weight: bold;
        }
        .btn-ready:hover { background-color: #146c43; }

    </style>
</head>
<body>

    <div class="kds-header">
        <div class="d-flex align-items-center gap-3">
            <a href="index.html" class="btn btn-outline-light btn-sm rounded-pill"><i class="fa-solid fa-arrow-left"></i> Salir</a>
            <h4 class="m-0 fw-800"><i class="fa-solid fa-fire-burner me-2 text-warning"></i>KDS - Monitor de Cocina</h4>
        </div>
        <div class="d-flex align-items-center gap-4">
            <div id="relojCocina" class="fs-4 fw-bold font-monospace text-info">00:00:00</div>
            <span class="badge bg-success fs-6"><i class="fa-solid fa-satellite-dish me-1"></i>En línea</span>
        </div>
    </div>

    <div class="container-fluid py-4">
        <!-- Contenedor Grid de Comandas -->
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 row-cols-xl-4 g-4" id="contenedorComandas">
            <!-- Los tickets se inyectan aquí con JS -->
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            iniciarReloj();
            cargarComandasKDS();
            // Refrescar las comandas automáticamente cada 5 segundos
            setInterval(cargarComandasKDS, 5000);
        });

        function iniciarReloj() {
            setInterval(() => {
                const ahora = new Date();
                document.getElementById('relojCocina').innerText = ahora.toLocaleTimeString('es-MX', { hour12: false });
                
                // Actualizar tiempos de espera en los tickets ya renderizados
                document.querySelectorAll('.tiempo-espera').forEach(el => {
                    const tiempoCreacion = new Date(el.dataset.fecha).getTime();
                    const minTranscurridos = Math.floor((ahora.getTime() - tiempoCreacion) / 60000);
                    el.innerText = `${minTranscurridos} min`;

                    // Cambiar colores si tardan mucho (Wansoft style)
                    const tarjeta = el.closest('.ticket-card');
                    if (minTranscurridos >= 20) {
                        tarjeta.classList.add('danger');
                        tarjeta.classList.remove('warning');
                    } else if (minTranscurridos >= 10) {
                        tarjeta.classList.add('warning');
                        tarjeta.classList.remove('danger');
                    }
                });
            }, 1000);
        }

        async function cargarComandasKDS() {
            try {
                const resp = await fetch('api/comandas/listar_pendientes_cocina.php');
                const comandas = await resp.json();
                renderizarComandas(comandas);
            } catch (error) {
                console.error("Error al obtener comandas:", error);
            }
        }

        function renderizarComandas(comandas) {
            const contenedor = document.getElementById('contenedorComandas');
            contenedor.innerHTML = '';

            if (comandas.length === 0) {
                contenedor.innerHTML = `
                    <div class="col-12 text-center py-5 text-secondary">
                        <i class="fa-solid fa-mug-hot fa-3x mb-3"></i>
                        <h5>No hay comandas pendientes en cocina.</h5>
                    </div>`;
                return;
            }

            comandas.forEach(orden => {
                const fechaOrden = new Date(orden.fecha_orden.replace(/-/g, '/'));
                
                let htmlDetalles = '';
                orden.detalles.forEach(item => {
                    let notasHtml = item.notas ? `<div class="item-notes"><i class="fa-solid fa-circle-exclamation me-1"></i>${item.notas}</div>` : '';
                    
                    htmlDetalles += `
                        <div class="kds-item" onclick="marcarPreparado(${item.id_detalle})">
                            <div class="d-flex">
                                <div class="item-qty">${item.cantidad}</div>
                                <div>
                                    <div class="item-name">${item.producto}</div>
                                    ${notasHtml}
                                </div>
                            </div>
                            <button class="btn-ready"><i class="fa-solid fa-check"></i></button>
                        </div>
                    `;
                });

                contenedor.insertAdjacentHTML('beforeend', `
                    <div class="col">
                        <div class="ticket-card">
                            <div class="ticket-header">
                                <span class="fs-5">Mesa: <span class="text-warning">${orden.numero_mesa}</span></span>
                                <span class="tiempo-espera text-light" data-fecha="${orden.fecha_orden}">0 min</span>
                            </div>
                            <div class="ticket-body">
                                ${htmlDetalles}
                            </div>
                        </div>
                    </div>
                `);
            });
        }

        async function marcarPreparado(idDetalle) {
            try {
                await fetch('api/comandas/actualizar_estado_kds.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id_detalle: idDetalle, estado: 'PREPARADO' })
                });
                // Recargar pantalla de inmediato para desaparecer el platillo
                cargarComandasKDS();
            } catch (error) {
                alert("No se pudo actualizar el estado de la comanda.");
            }
        }
    </script>
</body>
</html>