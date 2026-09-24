import { TokenType } from './lexer';

export function isAnd(type: number): boolean {
    return type === TokenType.And;
}
