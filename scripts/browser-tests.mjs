import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
if (!fs.existsSync(path.join(root, 'package.json'))) throw new Error('Invalid BShop root.');
const cache = path.join(root, '.runtime', 'playwright-browsers');
const args = process.argv.slice(2);
const command = args[0] === '--install' ? ['install', 'chromium', 'webkit'] : ['test', ...args];
const child = spawn(process.execPath, [path.join(root, 'node_modules', '@playwright', 'test', 'cli.js'), ...command], {
    cwd: root,
    stdio: 'inherit',
    env: { ...process.env, PLAYWRIGHT_BROWSERS_PATH: cache },
});
child.on('exit', code => { process.exitCode = code ?? 1; });
child.on('error', error => { console.error(error.message); process.exitCode = 1; });
