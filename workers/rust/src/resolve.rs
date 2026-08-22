//! Turning a file path into the Rust module path it declares.
//!
//! This is a path convention, not a resolution of `mod` declarations: the worker
//! sees one file per call and has no crate-wide view. The convention is Rust's
//! own, so it agrees with the compiler for every layout that follows it.

/// The canonical module path a source file declares.
///
/// `src/` is the crate root, so `src/lib.rs` and `src/main.rs` are `crate` itself
/// and `src/net/http.rs` is `crate::net::http`. A `mod.rs` collapses into the
/// directory that holds it. A path outside `src/` keeps its own directory chain,
/// which is what `tests/` and `benches/` want: each of those files is its own
/// crate root to the compiler, and pretending otherwise would collide their
/// symbols with the library's.
#[must_use]
pub fn module_path(relative: &str) -> String {
    let trimmed = relative.strip_suffix(".rs").unwrap_or(relative);
    let mut segments: Vec<&str> = trimmed.split('/').filter(|part| !part.is_empty()).collect();
    let in_crate = segments.first() == Some(&"src");
    if in_crate {
        segments.remove(0);
    }
    if matches!(segments.last(), Some(&"mod")) {
        segments.pop();
    }
    if in_crate && matches!(segments.last(), Some(&"lib") | Some(&"main")) {
        segments.pop();
    }
    if in_crate {
        segments.insert(0, "crate");
    }
    if segments.is_empty() {
        return "crate".to_owned();
    }

    segments.join("::")
}

#[cfg(test)]
mod tests {
    use super::module_path;

    #[test]
    fn lib_and_main_collapse_to_the_crate_root() {
        assert_eq!("crate", module_path("src/lib.rs"));
        assert_eq!("crate", module_path("src/main.rs"));
    }

    #[test]
    fn a_plain_file_becomes_a_child_of_the_crate() {
        assert_eq!("crate::greeting", module_path("src/greeting.rs"));
        assert_eq!("crate::net::http", module_path("src/net/http.rs"));
    }

    #[test]
    fn mod_rs_collapses_into_its_directory() {
        assert_eq!("crate::net", module_path("src/net/mod.rs"));
    }

    #[test]
    fn a_path_outside_src_keeps_its_directory_chain() {
        assert_eq!("tests::integration", module_path("tests/integration.rs"));
    }
}
