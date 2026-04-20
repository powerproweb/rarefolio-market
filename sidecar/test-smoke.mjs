/**
 * Sidecar smoke test  —  Phase 2
 *
 * Assumes the sidecar is running on $SIDECAR_BASE_URL (default http://localhost:4000).
 *
 *   node sidecar/test-smoke.mjs
 *
 * Exits 0 on success, 1 on any failure.
 *
 * Tests that require POLICY_MNEMONIC are automatically skipped when that env
 * var is absent (they would fail anyway because the wallet cannot initialise).
 */
const BASE          = process.env.SIDECAR_BASE_URL || 'http://localhost:4000';
const POLICY_READY  = Boolean(process.env.POLICY_MNEMONIC?.trim());

let pass = 0;
let fail = 0;
let skip = 0;
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

function skipTest(name, reason) {
    skip++;
    console.log(`  skip ${name} (${reason})`);
}

function expect(cond, msg = 'expectation failed') {
    if (!cond) throw new Error(msg);
}

console.log(`Sidecar smoke test against ${BASE}`);
console.log('==========================================');

// -----------------------------------------------------------------------
// /health
// -----------------------------------------------------------------------
await test('GET /health returns ok:true + correct service name', async () => {
    const r = await fetch(`${BASE}/health`);
    expect(r.ok, `status ${r.status}`);
    const j = await r.json();
    expect(j.ok === true,                  'ok != true');
    expect(j.service === 'rarefolio-sidecar', `service=${j.service}`);
    expect(typeof j.version === 'string',  'missing version');
    expect(typeof j.policy_ready === 'boolean', 'missing policy_ready');
    expect(j.policy_ready === POLICY_READY, `policy_ready mismatch (env=${POLICY_READY} response=${j.policy_ready})`);
});

// -----------------------------------------------------------------------
// /mint/policy-id
// -----------------------------------------------------------------------
if (POLICY_READY) {
    await test('GET /mint/policy-id returns 56-char hex policy_id', async () => {
        const r = await fetch(`${BASE}/mint/policy-id`);
        expect(r.ok, `status ${r.status}`);
        const j = await r.json();
        expect(typeof j.policy_id === 'string' && /^[0-9a-f]{56}$/.test(j.policy_id),
            `invalid policy_id: ${j.policy_id}`);
        expect(typeof j.policy_addr === 'string' && j.policy_addr.startsWith('addr'),
            `invalid policy_addr: ${j.policy_addr}`);
        console.log(`       policy_id: ${j.policy_id}`);
    });
} else {
    skipTest('GET /mint/policy-id', 'POLICY_MNEMONIC not set');
}

// -----------------------------------------------------------------------
// /mint/prepare — validation (400) path always runs
// -----------------------------------------------------------------------
await test('POST /mint/prepare with missing fields returns 400', async () => {
    const r = await fetch(`${BASE}/mint/prepare`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ rarefolio_token_id: 'x' }),   // missing required fields
    });
    expect(r.status === 400, `expected 400 got ${r.status}`);
    const j = await r.json();
    expect(Array.isArray(j.issues), 'expected issues array in 400 response');
});

// /mint/prepare — success path requires policy wallet
if (POLICY_READY) {
    await test('POST /mint/prepare with valid payload returns cbor_hex + policy_id', async () => {
        const r = await fetch(`${BASE}/mint/prepare`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                rarefolio_token_id: 'smoke-test-001',
                collection_slug:    'smoke-test',
                asset_name_utf8:    'SmokeTest001',
                recipient_addr:     'addr_test1qpkxqn9jpzrjdpls0g5agqefm60glx6k5qhvtdjfar40plq2pqfssufygjpxrxclpjh2p7r37r3llxm49myvf8dfhpqq5rpdr',
                cip25: { name: 'Smoke Test', image: 'ipfs://Qmtest', mediaType: 'image/jpeg' },
            }),
        });
        expect(r.ok, `status ${r.status} body=${await r.text()}`);
        const j = await r.json();
        expect(j.stub === false, `expected stub=false, got ${j.stub}`);
        expect(typeof j.cbor_hex === 'string' && j.cbor_hex.length > 20, 'missing/short cbor_hex');
        expect(typeof j.policy_id === 'string' && /^[0-9a-f]{56}$/.test(j.policy_id), 'invalid policy_id');
    });
} else {
    skipTest('POST /mint/prepare success path', 'POLICY_MNEMONIC not set');
}

// -----------------------------------------------------------------------
// /mint/submit — validation only (we don't submit real txs in smoke tests)
// -----------------------------------------------------------------------
await test('POST /mint/submit with missing cbor_hex returns 400', async () => {
    const r = await fetch(`${BASE}/mint/submit`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({}),
    });
    expect(r.status === 400, `expected 400 got ${r.status}`);
});

// -----------------------------------------------------------------------
// /sync/token
// -----------------------------------------------------------------------
await test('GET /sync/token/:unit with bad unit returns 400', async () => {
    const r = await fetch(`${BASE}/sync/token/not-hex`);
    expect(r.status === 400, `expected 400 got ${r.status}`);
});

await test('GET /sync/token/:unit with non-existent asset returns 404', async () => {
    // 56 zeros is a valid hex unit format but will not exist on-chain
    const r = await fetch(`${BASE}/sync/token/${'0'.repeat(64)}`);
    expect(r.status === 404, `expected 404 got ${r.status}`);
});

// -----------------------------------------------------------------------
// /sync/policy
// -----------------------------------------------------------------------
await test('GET /sync/policy/:policyId with bad id returns 400', async () => {
    const r = await fetch(`${BASE}/sync/policy/not-hex`);
    expect(r.status === 400, `expected 400 got ${r.status}`);
});

// -----------------------------------------------------------------------
// /asset
// -----------------------------------------------------------------------
await test('GET /asset/:unit with bad unit returns 400', async () => {
    const r = await fetch(`${BASE}/asset/not-hex`);
    expect(r.status === 400, `expected 400 got ${r.status}`);
});

// -----------------------------------------------------------------------
// /handle
// -----------------------------------------------------------------------
await test('GET /handle/:h on non-mainnet returns note or resolved_addr', async () => {
    const r = await fetch(`${BASE}/handle/rarefolio`);
    expect(r.ok, `status ${r.status}`);
    const j = await r.json();
    expect(j.handle === 'rarefolio', `handle echo: ${j.handle}`);
    if (j.note) {
        expect(/mainnet/i.test(j.note), 'expected mainnet note on non-mainnet');
    }
});

console.log(`\nResults: ${pass} passed, ${fail} failed, ${skip} skipped`);
if (fail > 0) {
    for (const [n, m] of failures) console.log(`  - ${n}: ${m}`);
    process.exit(1);
}
process.exit(0);
