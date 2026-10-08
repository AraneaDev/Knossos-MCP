//! Where a Rust file sits in the project's crates, and which files could hold
//! the declarations of a module.
//!
//! [`Layout::module_of`] places a file in a module by its path, and
//! [`Layout::child_file`] finds the file a `mod` declaration loads, whose
//! module the declaration then names. [`module_files`] is the inverse: a file's module
//! path follows from where it sits, so the files that could declare a name in
//! a module are the files that would sit at that module's path. A walk that
//! asked the declaration index about a name depends on every one of them,
//! whether it exists or not: an edit to one, its deletion, or a file added at
//! one of those paths can change the answer.

use std::collections::BTreeMap;

use crate::resolve::module_path_in_crate;

/// A Cargo package that names its crate: the directory its manifest sits in
/// (empty for the project root, otherwise ending in `/`) and the crate name as
/// Rust code spells it (`core_lib` for a package named `core-lib`).
pub type Package = (String, String);

/// The files a crate's `src/` keeps for a module `rest` segments below its
/// root, relative to `src` itself. The convention pops a trailing `mod`, so
/// both land on the same module.
const MODULE_FILES: [&str; 2] = [".rs", "/mod.rs"];

/// The files a crate's `src/` keeps for its root module.
const ROOT_FILES: [&str; 3] = ["src/lib.rs", "src/main.rs", "src/mod.rs"];

/// The Cargo target directories outside `src/` whose files are crate roots of
/// their own (integration tests, examples, benchmarks). Only a directory chain
/// through one of them is a module the declaration index answers for: Rust
/// reaches no other file outside `src/` without `#[path]`.
const TARGET_DIRECTORIES: [&str; 3] = ["tests", "examples", "benches"];

/// What the request's manifests say about the project's crates.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Layout {
    /// Each crate root that exists, with its package's name: `src/lib.rs`, or
    /// `src/main.rs` beside or instead of it, below a manifest that names its
    /// package.
    pub crates: Vec<(String, String)>,
    /// Every manifest that names its package, by directory (empty for the
    /// project root, otherwise ending in `/`) and the crate name as Rust code
    /// spells it, whether or not its roots exist.
    pub packages: Vec<Package>,
    /// The name code writes for each package's library (`[lib] name`, else
    /// the package name with dashes as underscores), mapped to the root its
    /// modules have in the graph: `crate` for the package at the project
    /// root, the package's crate name for a workspace member.
    pub libraries: BTreeMap<String, String>,
}

impl Layout {
    /// One file's module path.
    ///
    /// A file under a workspace member's `src/` is rooted at that crate's name,
    /// the deepest member claiming it winning; anything else is rooted at
    /// `crate` (see [`module_path_in_crate`]). A binary root is disambiguated
    /// only when the same package also has a library root.
    #[must_use]
    pub fn module_of(&self, relative: &str) -> String {
        let has_library = |directory: &str| {
            self.crates
                .iter()
                .any(|(root_file, _)| root_file == &format!("{directory}src/lib.rs"))
        };
        let member = self
            .crates
            .iter()
            .filter_map(|(root_file, name)| {
                let directory = root_file
                    .strip_suffix("src/lib.rs")
                    .or_else(|| root_file.strip_suffix("src/main.rs"))?;
                (!directory.is_empty() && relative.starts_with(&format!("{directory}src/")))
                    .then_some((directory, name))
            })
            .max_by_key(|(directory, _)| directory.len());
        match member {
            Some((directory, name)) => {
                let inner = &relative[directory.len()..];
                module_path_in_crate(
                    inner,
                    &name.replace('-', "_"),
                    has_library(directory) && inner == "src/main.rs",
                )
            }
            None => module_path_in_crate(
                relative,
                "crate",
                has_library("") && relative == "src/main.rs",
            ),
        }
    }

