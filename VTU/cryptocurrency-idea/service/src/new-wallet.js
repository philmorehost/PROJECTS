// Generates a fresh seed phrase and prints the hot wallet address for each network.
// Run once. Store the phrase OFFLINE as well as in .env.
import { ethers } from 'ethers';

const phrase = ethers.Wallet.createRandom().mnemonic.phrase;
const at = (path) => ethers.HDNodeWallet.fromPhrase(phrase, undefined, path).deriveChild(0);

console.log('\nSeed phrase (put in .env as MNEMONIC, and write it down offline):\n');
console.log(`  ${phrase}\n`);
console.log('Hot wallets — fund these with the coins you sell, plus fees:');
console.log(`  BSC / Ethereum / Polygon : ${at("m/44'/60'/0'/0").address}   (+ BNB/ETH/POL for gas)`);

try {
  const { TronWeb } = await import('tronweb');
  console.log(`  TRON                     : ${TronWeb.address.fromPrivateKey(at("m/44'/195'/0'/0").privateKey.slice(2))}   (+ TRX for energy)`);
} catch { console.log('  TRON                     : (install tronweb to show)'); }

try {
  const bitcoin = await import('bitcoinjs-lib');
  const pubkey = Buffer.from(ethers.getBytes(at("m/84'/0'/0'/0").publicKey));
  console.log(`  Bitcoin                  : ${bitcoin.payments.p2wpkh({ pubkey }).address}`);
} catch { console.log('  Bitcoin                  : (install bitcoinjs-lib to show)'); }
console.log('');
