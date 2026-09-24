<?php

declare(strict_types=1);

namespace Fixture\Entity;

use Fixture\Validator\AdminEmail;
use Fixture\Validator as AppAssert;

/**
 * @AdminEmail
 * @param string $unused not an annotation class: nothing imports it
 */
final class AnnotatedEntity
{
    /**
     * @AppAssert\CustomerType(groups={"all"})
     * @var string
     */
    private string $type = '';
}
