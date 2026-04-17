/**
 * Sidecar smoke test.
 *
 * Assumes the sidecar is running on $SIDECAR_BASE_URL (default http://localhost:4000).
 *
 *   node sidecar/test-smoke.mjs
 *
 * Exits 0 on success, 1 on any failure.
 */
const BASE = process.env.SIDECAR_BASE_URL || 'http://localhost:4000';

let pass = 0;
let fail = 0;
const failures = [];

async function test(name, fn) {
    try {
        await fn();
        pass++;
        console.log(`  ok   ${name}`);
    } catch (e) {
        fail++;
        failures.push([name, e?.message ?? String(e)]);
        console.log(`  FAIL ${name} — ${e?.message ?? e}`);
    }
}

function expect(cond, msg = 'expectation failed') {
    if (!cond) throw new Error(msg);
}

console.log(`Sidecar smoke test against ${BASE}`);
console.log('==========================================');

await test('GET /health returns ok:true', async () => {
    const r = await fetch(`${BASE}/health`);
    expect(r.ok, `status ${r.status}`);
    const j = await r.json();
    expect(j.ok === true, 'ok != true');
    expect(j.service === 'rarefolio-sidecar', `service=${j.service}`);
});

await test('POST /mint/prepare with valid payload returns a stub envelope', async () => {
    const r = await fetch(`${BASE}/mint/prepare`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            rarefolio_token_id: 'RF-0001',
            collection_slug: 'genesis',
            asset_name_utf8: 'RareFolioGenesis0001',
            recipient_addr: 'addr_test1qqexampleexampleexample',
            cip25: { '721': { PENDING: { RareFolioGenesis0001: { name: 'x' } } } },
        }),
    });
    expect(r.ok, `status ${r.status}`);
    const j = await r.json();
    expect(j.stub === true, 'expected stub=true (Phase 1)');
    expect(j.request.asset_name_hex === Buffer.from('RareFolioGenesis0001', 'utf8').toString('hex'),
        'asset_name_hex mismatch');
});

await test('POST /mint/prepare with invalid payload returns 400', async () => {
    const r = await fetch(`${BASE}/mint/prepare`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ rarefolio_token_id: 'bad' }),
    });
    expect(r.status === 400, `expected 400 got ${r.status}`);
});

await test('GET /asset/:unit with bad unit returns 400', async () => {
    const r = await fetch(`${BASE}/asset/not-hex`);
    expect(r.status === 400, `expected 400 got ${r.status}`);
});

await test('GET /handle/:h on non-mainnet returns a clear note', async () => {
    const r = await fetch(`${BASE}/handle/rarefolio`);
    expect(r.ok, `status ${r.status}`);
    const j = await r.json();
    expect(j.handle === 'rarefolio', 'handle echo');
    // On preprod the note must mention mainnet-only; on mainnet resolved_addr may be present.
    if (j.note) {
        expect(/mainnet/i.test(j.note), 'expected mainnet note on non-mainnet');
    }
});

console.log(`\nResults: ${pass} passed, ${fail} failed`);
if (fail > 0) {
    for (const [n, m] of failures) console.log(`  - ${n}: ${m}`);
    process.exit(1);
}
process.exit(0);
