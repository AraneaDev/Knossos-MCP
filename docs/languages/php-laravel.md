# Laravel static enrichment support

Knossos detects Laravel from a root Composer requirement on
`laravel/framework`. The Phase 2 enricher targets source-compatible static
idioms used by Laravel 10, 11, and 12; it does not boot Laravel or claim runtime
resolution.

| Area        | Supported static idioms                                                                                                                                  | Confidence                                                  |
| ----------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| Routes      | `Route::get/post/put/patch/delete/options/any/match`, controller arrays/classes, fluent names and middleware, nested `prefix`/`name`/`middleware` groups | certain when literals; dynamic URI is diagnosed and omitted |
| Roles       | framework base classes/interfaces                                                                                                                        | certain                                                     |
| Roles       | conventional `app/` directories                                                                                                                          | probable                                                    |
| Roles       | conservative class suffixes                                                                                                                              | possible                                                    |
| Events/jobs | `SomeEvent::dispatch()`, `event(new Event)`, `dispatch(new Job)`                                                                                         | certain                                                     |
| Listeners   | static `$listen` provider maps                                                                                                                           | certain mapping, listener retained in attributes            |
| Container   | `$this->app->bind/singleton/scoped(Contract::class, Implementation::class)`                                                                              | certain                                                     |
| Policies    | static `$policies` provider maps                                                                                                                         | certain mapping, policy retained in attributes              |
| Observers   | `Model::observe(Observer::class)`                                                                                                                        | certain                                                     |

Unsupported dynamic expressions are omitted or diagnosed. Route macros,
runtime provider mutation, container closures, cached/generated routes, package
auto-discovery internals, and values requiring application execution are not
resolved in this phase.
