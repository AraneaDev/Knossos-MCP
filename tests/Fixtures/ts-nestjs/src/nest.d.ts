declare module '@nestjs/common' {
    export function Injectable(): ClassDecorator;
    export function Catch(): ClassDecorator;
    export interface CanActivate { canActivate(context: unknown): boolean; }
    export interface NestInterceptor { intercept(): void; }
    export interface PipeTransform { transform(): void; }
    export interface ExceptionFilter { catch(): void; }
    export interface NestMiddleware { use(): void; }
}
declare module '@nestjs/passport' {
    export function AuthGuard(name: string): new () => { canActivate(context: unknown): boolean };
}
declare module '@nestjs/websockets' {
    export function WebSocketGateway(): ClassDecorator;
    export interface OnGatewayConnection { handleConnection(): void; }
}