    /// The project-relative file a `mod name;` declaration in `declaring`
    /// loads, following Rust's own rules, or `None` when `#[path]` leaves the
    /// project or names no file a relative path can spell.
    ///
    /// `inline` is the chain of inline `mod x { .. }` blocks the declaration
    /// sits in, and `path` the declaration's `#[path = ".."]`. A crate root
    /// and a `mod.rs` keep their children beside them (`src/main.rs`'s
    /// `mod cli;` is `src/cli.rs`, `tests/it.rs`'s `mod common;` is
    /// `tests/common.rs`); any other file keeps them in a directory named
    /// after it (`src/net.rs`'s `mod http;` is `src/net/http.rs`). `#[path]`
    /// on a declaration outside inline blocks is relative to the declaring
    /// file's directory; inside them it starts where the blocks' directory
    /// would.
    #[must_use]
    pub fn child_file(
        &self,
        declaring: &str,
        inline: &[String],
        name: &str,
        path: Option<&str>,
    ) -> Option<String> {
        let (directory, file) = declaring.rsplit_once('/').unwrap_or(("", declaring));
        let mut segments: Vec<&str> = directory.split('/').filter(|s| !s.is_empty()).collect();
        let stem = file.strip_suffix(".rs").unwrap_or(file);
        let keeps_beside = file == "mod.rs" || self.is_crate_root(declaring);
        if !(keeps_beside || path.is_some() && inline.is_empty()) {
            segments.push(stem);
        }
        if path.is_none() || !inline.is_empty() {
            segments.extend(inline.iter().map(String::as_str));
        }
        let leaf = format!("{name}.rs");
        let target = path.unwrap_or(&leaf);
        if target.starts_with('/') || target.contains(['\\', '\0']) {
            return None;
        }
        for part in target.split('/') {
            match part {
                "" | "." => {}
                ".." => {
                    segments.pop()?;
                }
                part => segments.push(part),
            }
        }

        (!segments.is_empty()).then(|| segments.join("/"))
    }

    /// Whether Cargo compiles `relative` as a crate root: a package's
    /// `src/lib.rs`, `src/main.rs` or `build.rs`, a binary in `src/bin/`, or
    /// a target under `tests/`, `examples/` or `benches/`, each either a
    /// single file or a directory with a `main.rs`. Decided by the path alone,
    /// relative to the deepest package directory holding it.
    #[must_use]
    pub fn is_crate_root(&self, relative: &str) -> bool {
        let directory = self
            .packages
            .iter()
            .map(|(directory, _)| directory.as_str())
            .filter(|directory| relative.starts_with(directory))
            .max_by_key(|directory| directory.len())
            .unwrap_or("");
        let inner: Vec<&str> = relative[directory.len()..].split('/').collect();
        match inner.as_slice() {
            ["src", "lib.rs" | "main.rs"] | ["build.rs"] => true,
            ["src", "bin", file] => file.ends_with(".rs"),
            ["src", "bin", _, "main.rs"] => true,
            [target, file] => TARGET_DIRECTORIES.contains(target) && file.ends_with(".rs"),
            [target, _, "main.rs"] => TARGET_DIRECTORIES.contains(target),
            _ => false,
        }
    }

    /// A path whose leading segment names one of the project's libraries,
    /// rewritten onto the root its modules have in the graph (see
    /// [`Layout::libraries`]): `my_demo::run` written in `src/main.rs` or
    /// `tests/` is `crate::run`. `None` for any other path.
    #[must_use]
    pub fn library_path(&self, path: &str) -> Option<String> {
        let (head, rest) = match path.split_once("::") {
            Some((head, rest)) => (head, Some(rest)),
            None => (path, None),
        };
        let root = self.libraries.get(head)?;

        Some(match rest {
            Some(rest) => format!("{root}::{rest}"),
            None => root.clone(),
        })
    }

    /// Every path a file placed in `module` could have, see [`module_files`].
    #[must_use]
    pub fn module_files(&self, module: &str) -> Vec<String> {
        module_files(module, &self.packages)
    }

    /// Whether the declaration index holds what `relative` declares: the file
    /// is one of the paths its own module could have, so every lookup that
    /// could find its names reads it.
    #[must_use]
    pub fn is_indexed(&self, relative: &str, module: &str) -> bool {
        self.module_files(module)
            .iter()
            .any(|file| file == relative)
    }
}

