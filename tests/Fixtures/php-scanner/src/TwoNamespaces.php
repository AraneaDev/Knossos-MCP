<?php

declare(strict_types=1);

namespace Fixture\First {
    use Fixture\Rules\Email as Rule;

    /** @Rule */
    final class Contact
    {
    }
}

namespace Fixture\Second {
    use Fixture\Rules\Phone as Rule;

    /** @Rule */
    final class Caller
    {
    }
}
