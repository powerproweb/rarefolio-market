</main>
<footer class="rf-admin-footer">
    <small>RareFolio marketplace &mdash; Phase 1 scaffold</small>
</footer>
<script>
(function () {
    const root = document.getElementById('rf-wallet-status');
    const text = document.getElementById('rf-wallet-status-text');
    if (!root || !text) return;
    function shortenAddress(addr) {
        if (!addr) return '';
        return addr.length > 28 ? (addr.slice(0, 12) + '…' + addr.slice(-8)) : addr;
    }

    function setState(label, stateClass) {
        root.classList.remove('rf-wallet-status-connected', 'rf-wallet-status-disconnected', 'rf-wallet-status-checking');
        root.classList.add(stateClass);
        text.textContent = label;
    }

    async function detectWalletConnection() {
        const cardano = window.cardano;
        if (!cardano) {
            setState('Wallet: no extension', 'rf-wallet-status-disconnected');
            return;
        }

        const preferred = ['eternl', 'lace', 'nami', 'typhon', 'flint', 'yoroi'];
        const available = preferred.filter((key) => cardano[key] && typeof cardano[key].enable === 'function');
        if (!available.length) {
            setState('Wallet: no CIP-30 wallet', 'rf-wallet-status-disconnected');
            return;
        }

        for (const key of available) {
            const wallet = cardano[key];
            if (typeof wallet.isEnabled !== 'function') continue;
            const enabled = await wallet.isEnabled().catch(() => false);
            if (enabled) {
                const api = await wallet.enable().catch(() => null);
                let address = '';
                if (api) {
                    const used = await api.getUsedAddresses().catch(() => []);
                    const change = await api.getChangeAddress().catch(() => null);
                    address = (used && used[0]) || change || '';
                }
                const shortAddr = shortenAddress(address);
                setState(
                    shortAddr ? ('Wallet: ' + shortAddr + ' (' + key + ')') : ('Wallet: connected (' + key + ')'),
                    'rf-wallet-status-connected'
                );
                return;
            }
        }

        setState('Wallet: detected, not connected', 'rf-wallet-status-checking');
    }

    detectWalletConnection();
})();
</script>
</body>
</html>
