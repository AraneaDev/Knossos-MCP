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

    public function reassigned(string $command): object
    {
        $card = 'App\\Cards\\' . $command;
        $card = $command;

        return new $card();
    }
}
