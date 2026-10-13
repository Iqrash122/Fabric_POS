import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
if (existsSync('.env')) {
    console.log(
        '.env already exists; retained without changes. Check DB_HOST=postgres and DB_PASSWORD before Docker startup.',
    );
} else {
    const password = randomBytes(32).toString('hex');
    const text = readFileSync('.env.example', 'utf8').replace(
        /^DB_PASSWORD=.*$/m,
        `DB_PASSWORD=${password}`,
    );
    writeFileSync('.env', text, { mode: 0o600, flag: 'wx' });
    console.log('Created private local .env with random database password.');
}
