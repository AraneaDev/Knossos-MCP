<?php

declare(strict_types=1);

namespace Fixture;

use JMS\Serializer\Annotation as JMS;
use JMS\Serializer\Annotation\VirtualProperty;

final class Serialized
{
    /**
     * @JMS\VirtualProperty()
     * @JMS\SerializedName("provider")
     */
    public function provider(): string
    {
        return 'x';
    }

    #[VirtualProperty]
    public function label(): string
    {
        return 'y';
    }

    public function plain(): string
    {
        return 'z';
    }
}
