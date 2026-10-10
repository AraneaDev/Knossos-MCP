use super::{
    call_kind, collect_declarations, collect_test_modules, walk, Declarations, TestModules,
};
use crate::facts::Facts;
use crate::layout::Layout;

/// A method lives in an `impl` block, not beside the type, so a collector
/// that walks only top-level items never indexes one. Nothing in the crate
/// can then resolve a call to it, and every method reads as uncalled
/// however many call sites it really has.
#[test]
fn impl_block_methods_enter_the_declaration_index() {
    let file: syn::File =
        syn::parse_str("struct Facts;\nimpl Facts {\n    fn new() -> Self { Facts }\n}")
            .expect("parses");
    let mut declarations = Declarations::new();

    collect_declarations("crate::facts", &file.items, &mut declarations);

    assert_eq!(Some(&1), declarations.get("crate::facts::Facts"));
    assert_eq!(Some(&1), declarations.get("crate::facts::Facts::new"));
}

/// Trait impls declare methods on the type too, so they belong in the
/// index on the same terms.
#[test]
fn trait_impl_methods_enter_the_declaration_index() {
    let file: syn::File = syn::parse_str(
        "struct Walk;\ntrait Render { fn render(&self); }\nimpl Render for Walk {\n    fn render(&self) {}\n}",
    )
    .expect("parses");
    let mut declarations = Declarations::new();

    collect_declarations("crate::visit", &file.items, &mut declarations);

    assert_eq!(Some(&1), declarations.get("crate::visit::Walk::render"));
}

/// `#[cfg(unix)] fn is_tty()` beside `#[cfg(windows)] fn is_tty()` are one
/// function, compiled in whichever form the target takes, so a call to it
/// is not ambiguous. Two files declaring one path still are.
#[test]
fn cfg_alternatives_in_one_file_are_one_declaration() {
    let file: syn::File = syn::parse_str(
        "#[cfg(unix)]\nfn is_tty() -> bool { true }\n#[cfg(windows)]\nfn is_tty() -> bool { false }\n#[cfg(not(any(unix, windows)))]\nfn is_tty() -> bool { false }\nfn colour() -> bool { is_tty() }",
    )
    .expect("parses");
    let mut declarations = Declarations::new();
    collect_declarations("crate::ui", &file.items, &mut declarations);
    assert_eq!(Some(&1), declarations.get("crate::ui::is_tty"));
    let mut facts = Facts::new("src/ui.rs");

    walk(
        &mut facts,
        "crate::ui",
        &file,
        &[],
        &declarations,
        &TestModules::new(),
        &Layout::default(),
    );

    assert!(facts
        .finish()
        .edges
        .iter()
        .any(|edge| edge.kind == "calls" && edge.target == "rust:function:crate::ui::is_tty"));
}

/// The behaviour the index exists for: a call to an impl method becomes a
/// real `calls` edge instead of being dropped as unconfirmable.
/// `Wrapper(1)` and `Kind::Io(e)` build values: no `calls` edge, and the
/// type built is referenced. A project call is speculative, so a kind
/// guessed wrong is dropped instead of becoming an external symbol.
#[test]
fn a_constructor_references_its_type_and_calls_nothing() {
    let file: syn::File = syn::parse_str(
        "use serde_json::to_value;\nstruct Wrapper(u32);\nenum Kind { Io(u32) }\nfn helper() {}\nfn build() {\n    let _ = Wrapper(1);\n    let _ = Kind::Io(2);\n    crate::helper();\n    to_value();\n}",
    )
    .expect("parses");
    let mut declarations = Declarations::new();
    collect_declarations("crate", &file.items, &mut declarations);
    let mut facts = Facts::new("src/lib.rs");
    walk(
        &mut facts,
        "crate",
        &file,
        &[],
        &declarations,
        &TestModules::new(),
        &Layout::default(),
    );
    let edges = facts.finish().edges;
    let calls: Vec<(&str, bool)> = edges
        .iter()
        .filter(|edge| edge.kind == "calls")
        .map(|edge| {
            (
                edge.target.as_str(),
                edge.attributes.contains_key("speculative"),
            )
        })
        .collect();

    assert_eq!(
        vec![
            ("rust:function:crate::helper", true),
            ("rust:function:serde_json::to_value", false),
        ],
        calls
    );
    for target in ["rust:class:crate::Wrapper", "rust:class:crate::Kind"] {
        assert!(
            edges
                .iter()
                .any(|edge| edge.kind == "references" && edge.target == target),
            "{target}"
        );
    }
}

