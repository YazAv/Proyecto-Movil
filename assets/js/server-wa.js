const { default: makeWASocket, useMultiFileAuthState, DisconnectReason, Browsers } = require('@whiskeysockets/baileys');
const express = require('express');
const cors = require('cors');
const qrcode = require('qrcode-terminal');

const app = express();
app.use(express.json({ limit: '15mb' }));
app.use(cors());

let sock = null;
let isConnected = false;

async function connectToWhatsApp() {
    const { state, saveCreds } = await useMultiFileAuthState('baileys_auth_info');

    sock = makeWASocket({
        auth: state,
        printQRInTerminal: false,
        browser: Browsers.windows('Chrome'),
        connectTimeoutMs: 60000,
        defaultQueryTimeoutMs: 60000,
        keepAliveIntervalMs: 10000
    });

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            isConnected = false;
            console.clear();
            console.log('\n======================================================');
            console.log('📌 ESCANEA ESTE CÓDIGO QR PARA VINCULAR EL BOT A WHATSAPP:');
            console.log('======================================================\n');
            qrcode.generate(qr, { small: true });
        }

        if (connection === 'close') {
            isConnected = false;
            const statusCode = lastDisconnect?.error?.output?.statusCode;
            const shouldReconnect = statusCode !== DisconnectReason.loggedOut;
            console.log(`Conexión cerrada (Código: ${statusCode}). Reintentando conexión...`);
            if (shouldReconnect) {
                setTimeout(connectToWhatsApp, 3000);
            }
        } else if (connection === 'open') {
            isConnected = true;
            console.log('\n======================================================');
            console.log('✅ ¡Bot de WhatsApp para SazónTrack POS CONECTADO CON ÉXITO!');
            console.log('======================================================\n');
        }
    });
}

connectToWhatsApp();

app.post('/api/whatsapp/enviar-qr', async (req, res) => {
    const { telefono, mensaje, qrBase64, nombre } = req.body;

    if (!sock || !isConnected) {
        return res.status(500).json({ 
            status: 'error', 
            message: 'El bot de WhatsApp no está listo. Escanea el código QR en la terminal de VS Code.' 
        });
    }

    try {
        let numLimpio = telefono.replace(/\D/g, '');
        if (numLimpio.length === 10) numLimpio = '521' + numLimpio; // Formato internacional México
        const jid = `${numLimpio}@s.whatsapp.net`;

        if (qrBase64) {
            // Convertir Base64 a buffer de imagen
            const base64Data = qrBase64.replace(/^data:image\/png;base64,/, "");
            const buffer = Buffer.from(base64Data, 'base64');

            // Enviar la imagen con el mensaje completo adjunto en un solo envío
            await sock.sendMessage(jid, { 
                image: buffer, 
                caption: mensaje 
            });
        } else {
            // Si no hay imagen, enviar solo texto
            await sock.sendMessage(jid, { text: mensaje });
        }

        res.json({ status: 'success', message: 'Credencial e imagen enviadas en un solo paquete.' });
    } catch (error) {
        console.error('Error durante el envío por WhatsApp:', error);
        res.status(500).json({ status: 'error', message: 'Error de envío: ' + error.message });
    }
});

app.listen(3001, () => {
    console.log('🚀 API WhatsApp corriendo en http://localhost:3001');
});