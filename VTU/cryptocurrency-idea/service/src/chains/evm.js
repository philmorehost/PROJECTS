import { ethers } from 'ethers';

const ERC20 = [
  'function balanceOf(address) view returns (uint256)',
  'function transfer(address to, uint256 amount) returns (bool)',
];
const erc20 = new ethers.Interface(ERC20);
const root = new Map(); // one HD root per mnemonic (shared by all EVM chains)

/** BNB Smart Chain, Ethereum, Polygon. */
export function createEvmAdapter({ config, chain: c }) {
  const provider = new ethers.JsonRpcProvider(c.rpcUrl, c.chainId, { staticNetwork: true });
  if (!root.has(config.mnemonic)) root.set(config.mnemonic, ethers.HDNodeWallet.fromPhrase(config.mnemonic, undefined, "m/44'/60'/0'/0"));
  const hd = root.get(config.mnemonic);
  const keys = new Map();
  const walletAt = (i) => {
    if (!keys.has(i)) keys.set(i, hd.deriveChild(i).privateKey);
    return new ethers.Wallet(keys.get(i), provider);
  };
  const hot = () => walletAt(0);

  let lock = Promise.resolve();
  const withLock = (fn) => {
    const run = lock.then(fn, fn);
    lock = run.catch(() => {});
    return run;
  };
  let nextNonce;

  async function fees() {
    const fd = await provider.getFeeData();
    if (!c.legacyGas && fd.maxFeePerGas) {
      return { type: 2, maxFeePerGas: fd.maxFeePerGas, maxPriorityFeePerGas: fd.maxPriorityFeePerGas ?? 1_000_000_000n };
    }
    return { type: 0, gasPrice: ((fd.gasPrice ?? 1_000_000_000n) * 110n) / 100n };
  }
  async function legacyGasPrice() {
    const fd = await provider.getFeeData();
    return ((fd.gasPrice ?? 1_000_000_000n) * 110n) / 100n;
  }
  const transferTx = (asset, to, amount) =>
    asset.native ? { to, value: amount } : { to: asset.contract, value: 0n, data: erc20.encodeFunctionData('transfer', [to, amount]) };

  async function balanceAt(asset, address, blockTag = 'latest') {
    if (asset.native) return provider.getBalance(address, blockTag);
    return new ethers.Contract(asset.contract, ERC20, provider).balanceOf(address, { blockTag });
  }

  const self = {
    hotAddress: () => hot().address,
    addressAt: (i) => walletAt(i).address,
    normalizeAddress(a) {
      if (!ethers.isAddress(a)) throw new Error('invalid address');
      return ethers.getAddress(a);
    },
    getBalance: (asset, address) => balanceAt(asset, address),

    async getDeposit(asset, address) {
      const latest = await provider.getBlockNumber();
      const confirmedBlock = Math.max(0, latest - c.confirmations + 1);
      const [seen, confirmed] = await Promise.all([balanceAt(asset, address, 'latest'), balanceAt(asset, address, confirmedBlock)]);
      return { seen, confirmed };
    },

    signFromHot(asset, to, amount) {
      return withLock(async () => {
        const w = hot();
        const base = transferTx(asset, to, amount);
        const pending = await provider.getTransactionCount(w.address, 'pending');
        const nonce = Math.max(pending, nextNonce ?? 0);
        const gas = await provider.estimateGas({ ...base, from: w.address }); // fails if not enough tokens
        const f = await fees();
        const nativeBal = await provider.getBalance(w.address, 'pending');
        if (nativeBal < gas * (f.maxFeePerGas ?? f.gasPrice) + (asset.native ? amount : 0n)) {
          throw new Error('hot wallet has insufficient gas/balance');
        }
        const raw = await w.signTransaction({ ...base, ...f, nonce, gasLimit: (gas * 120n) / 100n, chainId: c.chainId });
        nextNonce = nonce + 1;
        return { raw, hash: ethers.keccak256(raw), meta: { nonce } };
      });
    },

    release() {
      nextNonce = undefined;
    },

    async broadcast(raw) {
      try {
        await provider.broadcastTransaction(raw);
      } catch (e) {
        const msg = String(e.message || '').toLowerCase();
        if (msg.includes('already known') || msg.includes('known transaction')) return;
        nextNonce = undefined; // resync from node
        throw e;
      }
    },

    async getTxStatus(asset, { hash, meta }) {
      const r = await provider.getTransactionReceipt(hash);
      if (r) {
        if (r.status === 0) return { state: 'reverted' };
        const latest = await provider.getBlockNumber();
        return { state: latest - r.blockNumber + 1 >= c.confirmations ? 'confirmed' : 'pending' };
      }
      // Our nonce has been used by another tx → ours can never be mined.
      if (meta?.nonce != null && (await provider.getTransactionCount(hot().address, 'latest')) > meta.nonce) {
        if (!(await provider.getTransactionReceipt(hash))) return { state: 'dropped' };
        return { state: 'pending' };
      }
      return { state: 'not_found' };
    },

    async sweepStep(asset, index, state) {
      const dw = walletAt(index);
      const to = hot().address;
      const gasPrice = await legacyGasPrice();
      if (asset.native) {
        const bal = await provider.getBalance(dw.address);
        const fee = gasPrice * 21_000n;
        if (bal <= fee * 2n) return { status: 'done', tx: state.tx ?? null };
        const t = await dw.sendTransaction({ to, value: bal - fee, gasLimit: 21_000n, gasPrice, type: 0 });
        return { status: 'done', tx: t.hash };
      }
      const tokenBal = await balanceAt(asset, dw.address);
      if (tokenBal === 0n) return { status: 'done', tx: state.tx ?? null };
      const gasLimit = 100_000n;
      const need = gasLimit * gasPrice;
      const nat = await provider.getBalance(dw.address);
      if (nat < need) {
        if (state.status === 'gas_sent' && Date.now() - (state.at ?? 0) < 10 * 60_000) return state;
        const native = config.registry.nativeFor(c.key);
        const signed = await self.signFromHot(native, dw.address, (need * 12n) / 10n - nat);
        await self.broadcast(signed.raw);
        return { status: 'gas_sent', tx: signed.hash };
      }
      const t = await dw.sendTransaction({ to: asset.contract, data: erc20.encodeFunctionData('transfer', [to, tokenBal]), gasLimit, gasPrice, type: 0 });
      return { status: 'done', tx: t.hash };
    },
  };
  return self;
}
