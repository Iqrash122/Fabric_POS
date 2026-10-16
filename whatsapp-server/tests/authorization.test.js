const { test } = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const { authorizationVerifier } = require('../authorization');
test('only signed unexpired non-replayed recipient lists can be sent', () => {
    const verify = authorizationVerifier('fixture-secret', () => 1000000);
    const sign = value => { const payload = JSON.stringify(value); return { payload, signature: crypto.createHmac('sha256', 'fixture-secret').update(payload).digest('hex') }; };
    const body = sign({ phones: ['+923001234567'], message: 'Fixture only', expires: 1060, nonce: 'one' });
    assert.deepEqual(verify(body).phones, ['+923001234567']);
    assert.throws(() => verify(body), /already used/);
    assert.throws(() => verify({ ...body, payload: body.payload.replace('Fixture', 'Tampered') }), /Invalid/);
    assert.throws(() => verify(sign({ expires: 999, nonce: 'two' })), /expired/);
    assert.throws(() => verify({ phones: ['+923001234567'] }), /Authorize/);
});