/// A `#[path]` declaration renames its declared path for every file, and
/// two declarations that disagree leave the path unresolvable.
#[test]
fn a_renamed_mod_rewrites_the_longest_declared_prefix() {
    let layout = Layout::default();
    let file: syn::File = syn::parse_str(
        "#[path = \"impl_a.rs\"]\npub mod a;\npub mod plain;\n#[cfg(unix)]\n#[path = \"u.rs\"]\nmod sys;\n#[cfg(windows)]\n#[path = \"w.rs\"]\nmod sys;",
    )
    .expect("parses");
    let mut declarations = Declarations::new();
    declarations.add_renames(&super::declared_renames(
        "src/lib.rs",
        "crate",
        &file.items,
        &layout,
    ));

    assert_eq!(
        Some(Some("crate::impl_a::x".to_owned())),
        declarations.renamed("crate::a::x")
    );
    assert_eq!(None, declarations.renamed("crate::plain::x"));
    assert_eq!(Some(None), declarations.renamed("crate::sys::go"));
    assert!(declarations.take_lookups().contains("crate::plain::x"));
}

#[test]
fn a_call_to_an_impl_method_becomes_an_edge() {
    let file: syn::File = syn::parse_str(
        "struct Facts;\nimpl Facts {\n    fn new() -> Self { Facts }\n}\nfn build() { let _ = Facts::new(); }",
    )
    .expect("parses");
    let mut declarations = Declarations::new();
    collect_declarations("crate", &file.items, &mut declarations);
    let mut facts = Facts::new("src/lib.rs");

    walk(
        &mut facts,
        "crate",
        &file,
        &[],
        &declarations,
        &TestModules::new(),
        &Layout::default(),
    );

    let contribution = facts.finish();
    assert!(
        contribution
            .edges
            .iter()
            .any(|edge| edge.kind == "calls" && edge.target == "rust:method:crate::Facts::new"),
        "no calls edge reached Facts::new; edges were {:?}",
        contribution
            .edges
            .iter()
            .map(|edge| format!("{} -> {}", edge.kind, edge.target))
            .collect::<Vec<_>>()
    );
}

/// `use crate::components::*;` brings every public name of that module into
/// scope, so `Camera::perspective(..)` there names the components' Camera.
/// A glob names no symbol of its own, and ignoring it left every call
/// through one unresolved.
#[test]
fn a_name_a_glob_import_brings_in_resolves_through_the_index() {
    let components: syn::File = syn::parse_str(
        "pub struct Camera;\nimpl Camera {\n    pub fn perspective() -> Self { Camera }\n}",
    )
    .expect("parses");
    let engine: syn::File =
        syn::parse_str("use crate::components::*;\nfn create() { let _ = Camera::perspective(); }")
            .expect("parses");
    let mut declarations = Declarations::new();
    collect_declarations("crate::components", &components.items, &mut declarations);
    collect_declarations("crate::engine", &engine.items, &mut declarations);
    let mut facts = Facts::new("src/engine.rs");

    walk(
        &mut facts,
        "crate::engine",
        &engine,
        &[],
        &declarations,
        &TestModules::new(),
        &Layout::default(),
    );

    let contribution = facts.finish();
    let edges: Vec<String> = contribution
        .edges
        .iter()
        .map(|edge| format!("{} -> {}", edge.kind, edge.target))
        .collect();
    assert!(
        edges.contains(&"calls -> rust:method:crate::components::Camera::perspective".to_owned()),
        "edges were {edges:?}"
    );
}

