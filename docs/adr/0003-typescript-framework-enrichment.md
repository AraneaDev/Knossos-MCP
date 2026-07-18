# ADR 0003: prioritize static NestJS enrichment

Status: Accepted (2026-07-18)

## Context

Knossos needs framework facts that are architecturally useful, statically
recognizable, evidence-backed, and safe to extract without importing or booting
target applications. We evaluated NestJS, Next.js, and Express using their
current primary documentation.

- NestJS defines modules through `@Module()` metadata (`imports`, `providers`,
  `controllers`, and `exports`) and controllers/routes through explicit class
  and HTTP-method decorators. These declarations directly describe its module
  graph and routing map. See the official [modules](https://docs.nestjs.com/v4/modules)
  and [controllers](https://docs.nestjs.com/controllers) documentation.
- Next.js App Router has strong file conventions: route segments come from
  folders, public routes require special `page` or `route` files, route groups
  and dynamic segments transform paths, and route handlers export named HTTP
  methods. See [project structure](https://nextjs.org/docs/app/getting-started/project-structure)
  and [`route.js`](https://nextjs.org/docs/app/api-reference/file-conventions/route).
- Express declares routes and middleware through method calls on application
  and Router values. Its official [routing guide](https://expressjs.com/en/4x/guide/routing/)
  confirms the model, but aliases, router factories, re-exports, and mounts
  require more inter-file value-flow analysis than decorator/file recognition.

## Decision

Implement NestJS first. Recognize only decorators imported by name (including
aliases) from `@nestjs/common`. Emit certain `nestjs.module`,
`nestjs.controller`, and `nestjs.provider` roles; literal controller/method
routes and `routes_to` edges; and literal `@Module()` array relations using
existing graph edge kinds. Preserve decorator evidence and mark generated facts
as `framework_convention`. Skip dynamic metadata and paths rather than guessing.

Defer Next.js to a dedicated App Router enricher after a fixture matrix covers
route groups, private/parallel/intercepting/dynamic segments, `src/app`, and
special-file hierarchy. Defer Express until a bounded alias/router/mount
data-flow design can state confidence accurately.

## Consequences

NestJS provides high-value module and request-flow facts with low false-positive
risk and no project execution. The TypeScript worker version advances to 0.3.0,
invalidating its contribution cache. Next.js and Express remain ordinary
TypeScript graphs rather than receiving incomplete framework labels.
