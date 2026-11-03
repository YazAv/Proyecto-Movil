const express = require('express');
const http = require('http');
const { Server } = require('socket.io');

const app = express();
const server = http.createServer(app);
const io = new Server(server, {
    cors: { origin: "*" }
});

io.on('connection', (socket) => {
    console.log('🟢 Klien a nakonekta iti WebSocket:', socket.id);

    // Dumngeg no mangipatulod ti comanda ti mobile app
    socket.on('nueva_comanda', (data) => {
        console.log('🔔 Comanda a naawat:', data);
        io.emit('actualizar_mesas_caja', data);
        io.emit('renderizar_kds', data);
    });

    // Dumngeg no nakasagana ti makan idiay kusina
    socket.on('platillo_preparado', (data) => {
        console.log('✅ Nakasagana ti makan:', data);
        io.emit('notificar_mesero', data);
    });

    socket.on('disconnect', () => {
        console.log('🔴 Nataktak ti koneksion ti klien');
    });
});

server.listen(3000, () => {
    console.log('🚀 Agturturong ti Servidor WebSocket iti http://localhost:3000');
});