/// A glob brings names into the module that declares it and no other, and
/// its path may start with a module alias the file declares anywhere.
#[test]
fn a_glob_is_scoped_to_its_module_and_expands_an_alias() {
    let left: syn::File =
        syn::parse_str("pub struct Device;\nimpl Device { pub fn open() -> Self { Device } }")
            .expect("parses");
    let right = left.clone();
    let engine: syn::File = syn::parse_str(
        "use parts::*;\nuse crate::left as parts;\nuse crate::left as dep;\nfn top() { let _ = Device::open(); }\nmod a {\n    use crate::right::*;\n    fn go() { let _ = Device::open(); }\n}\nmod b {\n    fn go() { let _ = Device::open(); }\n}\nmod c {\n    use crate::left as kit;\n    use kit::*;\n    fn go() { let _ = Device::open(); }\n}\nmod d {\n    use crate::right as kit;\n    use kit::*;\n    fn go() { let _ = Device::open(); }\n}\nmod e {\n    use ::dep::*;\n    fn go() { let _ = Device::open(); }\n}",
    )
    .expect("parses");
    let mut declarations = Declarations::new();
    collect_declarations("crate::left", &left.items, &mut declarations);
    collect_declarations("crate::right", &right.items, &mut declarations);
    let mut facts = Facts::new("src/engine.rs");

    walk(
        &mut facts,
        "crate::engine",
        &engine,
        &[],
        &declarations,
        &TestModules::new(),
        &Layout::default(),
    );

    let contribution = facts.finish();
    let calls: Vec<String> = contribution
        .edges
        .iter()
        .filter(|edge| edge.kind == "calls")
        .map(|edge| format!("{} -> {}", edge.source, edge.target))
        .collect();
    assert!(
        calls.contains(
            &"rust:function:crate::engine::top -> rust:method:crate::left::Device::open".to_owned()
        ),
        "calls were {calls:?}"
    );
    assert!(
        calls.contains(
            &"rust:function:crate::engine::a::go -> rust:method:crate::right::Device::open"
                .to_owned()
        ),
        "calls were {calls:?}"
    );
    // Sibling modules binding the same alias to different modules.
    assert!(
        calls.contains(
            &"rust:function:crate::engine::c::go -> rust:method:crate::left::Device::open"
                .to_owned()
        ),
        "calls were {calls:?}"
    );
    assert!(
        calls.contains(
            &"rust:function:crate::engine::d::go -> rust:method:crate::right::Device::open"
                .to_owned()
        ),
        "calls were {calls:?}"
    );
    // `::dep` is the external crate, not the file's `dep` alias.
    assert!(
        !calls.iter().any(|call| call
            .starts_with("rust:function:crate::engine::e::go -> rust:method:crate::left")),
        "calls were {calls:?}"
    );
    // `b` imports nothing, so the top-level glob does not reach it.
    assert!(
        !calls.iter().any(|call| call
            .starts_with("rust:function:crate::engine::b::go -> rust:method:crate::left")),
        "calls were {calls:?}"
    );
}

/// `#[cfg(test)] mod tests;` puts the module in its own file, which is
/// scanned as a separate contribution carrying no hint that it compiles
/// only under cfg(test). The declaring file is the only place that fact
/// exists, so it has to travel from there to the walk of the other file.
#[test]
fn an_out_of_line_cfg_test_module_marks_the_file_that_holds_it() {
    let declaring: syn::File =
        syn::parse_str("#[cfg(test)]\nmod tests;\nmod real;\nfn ship() {}").expect("parses");
    let mut test_modules = TestModules::new();

    collect_test_modules(
        "src/lib.rs",
        "crate",
        &declaring.items,
        &Layout::default(),
        &mut test_modules,
    );

    assert!(test_modules.contains("crate::tests"));
    // A bodiless module without the attribute is ordinary source.
    assert!(!test_modules.contains("crate::real"));

    // tests.rs is its own contribution, walked with its own module path.
    let held: syn::File = syn::parse_str("fn helper() {}\nstruct Rig;").expect("parses");
    let mut facts = Facts::new("src/tests.rs");
    walk(
        &mut facts,
        "crate::tests",
        &held,
        &[],
        &Declarations::new(),
        &test_modules,
        &Layout::default(),
    );
    let contribution = facts.finish();

    for name in ["crate::tests::helper", "crate::tests::Rig"] {
        assert!(
            contribution
                .nodes
                .iter()
                .find(|node| node.canonical_name == name)
                .unwrap_or_else(|| panic!("no node {name}"))
                .attributes
                .contains_key("test"),
            "{name} was not marked"
        );
    }

    // A file that is not the test module keeps its production status.
    let other: syn::File = syn::parse_str("fn ship() {}").expect("parses");
    let mut facts = Facts::new("src/real.rs");
    walk(
        &mut facts,
        "crate::real",
        &other,
        &[],
        &Declarations::new(),
        &test_modules,
        &Layout::default(),
    );
    assert!(!facts
        .finish()
        .nodes
        .iter()
        .any(|node| node.attributes.contains_key("test")));
}

