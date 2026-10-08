//! Which files could hold the declarations of a module.
//!
//! The inverse of [`crate::resolve::module_path_in_crate`]: a file's module
//! path follows from where it sits, so the files that could declare a name in
//! a module are the files that would sit at that module's path. A walk that
//! asked the declaration index about a name depends on every one of them,
//! whether it exists or not: an edit to one, its deletion, or a file added at
//! one of those paths can change the answer.

/// A Cargo package that names its crate: the directory its manifest sits in
/// (empty for the project root, otherwise ending in `/`) and the crate name as
/// Rust code spells it (`core_lib` for a package named `core-lib`).
pub type Package = (String, String);

/// The files a crate's `src/` keeps for a module `rest` segments below its
/// root, relative to `src` itself. The convention pops a trailing `mod`, then
/// a trailing `lib` or `main`, so each of these lands on the same module.
const MODULE_FILES: [&str; 6] = [
    ".rs",
    "/mod.rs",
    "/lib.rs",
    "/main.rs",
    "/lib/mod.rs",
    "/main/mod.rs",
];

/// The files a crate's `src/` keeps for its root module.
const ROOT_FILES: [&str; 5] = [
    "src/lib.rs",
    "src/main.rs",
    "src/mod.rs",
    "src/lib/mod.rs",
    "src/main/mod.rs",
];

/// Every project-relative path whose file would be placed in `module`, under
/// each reading of its first segment, whether the file exists or not.
///
/// A path outside any `src/` keeps its directory chain (`tests::smoke` is
/// `tests/smoke.rs`). `crate` is the root package's `src/`, and a workspace
/// member's crate name is that member's `src/`. A package whose roots are
/// missing is still listed: adding its `src/lib.rs` makes it a crate, and a
/// file that asked about the crate's names must hear of it.
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
    let chain = segments.join("/");
    let mut files = vec![format!("{chain}.rs"), format!("{chain}/mod.rs")];
    let roots = std::iter::once(("", "crate")).chain(
        members
            .iter()
            .filter(|(directory, _)| !directory.is_empty())
            .map(|(directory, name)| (directory.as_str(), name.as_str())),
    );
    for (directory, name) in roots {
        if segments[0] != name {
            continue;
        }
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
    use super::{module_files, modules_above};
    use crate::resolve::module_path_in_crate;

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
            for file in files.iter().filter(|file| file.starts_with("src/")) {
                assert_eq!(module, module_path_in_crate(file, "crate", false), "{file}");
            }
        }
        assert!(module_files("crate", &[]).contains(&"src/lib.rs".to_owned()));
        assert!(module_files("crate::engine", &[]).contains(&"src/engine/mod.rs".to_owned()));
        assert!(module_files("crate::engine", &[]).contains(&"crate/engine.rs".to_owned()));
    }

    #[test]
    fn a_path_outside_src_keeps_its_directory_chain() {
        let files = module_files("tests::smoke", &[]);
        assert_eq!(vec!["tests/smoke.rs", "tests/smoke/mod.rs"], files);
        for file in files {
            assert_eq!("tests::smoke", module_path_in_crate(&file, "crate", false));
        }
    }

    #[test]
    fn a_member_crate_name_reads_as_that_members_src() {
        let members = [
            (String::new(), "demo".to_owned()),
            ("crates/core-lib/".to_owned(), "core_lib".to_owned()),
        ];
        assert!(
            module_files("core_lib", &members).contains(&"crates/core-lib/src/lib.rs".to_owned())
        );
        assert!(module_files("core_lib::x", &members)
            .contains(&"crates/core-lib/src/x/mod.rs".to_owned()));
        // The root package is `crate`, whatever its manifest names it.
        assert!(!module_files("demo", &members)
            .iter()
            .any(|file| file.starts_with("src/")));
    }

    #[test]
    fn a_module_no_path_can_spell_has_no_files() {
        assert!(module_files("crate::", &[]).is_empty());
        assert!(module_files("a::..::b", &[]).is_empty());
        assert!(module_files("a/b", &[]).is_empty());
    }
}