/// Every project-relative path whose file would be placed in `module`, under
/// each reading of its first segment, whether the file exists or not.
///
/// `crate` is the root package's `src/`, and a workspace member's crate name
/// is that member's `src/`: `crate::net` is `src/net.rs` or `src/net/mod.rs`.
/// A package whose roots are missing is still listed: adding its `src/lib.rs`
/// makes it a crate, and a file that asked about the crate's names must hear
/// of it. Any other module is a directory chain (`tests::smoke` is
/// `tests/smoke.rs`), and only one through a Cargo target directory
/// (`tests`, `examples`, `benches`) has files: a `proc_macro2::Span` or a
/// top-level `crate/` directory names no module the index answers for.
///
/// A module path no file could have, one with an empty segment or one a
/// project-relative path cannot spell, has no files.
#[must_use]
pub fn module_files(module: &str, members: &[Package]) -> Vec<String> {
    let segments: Vec<&str> = module.split("::").collect();
    if segments
        .iter()
        .any(|segment| segment.is_empty() || matches!(*segment, "." | ".."))
        || module.contains(['/', '\\', '\0'])
    {
        return Vec::new();
    }
    let roots: Vec<(&str, &str)> = std::iter::once(("", "crate"))
        .chain(
            members
                .iter()
                .filter(|(directory, _)| !directory.is_empty())
                .map(|(directory, name)| (directory.as_str(), name.as_str())),
        )
        .filter(|(_, name)| *name == segments[0])
        .collect();
    let mut files = Vec::new();
    if roots.is_empty()
        && segments
            .iter()
            .any(|segment| TARGET_DIRECTORIES.contains(segment))
    {
        let chain = segments.join("/");
        files.extend(MODULE_FILES.iter().map(|suffix| format!("{chain}{suffix}")));
    }
    for (directory, _) in roots {
        if segments.len() == 1 {
            files.extend(ROOT_FILES.iter().map(|file| format!("{directory}{file}")));
        } else {
            let base = format!("{directory}src/{}", segments[1..].join("/"));
            files.extend(MODULE_FILES.iter().map(|suffix| format!("{base}{suffix}")));
        }
    }
    files.sort();
    files.dedup();

    files
}

/// Every module above `path`, from its crate root down: `crate::a::b` gives
/// `crate` and `crate::a`. A name declared at `path` can only come from a file
/// placed in one of them, since a file's declarations sit below its module.
#[must_use]
pub fn modules_above(path: &str) -> Vec<&str> {
    path.match_indices("::")
        .map(|(at, _)| &path[..at])
        .collect()
}

#[cfg(test)]
mod tests {
    use super::{module_files, modules_above, Layout};
    use crate::resolve::module_path_in_crate;

    /// A project whose root package `demo` has a library and a binary, with
    /// a workspace member `core-lib`.
    fn layout() -> Layout {
        Layout {
            crates: vec![
                ("src/lib.rs".to_owned(), "demo".to_owned()),
                ("src/main.rs".to_owned(), "demo".to_owned()),
                (
                    "crates/core-lib/src/lib.rs".to_owned(),
                    "core-lib".to_owned(),
                ),
            ],
            packages: vec![
                (String::new(), "demo".to_owned()),
                ("crates/core-lib/".to_owned(), "core_lib".to_owned()),
            ],
            libraries: [
                ("demo".to_owned(), "crate".to_owned()),
                ("core_lib".to_owned(), "core_lib".to_owned()),
            ]
            .into_iter()
            .collect(),
        }
    }

    #[test]
    fn the_modules_above_a_name_run_from_its_crate_root_down() {
        assert_eq!(vec!["crate", "crate::a"], modules_above("crate::a::b"));
        assert!(modules_above("crate").is_empty());
    }

    #[test]
    fn every_listed_file_of_the_root_package_is_placed_in_the_module() {
        for module in ["crate", "crate::engine", "crate::engine::sign"] {
            let files = module_files(module, &[]);
            assert!(files.contains(&"src/engine/sign.rs".to_owned()) == module.ends_with("sign"));
            for file in &files {
                assert_eq!(module, module_path_in_crate(file, "crate", false), "{file}");
            }
        }
        assert!(module_files("crate", &[]).contains(&"src/lib.rs".to_owned()));
        assert_eq!(
            vec!["src/engine.rs", "src/engine/mod.rs"],
            module_files("crate::engine", &[])
        );
    }

    #[test]
    fn a_target_directory_keeps_its_directory_chain() {
        let files = module_files("tests::smoke", &[]);
        assert_eq!(vec!["tests/smoke.rs", "tests/smoke/mod.rs"], files);
        for file in files {
            assert_eq!("tests::smoke", module_path_in_crate(&file, "crate", false));
        }
    }

    #[test]
    fn a_chain_outside_a_target_directory_names_no_files() {
        assert!(module_files("proc_macro2", &[]).is_empty());
        assert!(module_files("proc_macro2::Span", &[]).is_empty());
        let layout = layout();
        assert!(!layout.is_indexed("crate/engine.rs", &layout.module_of("crate/engine.rs")));
        assert!(!layout.is_indexed("build.rs", &layout.module_of("build.rs")));
        assert!(layout.is_indexed(
            "tests/common/mod.rs",
            &layout.module_of("tests/common/mod.rs")
        ));
        assert!(layout.is_indexed("src/net/lib.rs", &layout.module_of("src/net/lib.rs")));
        assert!(layout.is_indexed("src/main.rs", &layout.module_of("src/main.rs")));
    }

