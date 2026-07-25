<?php

declare(strict_types=1);

namespace Fixture;

enum Mode
{
    case Fast;
    case Slow;
}

final class Ids
{
    public const PREFIX = 'id-';

    public static int $counter = 0;

    public static function next(): string
    {
        return self::PREFIX . self::$counter;
    }
}

final class Consumer
{
    public function run(mixed $value): string
    {
        if ($value instanceof Mode) {
            return Ids::PREFIX;
        }

        return Ids::next() . Mode::Fast->name . Ids::class . (string) Ids::$counter;
    }

    public function local(): string
    {
        // `self::` names the enclosing class; a class referencing itself is not
        // evidence that anything else uses it.
        return self::class;
    }
}
