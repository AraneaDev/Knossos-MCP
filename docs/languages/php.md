# PHP

Knossos scans PHP with the bundled PHP worker, which parses each `.php` file
with `nikic/php-parser` and never runs your code. It reads one file at a time:
anything that depends on another file, such as which class a call reaches, is
settled when the facts of every file are put together. The
[Laravel](php-laravel.md) and [Symfony](php-symfony.md) pages describe what each
framework adds on top.

## Variables and closures

A call is attributed to a class when the receiver's type is known in the scope
the call is written in:

- a typed parameter or property, `$this`, or `(new Foo())`, which are certain
- a local `$x = new Foo()` or the declared return type of a call, which are
  probable
- `$x ??= new Foo()` when `$x` held no other type, which is probable
- the value of `foreach ($items as $item)` when `$items` is a parameter whose
  docblock names its element type (`@param list<Foo> $items`, `array<K, Foo>`,
  `iterable<Foo>` or `Foo[]`), which is probable
- `$result = $run()` when `$run` holds a closure that declares its return type,
  or one returned by a method of the same file documented as
  `@return \Closure(): Foo`, which is probable
- `$x = $typed->property`, including a property declared in another file, so
  that `$x->run()` and `$x->other?->run()` follow the declared property types,
  which is probable

A closure and an arrow function have their own variables. A typed parameter of
`fn (Foo $x) => $x->run()` types its calls even when the method around it has a
`$x` of another type, and an assignment inside a closure does not change the
type of the variable outside. An arrow function sees every variable around it,
and a closure sees its `use` list. After a closure takes a variable by reference
and assigns it, the code around it no longer knows that variable's type.

## Names and case

PHP reads the names of namespaces, classes, interfaces, traits, enums, functions
and methods without regard to case, so `new foo()` builds the `Foo` that another
file declares. Knossos matches these names the same way, including a class name built
at runtime from a namespace literal and a method called on a receiver no scan
could type. Property names stay case-sensitive, as PHP keeps them.

A declared symbol keeps the name its declaration spells, and its ID with it:
the graph does not lower-case names. A name written in another case is matched
only when no exact spelling matches, so code that spells its names as declared
is unaffected. A symbol that nothing in the scan declares, such as `\DateTime`
written in two ways, is one external node, named by the spelling the
declaration of its type uses or else by the first of the spellings in use, in
byte order.
