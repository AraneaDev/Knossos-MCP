<?php

declare(strict_types=1);

namespace Knossos\Classification;

use Knossos\Scanner\Protocol\Confidence;

/** Infers roles from Django, FastAPI, Flask, and Celery conventions. */
final readonly class PythonFrameworkRoleRule extends AbstractFrameworkRoleRule
{
    /** {@inheritDoc} */
    public function id(): string
    {
        return 'python.framework.ast.v1';
    }

    /** {@inheritDoc} */
    protected function knownRoles(): array
    {
        return [
            'django.middleware',
            'django.model',
            'django.view',
            'fastapi.route_handler',
            'flask.route_handler',
            'flask.view',
            'python.task',
        ];
    }

    /** {@inheritDoc} */
    protected function attributeKey(): string
    {
        return 'python_framework_roles';
    }

    /** {@inheritDoc} */
    protected function confidence(): Confidence
    {
        return Confidence::Certain;
    }

    /** {@inheritDoc} */
    protected function evidenceMeta(): array
    {
        return ['source' => 'python AST decorator/base'];
    }
}
