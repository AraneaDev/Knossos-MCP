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

    public function reassigned(string $command): object
    {
        $card = 'App\\Cards\\' . $command;
        $card = $command;

        return new $card();
    }
}