/// Rust calls `Drop::drop` during destruction, so no call site names it
/// and the graph shows it unreferenced however heavily the type is used.
/// The marker lets the dead-code budget discount exactly these, without
/// blanket-excluding every method that happens to be called `drop`.
#[test]
fn a_wasm_bindgen_impl_marks_its_public_methods_as_runtime_invoked() {
    let file: syn::File = syn::parse_str(
        "#[wasm_bindgen]\npub struct Engine;\n#[wasm_bindgen]\nimpl Engine {\n    #[wasm_bindgen(constructor)]\n    pub fn new() -> Engine { Engine }\n    pub fn key_up(&mut self, key: &str) {}\n    fn helper(&self) {}\n}\nimpl Engine {\n    pub fn internal(&self) {}\n}\npub struct Widget;\n#[cfg_attr(target_arch = \"wasm32\", wasm_bindgen)]\nimpl Widget {\n    pub fn draw(&self) {}\n}\n#[cfg_attr(test, derive(Debug))]\nimpl Widget {\n    pub fn measure(&self) {}\n}\n#[cfg_attr(feature = \"bindings\", cfg_attr(target_arch = \"wasm32\", wasm_bindgen))]\nimpl Widget {\n    pub fn paint(&self) {}\n}",
    )
    .expect("parses");
    let mut facts = Facts::new("src/lib.rs");
    walk(
        &mut facts,
        "crate",
        &file,
        &[],
        &Declarations::new(),
        &TestModules::new(),
        &Layout::default(),
    );
    let contribution = facts.finish();
    let marked = |name: &str| {
        contribution
            .nodes
            .iter()
            .filter(|node| node.canonical_name == name)
            .any(|node| node.attributes.contains_key("runtime_invoked"))
    };

    // JavaScript calls what the bindings export: the constructor and
    // every public method of the exported impl.
    assert!(marked("crate::Engine::new"));
    assert!(marked("crate::Engine::key_up"));
    // A private helper is not exported, nor is an impl without the attribute.
    assert!(!marked("crate::Engine::helper"));
    assert!(!marked("crate::Engine::internal"));
    // The wasm build applies a conditional `wasm_bindgen`, and exports
    // the same way; another conditional attribute exports nothing.
    assert!(marked("crate::Widget::draw"));
    assert!(!marked("crate::Widget::measure"));
    // A `cfg_attr` may apply another that applies it.
    assert!(marked("crate::Widget::paint"));
}

#[test]
fn a_trait_impl_marks_its_methods_as_overriding() {
    let file: syn::File = syn::parse_str(
        "struct Manifest;\nimpl Default for Manifest {\n    fn default() -> Self { Manifest }\n}\nimpl Manifest {\n    fn build(&self) {}\n}",
    )
    .expect("parses");
    let mut facts = Facts::new("src/lib.rs");
    walk(
        &mut facts,
        "crate",
        &file,
        &[],
        &Declarations::new(),
        &TestModules::new(),
        &Layout::default(),
    );
    let contribution = facts.finish();
    let overrides = |name: &str| {
        contribution
            .nodes
            .iter()
            .filter(|node| node.canonical_name == name)
            .any(|node| node.attributes.get("overrides") == Some(&serde_json::Value::Bool(true)))
    };

    // What the trait declares, reached through it: `Default::default`
    // calls it, and nothing names `Manifest::default`.
    assert!(overrides("crate::Manifest::default"));
    // An inherent method fulfils no trait and keeps its reference check.
    assert!(!overrides("crate::Manifest::build"));
}

#[test]
fn a_drop_impl_marks_its_method_as_runtime_invoked() {
    let file: syn::File = syn::parse_str(
        "struct Lease;\nimpl Drop for Lease {\n    fn drop(&mut self) {}\n}\nimpl Lease {\n    fn drop_manually(&self) {}\n    fn drop(&self) {}\n}",
    )
    .expect("parses");
    let mut facts = Facts::new("src/lib.rs");
    walk(
        &mut facts,
        "crate",
        &file,
        &[],
        &Declarations::new(),
        &TestModules::new(),
        &Layout::default(),
    );
    let contribution = facts.finish();
    let marked = |name: &str| {
        contribution
            .nodes
            .iter()
            .filter(|node| node.canonical_name == name)
            .any(|node| node.attributes.contains_key("runtime_invoked"))
    };

    assert!(marked("crate::Lease::drop"), "the Drop::drop impl");
    // An inherent method named `drop` is an ordinary method with ordinary
    // call sites, so it stays subject to the reference check.
    assert!(
        contribution
            .nodes
            .iter()
            .filter(|node| node.canonical_name == "crate::Lease::drop")
            .count()
            >= 1
    );
    assert!(
        !marked("crate::Lease::drop_manually"),
        "an unrelated method"
    );
}

