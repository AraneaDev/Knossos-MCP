<?php

declare(strict_types=1);

namespace Fixture;

final class CardFactory
{
    public function fromVariable(string $command): object
    {
        $card = 'App\\Cards\\' . ucfirst($command);

        return new $card();
    }

    public function inline(string $command): object
    {
        return new ('\\App\\Widgets\\' . $command)();
    }

    public function nested(string $segment, string $name): object
    {
        return new ('App\\Cards\\' . $segment . '\\' . $name)();
    }

    public function fixedSegment(string $name): object
    {
        return new ('App\\Cards\\' . 'Parts\\' . $name)();
    }

    public function looped(array $names): void
    {
        $card = 'App\\Cards\\' . 'Help';
        foreach ($names as $card) {
            new $card();
        }
    }

    public function closure(string $command): callable
    {
        $card = 'App\\Cards\\' . $command;

        return static function (string $card): object {
            return new $card();
        };
    }

    public function captured(string $command): array
    {
        $card = 'App\\Gadgets\\' . $command;

        return [
            fn(): object => new $card(),
            function () use ($card): object {
                return new $card();
            },
        ];
    }

    public function reassigned(string $command): object
    {
        $card = 'App\\Cards\\' . $command;
        $card = $command;

        return new $card();
    }
}
