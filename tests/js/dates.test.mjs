import { test } from 'node:test';
import assert from 'node:assert/strict';
import { localInput, displayDate } from '../../resources/js/pages/fabric/dates.ts';

test('PostgreSQL, SQL and ISO timestamps have the same shop-local date', () => {
    for (const date of ['2026-10-15 10:30:00+00', '2026-10-15 10:30:00+0000', '2026-10-15 10:30:00+00:00', '2026-10-15T10:30:00Z', '2026-10-15 10:30:00', '2026-10-15T15:30:00+05:00']) {
        assert.equal(localInput('Asia/Karachi', date), '2026-10-15T15:30');
    }
});
test('missing and invalid stored dates never crash or pretend to be today', () => {
    for (const date of ['', 'not-a-date', '2026-99-99']) {
        assert.equal(localInput('Asia/Karachi', date), '');
        assert.equal(displayDate('Asia/Karachi', date), '—');
    }
    assert.equal(localInput('invalid-zone', '2026-10-15T10:30:00Z'), '2026-10-15T10:30');
    assert.match(localInput('Asia/Karachi'), /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/);
});
