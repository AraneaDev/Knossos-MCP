<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;

/** Infers roles from TypeScript framework conventions such as Next.js, React, and Vue. */
final readonly class TypeScriptFrameworkRoleRule extends AbstractFrameworkRoleRule
{
    /** {@inheritDoc} */
    public function id(): string
    {
        return 'typescript.application.v1';
    }

    /** {@inheritDoc} */
    protected function knownRoles(): array
    {
        return [
            'nextjs.layout',
            'nextjs.page',
            'nextjs.route_handler',
            'nextjs.server_action',
            'react.component',
            'react.hook',
            'state.store',
            'vue.component',
            'vue.composable',
        ];
    }

    /** {@inheritDoc} */
    protected function attributeKey(): string
    {
        return 'typescript_framework_roles';
    }

    /** {@inheritDoc} */
    protected function confidence(): Confidence
    {
        return Confidence::Probable;
    }

    /** {@inheritDoc} */
    protected function evidenceMeta(): array
    {
        return ['source' => 'compiler syntax and application convention'];
    }
}
