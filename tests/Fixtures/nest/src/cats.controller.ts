import { Controller, Get, Post } from '@nestjs/common';
import { CatsService } from './cats.service';

@Controller('cats')
export class CatsController {
  constructor(private readonly cats: CatsService) {}

  @Get()
  findAll(): string[] { return this.cats.findAll(); }

  @Post('adopt')
  adopt(): void {}
}
