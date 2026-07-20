# Symfony static enrichment support

Knossos detects Symfony from Composer requirements for FrameworkBundle,
HttpKernel, Console, or Messenger. It parses PHP source and attributes without
loading Composer autoloaders, importing application classes, compiling the
container, or booting the kernel.

## Supported static facts

| Area        | Recognized source                                                                 | Output                                            |
| ----------- | --------------------------------------------------------------------------------- | ------------------------------------------------- |
| Controllers | `AbstractController`, `#[AsController]`, `#[Route]`                               | controller/route-handler roles                    |
| Routes      | class and method `#[Route]` paths, names, and method lists                        | route nodes and `routes_to` edges                 |
| Commands    | `#[AsCommand(name: ...)]`                                                         | command nodes and `handles` edges                 |
| Messenger   | `#[AsMessageHandler]` and a typed handler parameter                               | message-handler roles and `handles_message` edges |
| Events      | `#[AsEventListener]`, `EventSubscriberInterface`, static subscriber arrays        | listener/subscriber roles and `listens_to` edges  |
| Services    | `#[AsAlias]`, `#[Autoconfigure]`, typed constructors, `#[Autowire(service: ...)]` | service roles, `binds`, and `injects` edges       |

Attribute values must be statically representable strings, string arrays, or
class constants. Dynamic route paths, command names, and event targets produce
stable diagnostics instead of guessed facts. Generic PHP declarations,
inheritance, calls, construction, types, and constructor injection are retained
whether or not Symfony enrichment recognizes a convention.

## Deliberate limits

- YAML/XML service and route imports are not interpreted yet.
- Container extensions, compiler passes, runtime service decoration, generated
  containers, and expression-language values are not executed.
- Subscriber arrays record statically visible event keys; runtime-computed
  subscriptions are omitted.
- Attribute aliases with unsupported expressions remain generic PHP attributes.

These limits keep scans deterministic and safe. Evidence always points to the
source attribute, declaration, parameter, or subscriber entry that produced a
fact.
