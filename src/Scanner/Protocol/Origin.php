<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

/**
 * What kind of evidence produced a fact.
 *
 * Kept alongside confidence because the two answer different questions: origin
 * says where a fact came from (the AST, a Composer manifest, a framework
 * convention), confidence says how far it is inferred. Both travel to the caller
 * so an answer can be audited rather than trusted.
 */
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