    #[test]
    fn a_member_crate_name_reads_as_that_members_src() {
        let members = layout().packages;
        assert!(
            module_files("core_lib", &members).contains(&"crates/core-lib/src/lib.rs".to_owned())
        );
        assert_eq!(
            vec!["crates/core-lib/src/x.rs", "crates/core-lib/src/x/mod.rs"],
            module_files("core_lib::x", &members)
        );
        // The root package is `crate`, whatever its manifest names it.
        assert!(module_files("demo", &members).is_empty());
    }

    #[test]
    fn a_module_no_path_can_spell_has_no_files() {
        assert!(module_files("crate::", &[]).is_empty());
        assert!(module_files("a::..::b", &[]).is_empty());
        assert!(module_files("a/b", &[]).is_empty());
    }

    #[test]
    fn a_mod_declaration_loads_the_file_rust_would() {
        let layout = layout();
        let child = |declaring: &str, inline: &[&str], name: &str, path: Option<&str>| {
            let inline: Vec<String> = inline.iter().map(|s| (*s).to_owned()).collect();
            layout.child_file(declaring, &inline, name, path)
        };
        assert_eq!(
            Some("src/cli.rs".to_owned()),
            child("src/main.rs", &[], "cli", None)
        );
        assert_eq!(
            Some("src/net/http.rs".to_owned()),
            child("src/net.rs", &[], "http", None)
        );
        assert_eq!(
            Some("src/net/http.rs".to_owned()),
            child("src/net/mod.rs", &[], "http", None)
        );
        assert_eq!(
            Some("tests/common.rs".to_owned()),
            child("tests/it.rs", &[], "common", None)
        );
        assert_eq!(
            Some("src/bin/aid.rs".to_owned()),
            child("src/bin/tool.rs", &[], "aid", None)
        );
        assert_eq!(
            Some("src/bin/tool/aid.rs".to_owned()),
            child("src/bin/tool/main.rs", &[], "aid", None)
        );
        assert_eq!(
            Some("crates/core-lib/src/x.rs".to_owned()),
            child("crates/core-lib/src/lib.rs", &[], "x", None)
        );
        assert_eq!(
            Some("src/net/inner/deep.rs".to_owned()),
            child("src/net.rs", &["inner"], "deep", None)
        );
        assert_eq!(
            Some("src/impl_x.rs".to_owned()),
            child("src/net.rs", &[], "x", Some("impl_x.rs"))
        );
        assert_eq!(
            Some("src/net/inner/x.rs".to_owned()),
            child("src/net.rs", &["inner"], "x", Some("x.rs"))
        );
        assert_eq!(
            Some("shared/x.rs".to_owned()),
            child("src/lib.rs", &[], "x", Some("../shared/x.rs"))
        );
        assert_eq!(None, child("src/lib.rs", &[], "x", Some("../../x.rs")));
        assert_eq!(None, child("src/lib.rs", &[], "x", Some("/etc/x.rs")));
    }

    #[test]
    fn a_child_module_is_named_as_its_file_is() {
        let layout = layout();
        let module = |declaring: &str, name: &str| {
            layout
                .child_file(declaring, &[], name, None)
                .map(|file| layout.module_of(&file))
        };
        assert_eq!(Some("crate::cli".to_owned()), module("src/main.rs", "cli"));
        assert_eq!(
            Some("tests::common".to_owned()),
            module("tests/it.rs", "common")
        );
        assert_eq!(
            Some("crate::nested::lib".to_owned()),
            module("src/nested.rs", "lib")
        );
        assert_eq!(
            Some("core_lib::x".to_owned()),
            module("crates/core-lib/src/lib.rs", "x")
        );
    }

    #[test]
    fn a_library_is_named_by_its_graph_root_from_outside() {
        let layout = layout();
        assert_eq!(
            Some("crate::run".to_owned()),
            layout.library_path("demo::run")
        );
        assert_eq!(Some("crate".to_owned()), layout.library_path("demo"));
        assert_eq!(
            Some("core_lib::x".to_owned()),
            layout.library_path("core_lib::x")
        );
        assert_eq!(None, layout.library_path("serde::Serialize"));
    }
}
