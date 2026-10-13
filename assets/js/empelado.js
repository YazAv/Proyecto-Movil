document.addEventListener('DOMContentLoaded', () => {
    cargarEmpleados();

    const form = document.getElementById('formEmpleado');
    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const btnGuardar = document.getElementById('btnGuardar');
            btnGuardar.disabled = true;
            btnGuardar.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...';

            const empleadoData = {
                nombre: document.getElementById('nombre').value.trim(),
                apellido: document.getElementById('apellido').value.trim(),
                id_rol: parseInt(document.getElementById('id_rol').value),
                pin_acceso: document.getElementById('pin_acceso').value.trim(),
                telefono: document.getElementById('telefono').value.trim(),
                salario_por_hora: parseFloat(document.getElementById('salario_por_hora').value) || 0.00
            };

            try {
                const res = await fetch('api/empleados/index.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(empleadoData)
                });

                const data = await res.json();

                if (data.status === 'success') {
                    mostrarAlerta('Empleado registrado con éxito.', 'success');
                    
                    // Cerrar modal y resetear formulario
                    const modalEl = document.getElementById('modalEmpleado');
                    const modal = bootstrap.Modal.getInstance(modalEl);
                    if (modal) modal.hide();
                    
                    form.reset();
                    cargarEmpleados(); // Recargar la tabla automáticamente
                } else {
                    mostrarAlerta('Error: ' + (data.message || 'No se pudo guardar.'), 'danger');
                }
            } catch (error) {
                console.error('Error al guardar:', error);
                mostrarAlerta('Error de conexión con el servidor.', 'danger');
            } finally {
                btnGuardar.disabled = false;
                btnGuardar.innerHTML = 'Guardar Empleado';
            }
        });
    }
});

// Carga de empleados desde listar.php
async function cargarEmpleados() {
    const tbody = document.getElementById('tablaEmpleados');
    if (!tbody) return;

    try {
        const res = await fetch('api/empleados/listar.php');
        const result = await res.json();

        if (result.status === 'success' && Array.isArray(result.data)) {
            tbody.innerHTML = '';

            if (result.data.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No hay empleados registrados en el sistema.</td>
                    </tr>`;
                return;
            }

            result.data.forEach(emp => {
                tbody.innerHTML += `
                    <tr>
                        <td>#${emp.id_empleado}</td>
                        <td class="fw-bold">${emp.nombre} ${emp.apellido}</td>
                        <td><span class="badge badge-role">${emp.nombre_rol}</span></td>
                        <td>${emp.telefono || 'N/A'}</td>
                        <td><code>****</code></td>
                        <td><span class="badge bg-success">${emp.estado || 'Activo'}</span></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-dark" onclick="mostrarQR('${emp.nombre} ${emp.apellido}', '${emp.nombre_rol}', '${emp.codigo_qr}')">
                                <i class="fa-solid fa-qrcode me-1"></i> Ver QR
                            </button>
                        </td>
                    </tr>
                `;
            });
        } else {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center text-warning py-4">No se encontraron datos de empleados.</td>
                </tr>`;
        }
    } catch (error) {
        console.error('Error al cargar empleados:', error);
        tbody.innerHTML = `
            <tr>
                <td colspan="7" class="text-center text-danger py-4">Error al conectar con la base de datos.</td>
            </tr>`;
    }
}

// Mostrar credencial QR
function mostrarQR(nombre, rol, codigoQR) {
    document.getElementById('qrNombreEmpleado').innerText = nombre;
    document.getElementById('qrRolEmpleado').innerText = rol;
    document.getElementById('qrCodigoTexto').innerText = codigoQR;

    const qrContainer = document.getElementById('qrcode');
    qrContainer.innerHTML = '';

    new QRCode(qrContainer, {
        text: codigoQR,
        width: 160,
        height: 160
    });

    new bootstrap.Modal(document.getElementById('modalQR')).show();
}

// Función auxiliar para alertas
function mostrarAlerta(mensaje, tipo) {
    const alertContainer = document.getElementById('alertContainer');
    if (!alertContainer) return;

    alertContainer.innerHTML = `
        <div class="alert alert-${tipo} alert-dismissible fade show shadow-sm" role="alert">
            <i class="fa-solid ${tipo === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'} me-2"></i>${mensaje}
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" onclick="this.parentElement.remove()"></button>
        </div>
    `;

    setTimeout(() => {
        const alert = alertContainer.querySelector('.alert');
        if (alert) alert.remove();
    }, 4000);
}