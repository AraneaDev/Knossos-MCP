<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;

/** Infers the `rust.route_handler` role from worker-emitted attributes. */
final readonly class RustFrameworkRoleRule extends AbstractFrameworkRoleRule
{
    /** {@inheritDoc} */
    public function id(): string
    {
        return 'rust.framework.ast.v1';
    }

    /** {@inheritDoc} */
    protected function knownRoles(): array
    {
        return ['rust.route_handler'];
    }

    /** {@inheritDoc} */
    protected function attributeKey(): string
    {
        return 'rust_framework_roles';
    }

    /** {@inheritDoc} */
    protected function confidence(): Confidence
    {
        return Confidence::Certain;
    }

    /** {@inheritDoc} */
    protected function evidenceMeta(): array
    {
        return ['source' => 'rust AST framework route'];
    }
}
