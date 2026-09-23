import path from 'node:path';
import { fileURLToPath, URL } from 'node:url';
declare const __dirname: string;
declare function compute(): string;
export default { resolve: { alias: { '#lib': fileURLToPath(new URL('./src/lib', import.meta.url)), '#root': '/abs', '#store': path.resolve(__dirname, 'src/store'), '#bad': compute() } } };
export const second = { resolve: { alias: [{ find: '#arr', replacement: '/src' }, { find: /^re$/, replacement: '/x' }] } };