/// `not(test)` and `any(test, ..)` hold in a production build too, so
/// only a predicate no production build meets marks test code.
#[test]
fn a_cfg_predicate_requires_test_only_where_every_build_that_meets_it_is_a_test() {
    let requires = |predicate: &str| {
        super::cfg::requires_test(&syn::parse_str::<syn::Meta>(predicate).expect("parses"))
    };
    for test_only in [
        "test",
        "all(test, feature = \"x\")",
        "any(test, all(test, unix))",
        "not(not(test))",
        "not(any(not(test), unix))",
        "all(any(test, all(test, unix)), not(any(not(test), windows)))",
        "not(all(not(test), all()))",
    ] {
        assert!(requires(test_only), "{test_only}");
    }
    for production in [
        "not(test)",
        "any(test, feature = \"x\")",
        "all(not(test), unix)",
        "not(all(not(test), unix))",
        "any(not(not(test)), unix)",
        "not(test, unix)",
        "any()",
        "feature = \"test\"",
        "unix",
    ] {
        assert!(!requires(production), "{production}");
    }
}

/// `contains("test")` matched any token whose text held those four
/// letters, so `#[cfg(feature = "latest")]` marked a whole production
/// module as test code and removed it from the dead-code budget. Only a
/// bare `test` identifier counts.
#[test]
fn a_cfg_whose_text_merely_contains_test_is_not_a_test_module() {
    let file: syn::File =
        syn::parse_str("#[cfg(feature = \"latest\")]\nmod fastest {\n    pub fn ship() {}\n}")
            .expect("parses");
    let mut facts = Facts::new("src/lib.rs");
    walk(
        &mut facts,
        "crate",
        &file,
        &[],
        &Declarations::new(),
        &TestModules::new(),
        &Layout::default(),
    );
    let contribution = facts.finish();

    for node in &contribution.nodes {
        assert!(
            !node.attributes.contains_key("test"),
            "{} was marked as test code",
            node.canonical_name
        );
    }
}

/// A `test` ident nested inside `cfg(all(..))` still counts, which is why
/// the match has to recurse rather than look only at the top level.
#[test]
fn a_nested_cfg_all_test_still_marks_the_module() {
    let file: syn::File =
        syn::parse_str("#[cfg(all(test, feature = \"x\"))]\nmod tests {\n    fn helper() {}\n}")
            .expect("parses");
    let mut facts = Facts::new("src/lib.rs");
    walk(
        &mut facts,
        "crate",
        &file,
        &[],
        &Declarations::new(),
        &TestModules::new(),
        &Layout::default(),
    );
    let contribution = facts.finish();

    assert!(contribution
        .nodes
        .iter()
        .find(|node| node.canonical_name == "crate::tests")
        .expect("module node")
        .attributes
        .contains_key("test"));
}

/// Rust keeps its tests beside the code they cover, so a path-based test
/// convention cannot see them. Without a mark from the scanner every
/// `#[test]` fn reads as an unreferenced production symbol: nothing calls
/// it, because the harness invokes it.
#[test]
fn items_under_cfg_test_carry_the_test_attribute() {
    let file: syn::File = syn::parse_str(
        "#[cfg(test)]\nmod tests {\n    #[test]\n    fn it_works() {}\n    fn helper() {}\n}\nfn production() {}",
    )
    .expect("parses");
    let mut facts = Facts::new("src/lib.rs");
    walk(
        &mut facts,
        "crate",
        &file,
        &[],
        &Declarations::new(),
        &TestModules::new(),
        &Layout::default(),
    );
    let contribution = facts.finish();
    let marked = |name: &str| {
        contribution
            .nodes
            .iter()
            .find(|node| node.canonical_name == name)
            .unwrap_or_else(|| panic!("no node named {name}"))
            .attributes
            .contains_key("test")
    };

    assert!(marked("crate::tests"), "the cfg(test) module itself");
    assert!(marked("crate::tests::it_works"), "the #[test] fn");
    // A plain helper inside the test module is test code too: the whole
    // subtree is compiled only under cfg(test).
    assert!(marked("crate::tests::helper"), "a helper inside it");
    assert!(!marked("crate::production"), "production code must not be");
}

#[test]
fn a_snake_case_owner_segment_guesses_a_free_function() {
    assert_eq!("function", call_kind("crate::helper"));
    assert_eq!("function", call_kind("crate::net::http::get"));
}

#[test]
fn an_upper_camel_case_owner_segment_guesses_a_method() {
    assert_eq!("method", call_kind("crate::Engine::stop"));
    assert_eq!("method", call_kind("crate::net::Engine::stop"));
}
