// Networks and coins the service can trade.
// ⚠️ Double-check every contract address on the chain explorer before going live.

export const CHAINS = {
  bsc: {
    key: 'bsc',
    name: 'BNB Smart Chain (BEP20)',
    chainId: 56,
    confirmations: 15,
    family: 'evm',
    legacyGas: true,
    explorerTx: 'https://bscscan.com/tx/',
  },
  ethereum: {
    key: 'ethereum',
    name: 'Ethereum (ERC20)',
    chainId: 1,
    confirmations: 12,
    family: 'evm',
    legacyGas: false,
    explorerTx: 'https://etherscan.io/tx/',
  },
  polygon: {
    key: 'polygon',
    name: 'Polygon (PoS)',
    chainId: 137,
    confirmations: 64,
    family: 'evm',
    legacyGas: false,
    explorerTx: 'https://polygonscan.com/tx/',
  },
  tron: {
    key: 'tron',
    name: 'TRON (TRC20)',
    family: 'tron',
    confirmations: 19, // informational: we wait for the solidified (irreversible) block
    defaultRpc: 'https://api.trongrid.io',
    explorerTx: 'https://tronscan.org/#/transaction/',
  },
  bitcoin: {
    key: 'bitcoin',
    name: 'Bitcoin',
    family: 'btc',
    confirmations: 2,
    defaultRpc: 'https://mempool.space/api', // any Esplora-compatible API
    explorerTx: 'https://mempool.space/tx/',
  },
};

export const ASSETS = {
  BNB: { key: 'BNB', symbol: 'BNB', name: 'BNB', chain: 'bsc', native: true, decimals: 18, displayDecimals: 6, coingeckoId: 'binancecoin' },
  USDT_BSC: { key: 'USDT_BSC', symbol: 'USDT', name: 'Tether USD', chain: 'bsc', native: false, contract: '0x55d398326f99059fF775485246999027B3197955', decimals: 18, displayDecimals: 2, coingeckoId: 'tether' },
  USDC_BSC: { key: 'USDC_BSC', symbol: 'USDC', name: 'USD Coin', chain: 'bsc', native: false, contract: '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', decimals: 18, displayDecimals: 2, coingeckoId: 'usd-coin' },

  ETH: { key: 'ETH', symbol: 'ETH', name: 'Ether', chain: 'ethereum', native: true, decimals: 18, displayDecimals: 6, coingeckoId: 'ethereum' },
  USDT_ETH: { key: 'USDT_ETH', symbol: 'USDT', name: 'Tether USD', chain: 'ethereum', native: false, contract: '0xdAC17F958D2ee523a2206206994597C13D831ec7', decimals: 6, displayDecimals: 2, coingeckoId: 'tether' },
  USDC_ETH: { key: 'USDC_ETH', symbol: 'USDC', name: 'USD Coin', chain: 'ethereum', native: false, contract: '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', decimals: 6, displayDecimals: 2, coingeckoId: 'usd-coin' },

  POL: { key: 'POL', symbol: 'POL', name: 'Polygon', chain: 'polygon', native: true, decimals: 18, displayDecimals: 4, coingeckoId: 'polygon-ecosystem-token' },
  USDT_POLYGON: { key: 'USDT_POLYGON', symbol: 'USDT', name: 'Tether USD', chain: 'polygon', native: false, contract: '0xc2132D05D31c914a87C6611C10748AEb04B58e8F', decimals: 6, displayDecimals: 2, coingeckoId: 'tether' },

  TRX: { key: 'TRX', symbol: 'TRX', name: 'TRON', chain: 'tron', native: true, decimals: 6, displayDecimals: 2, coingeckoId: 'tron' },
  USDT_TRON: { key: 'USDT_TRON', symbol: 'USDT', name: 'Tether USD', chain: 'tron', native: false, contract: 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', decimals: 6, displayDecimals: 2, coingeckoId: 'tether' },

  BTC: { key: 'BTC', symbol: 'BTC', name: 'Bitcoin', chain: 'bitcoin', native: true, decimals: 8, displayDecimals: 6, coingeckoId: 'bitcoin' },
};

export function nativeAssetFor(chainKey) {
  const a = Object.values(ASSETS).find((x) => x.chain === chainKey && x.native);
  if (!a) throw new Error(`No native asset for chain ${chainKey}`);
  return a;
}
