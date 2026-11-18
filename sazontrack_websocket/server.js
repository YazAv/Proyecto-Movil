const express = require('express');
const http = require('http');
const { Server } = require('socket.io');
const cors = require('cors');

const app = express();
app.use(cors());
app.use(express.json());

const server = http.createServer(app);
const io = new Server(server, {
    cors: { origin: "*", methods: ["GET", "POST"] }
});

// Ruta HTTP para que PHP (crear_comanda.php) dispare alertas en tiempo real
app.post('/emitir', (req, res) => {
    const { evento, data } = req.body;
    io.emit(evento || 'renderizar_kds', data || {});
    res.json({ ok: true });
});

io.on('connection', (socket) => {
    console.log('🟢 Pantalla/Dispositivo conectado:', socket.id);

    socket.on('nueva_comanda', (data) => {
        io.emit('renderizar_kds', data);
        io.emit('actualizar_mesas_caja', data);
    });

    socket.on('platillo_preparado', (data) => {
        io.emit('notificar_mesero', data);
    });
});

const PORT = 3000;
server.listen(PORT, '0.0.0.0', () => {
    console.log(`🚀 WebSocket SazónTrack activo en puerto ${PORT}`);
});