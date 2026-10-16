const crypto = require('node:crypto');

function authorizationVerifier(key, now = () => Date.now()) {
    const used = new Map();
    return body => {
        if (typeof body?.payload !== 'string' || body.payload.length > 50000 || !/^[a-f0-9]{64}$/.test(body.signature || '')) throw new Error('Authorize this send through Fabric POS.');
        const expected = crypto.createHmac('sha256', key).update(body.payload).digest();
        if (!crypto.timingSafeEqual(expected, Buffer.from(body.signature, 'hex'))) throw new Error('Invalid send authorization.');
        const payload = JSON.parse(body.payload);
        const time = Math.floor(now() / 1000);
        for (const [nonce, expiry] of used) if (expiry < time) used.delete(nonce);
        if (!Number.isInteger(payload.expires) || payload.expires < time || payload.expires > time + 60 || typeof payload.nonce !== 'string' || used.has(payload.nonce)) throw new Error('Send authorization expired or was already used.');
        used.set(payload.nonce, payload.expires);
        return payload;
    };
}
module.exports = { authorizationVerifier };
