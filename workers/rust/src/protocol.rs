//! Serde mirrors of the DTOs under `src/Scanner/Protocol`.
//!
//! Serialisation only. Nothing here knows how a fact is discovered, so the wire
//! contract can be read without reading the scanner.

use std::collections::BTreeMap;

use serde::Serialize;

/// This worker's own semantic version, independent of the core's `version.txt`.
pub const VERSION: &str = "0.1.0";

/// The core protocol version this worker speaks.
pub const PROTOCOL_VERSION: &str = "1.0";

/// The output schema version this worker emits.
pub const OUTPUT_SCHEMA_VERSION: &str = "1.0";

/// The worker's identity, returned from `initialize`.
#[derive(Debug, Serialize)]
pub struct Manifest {
    /// Stable worker id the core matches against.
    pub id: &'static str,
    /// The worker's own semantic version.
    pub version: &'static str,
    /// Protocol version, always `1.0`.
    pub protocol_version: &'static str,
    /// Output schema version, always `1.0`.
    pub output_schema_version: &'static str,
    /// Languages this worker claims.
    pub languages: Vec<&'static str>,
    /// File extensions this worker claims.
    pub file_extensions: Vec<&'static str>,
    /// Optional capabilities a consumer may require.
    pub capabilities: Vec<&'static str>,
}

impl Manifest {
    /// The fixed manifest for this worker.
    #[must_use]
    pub fn new() -> Self {
        Self {
            id: "knossos.rust",
            version: VERSION,
            protocol_version: PROTOCOL_VERSION,
            output_schema_version: OUTPUT_SCHEMA_VERSION,
            languages: vec!["rust"],
            file_extensions: vec!["rs"],
            capabilities: vec!["partial_ast"],
        }
    }
}

impl Default for Manifest {
    fn default() -> Self {
        Self::new()
    }
}

/// Where in the project a fact was observed. Paths are project-relative.
#[derive(Debug, Clone, PartialEq, Eq, PartialOrd, Ord, Serialize)]
pub struct Evidence {
    /// Project-relative path, never absolute and never containing `..`.
    pub path: String,
    /// One-based first line of the construct.
    pub start_line: usize,
    /// One-based last line of the construct.
    pub end_line: usize,
}

/// One declared symbol.
#[derive(Debug, Clone, Serialize)]
pub struct Node {
    /// Contribution-local identifier, referenced by edge endpoints.
    pub local_id: String,
    /// Node kind: `module`, `class`, `interface`, `function`, `method`.
    pub kind: String,
    /// Fully qualified name, stable across scans.
    pub canonical_name: String,
    /// Short name a reader recognises.
    pub display_name: String,
    /// Always `ast` for this worker.
    pub origin: &'static str,
    /// `certain`, `probable`, or `possible`.
    pub confidence: &'static str,
    /// Where the declaration is.
    pub evidence: Evidence,
    /// Extra properties; serialises as `{}` when empty, never `null`.
    pub attributes: BTreeMap<String, serde_json::Value>,
}

/// One relationship between two symbols.
#[derive(Debug, Clone, Serialize)]
pub struct Edge {
    /// Edge kind: `contains`, `imports`, `implements`, `extends`, `calls`.
    pub kind: String,
    /// Source endpoint, a `local_id` or a canonical reference.
    pub source: String,
    /// Target endpoint, a `local_id` or a canonical reference.
    pub target: String,
    /// Always `ast` for this worker.
    pub origin: &'static str,
    /// `certain`, `probable`, or `possible`.
    pub confidence: &'static str,
    /// Where the relationship is expressed.
    pub evidence: Evidence,
    /// Extra properties; serialises as `{}` when empty, never `null`.
    pub attributes: BTreeMap<String, serde_json::Value>,
}

/// A problem encountered while scanning one file.
#[derive(Debug, Clone, Serialize)]
pub struct Diagnostic {
    /// `info`, `warning`, or `error`.
    pub severity: &'static str,
    /// Stable machine-readable code, prefixed `RS_`.
    pub code: String,
    /// Human-readable explanation.
    pub message: String,
    /// Where the problem is, when a location is known.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub evidence: Option<Evidence>,
}

/// One owner's complete set of facts. Re-emitting an owner replaces its facts.
#[derive(Debug, Clone, Serialize)]
pub struct Contribution {
    /// Stable owner key, `knossos.rust:file:<relative path>`.
    pub owner_key: String,
    /// Declared symbols.
    pub nodes: Vec<Node>,
    /// Relationships.
    pub edges: Vec<Edge>,
    /// Problems.
    pub diagnostics: Vec<Diagnostic>,
}
