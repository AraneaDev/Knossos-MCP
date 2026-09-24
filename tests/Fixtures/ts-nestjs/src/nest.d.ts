declare module '@nestjs/common' {
    export function Injectable(): ClassDecorator;
}
declare module '@nestjs/passport' {
    export function AuthGuard(name: string): new () => { canActivate(context: unknown): boolean };
}
