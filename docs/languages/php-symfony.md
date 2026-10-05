# Symfony

Knossos reads a Symfony project's controllers, routes, commands, message handlers, event listeners and services from the PHP source and its attributes. It loads no autoloader, imports no application class, compiles no container and boots no kernel.

A project counts as Symfony when its root `composer.json` requires `symfony/framework-bundle`, `symfony/http-kernel`, `symfony/console` or `symfony/messenger`, or when you list `symfony` under `frameworks` in your [project configuration](../get-started/project-configuration.md). Generic PHP facts (declarations, inheritance, calls, construction, types and constructor injection) are in the graph whether or not a convention is recognized.

## What you get

| Area        | Recognized source                                                                  | Graph facts                                       |
| ----------- | ---------------------------------------------------------------------------------- | ------------------------------------------------- |
| Controllers | `AbstractController`, `#[AsController]`, `#[Route]`                                | controller and route-handler roles                |
| Routes      | class-level and method-level `#[Route]` paths, names and method lists              | `route` nodes and `routes_to` edges               |
| Commands    | `#[AsCommand(name: ...)]`                                                          | `command` nodes and `handles` edges               |
| Messenger   | `#[AsMessageHandler]` and a typed handler parameter                                | message-handler roles and `handles_message` edges |
| Events      | `#[AsEventListener]`, `EventSubscriberInterface`, static subscriber arrays         | listener and subscriber roles, `listens_to` edges |
| Services    | `#[AsAlias]`, `#[Autoconfigure]`, typed constructors, `#[Autowire(service: ...)]`  | service roles, `binds` and `injects` edges        |
| Validation  | Doctrine annotations such as `@AdminEmail`, and a `Constraint`'s `<Name>Validator` | `references` edges                                |
| Migrations  | the directories `migrations_paths` names in the Doctrine Migrations YAML           | entry points                                      |
| Handlers    | a `services.yaml` `resource:` block with `tags:`: every class in its directory     | entry points                                      |
| Serializer  | JMS Serializer `@VirtualProperty` and `#[VirtualProperty]` methods                 | `runtime_invoked`                                 |

Attribute values have to be strings, string arrays or class constants. A dynamic route path, command name or event target produces a diagnostic instead of a guessed fact: `SYMFONY_DYNAMIC_ROUTE_PATH`, `SYMFONY_DYNAMIC_COMMAND_NAME` or `SYMFONY_DYNAMIC_EVENT`. Evidence always points at the attribute, declaration, parameter or subscriber entry that produced the fact.

## Limits

- YAML and XML service and route imports are not interpreted. The one exception is the directory of a tagged `resource:` block.
- Container extensions, compiler passes, runtime service decoration, generated containers and expression-language values are not executed.
- Subscriber arrays record the event keys that are visible in the source. A subscription computed at runtime is omitted.
- An attribute alias with an expression Knossos cannot read stays a generic PHP attribute.
