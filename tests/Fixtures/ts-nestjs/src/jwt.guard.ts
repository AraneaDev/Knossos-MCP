import { Injectable } from '@nestjs/common';
import { AuthGuard } from '@nestjs/passport';

@Injectable()
export class JwtAuthGuard extends AuthGuard('jwt') {
    canActivate(context: unknown): boolean {
        return super.canActivate(context);
    }

    onModuleInit(): void {}

    helper(): void {}
}

export class Plain {
    canActivate(): boolean {
        return true;
    }
}
