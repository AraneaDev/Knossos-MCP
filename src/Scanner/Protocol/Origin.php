<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

enum Origin: string
{
    case Ast = 'ast';
    case Composer = 'composer';
    case PackageManifest = 'package_manifest';
    case Config = 'config';
    case FrameworkConvention = 'framework_convention';
    case Derived = 'derived';
    case UserRule = 'user_rule';
}
