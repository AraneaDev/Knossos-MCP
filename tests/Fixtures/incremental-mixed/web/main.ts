import { foo } from './barrel';
import { dep } from 'dep';

export function main(): void {
    foo();
    dep();
}
