/// <reference types="not-installed" />
// In no tsconfig: Node's types are not loaded for it, so `node:path` and
// `__dirname` are unknown under the options it is checked with.
import { resolve } from 'node:path';

export const root = resolve(__dirname, 'visual');
