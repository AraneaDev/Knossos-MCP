<?php

declare(strict_types=1);

namespace Fixture;

final class Singleton
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function fresh(): static
    {
        return new static();
    }

    public static function capture(): void
    {
        $instance = self::getInstance();
        $instance->record(message: 'x');
        self::fresh()->flush();
    }

    private function record(string $message): void
    {
    }

    private function flush(): void
    {
    }
}
