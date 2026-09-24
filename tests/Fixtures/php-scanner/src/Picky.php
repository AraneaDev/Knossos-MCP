<?php

declare(strict_types=1);

namespace Fixture\Validator;

use Symfony\Component\Validator\Constraint;

final class Picky extends Constraint
{
    public function validatedBy(): string
    {
        return SharedValidator::class;
    }
}
