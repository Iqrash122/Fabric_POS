import { useForm } from '@inertiajs/react';

export type MetaStatus = { configured: boolean; test_ready: boolean; missing: string[] };

export default function MetaWhatsApp({ status, owner }: { status: MetaStatus; owner: boolean }) {
    const check = useForm({});
    const test = useForm({ confirm_test: false });
    return <div className="fabric-grid two">
        <section className="fabric-card"><h2>Meta WhatsApp Cloud API</h2>
            <p>Official Meta API. No QR login or local WhatsApp server is needed.</p>
            <p><strong>{status.configured ? 'Credentials configured — connection not yet verified' : 'Setup required'}</strong></p>
            {!!status.missing.length && <p>Missing server settings: {status.missing.join(', ')}</p>}
            <p>Use the test phone number from Meta API Setup for free testing. Production marketing and template messages can incur Meta charges; production bulk sending is disabled here.</p>
            <p>Keep access tokens in the server’s local .env file. Never enter them in a message or customer form.</p>
            <a href="https://developers.facebook.com/apps/" target="_blank" rel="noreferrer">Open Meta Developer apps</a>
            <form onSubmit={e => { e.preventDefault(); check.post('/fabric/whatsapp/meta/check', { preserveScroll: true }); }}>
                <button className="fabric-button" disabled={!owner || !status.configured || check.processing}>{check.processing ? 'Checking…' : 'Check Meta connection'}</button>
                {Object.values(check.errors).map((error, i) => <p role="alert" key={i}>{String(error)}</p>)}
            </form>
        </section>
        <section className="fabric-card"><h2>Verified test recipient</h2>
            <p>Sends Meta’s hello_world sample only to the test recipient configured on the server. Add and verify that recipient in Meta API Setup first.</p>
            {!status.test_ready && <p>Test sending is locked until a test number is confirmed and a verified recipient is configured.</p>}
            {!owner && <p>Only the owner can verify the connection or send a test.</p>}
            <form onSubmit={e => { e.preventDefault(); test.post('/fabric/whatsapp/meta/test', { preserveScroll: true, onSuccess: () => test.reset() }); }}>
                <label><input type="checkbox" checked={test.data.confirm_test} onChange={e => test.setData('confirm_test', e.target.checked)} /> I confirm this is my consenting, verified test recipient.</label>
                <button className="fabric-button" disabled={!owner || !status.test_ready || !test.data.confirm_test || test.processing}>{test.processing ? 'Sending…' : 'Send hello_world test'}</button>
                {Object.values(test.errors).map((error, i) => <p role="alert" key={i}>{String(error)}</p>)}
            </form>
            <p>Accepted by Meta does not mean delivered. Check the recipient phone before retrying.</p>
        </section>
    </div>;
}
