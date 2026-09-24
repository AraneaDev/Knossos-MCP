import { CanActivate, Catch, ExceptionFilter, Injectable, NestInterceptor, NestMiddleware, PipeTransform } from '@nestjs/common';
import { OnGatewayConnection, WebSocketGateway } from '@nestjs/websockets';

// A provider that is none of the roles below keeps only the lifecycle hooks.
@Injectable()
export class Helper {
    canActivate(): boolean { return true; }
    intercept(): void {}
    transform(): void {}
    use(): void {}
    catch(): void {}
    handleConnection(): void {}
    onModuleInit(): void {}
}

@Injectable()
export class RolesGuard implements CanActivate {
    canActivate(): boolean { return true; }
}

@Injectable()
export class AdminGuard {
    canActivate(): boolean { return true; }
}

@Injectable()
export class LoggingInterceptor implements NestInterceptor {
    intercept(): void {}
    transform(): void {}
}

@Injectable()
export class ParsePipe implements PipeTransform {
    transform(): void {}
}

@Catch()
export class HttpFilter implements ExceptionFilter {
    catch(): void {}
}

@Injectable()
export class LoggerMiddleware implements NestMiddleware {
    use(): void {}
}

@WebSocketGateway()
export class Events {
    handleConnection(): void {}
    afterInit(): void {}
    canActivate(): boolean { return true; }
}

// Known by the interface alone, with no conventional suffix.
@Injectable()
export class TokenCheck implements CanActivate {
    canActivate(): boolean { return true; }
}

@Injectable()
export class Timing implements NestInterceptor {
    intercept(): void {}
}

// Nest connects only a class registered with `@WebSocketGateway()`; the
// interface alone leaves this provider's hook uncalled.
@Injectable()
export class Presence implements OnGatewayConnection {
    handleConnection(): void {}
}
