//! The nesting guard that runs before a file is parsed.
//!
//! A cheap byte scan measures how deep a file's delimiters nest, so a file
//! nested far past real code is refused with a diagnostic instead of
//! overflowing the stack of the recursive parser.

/// Deepest delimiter nesting a file may have before it is not parsed.
///
/// Parsing, walking and dropping a syntax tree recurse once per level, and
/// running out of stack aborts the process instead of unwinding, so a file
/// nested far past real code would take every other file of the scan with it.
/// The pre-scan counts `(`, `[` and `{` only. Other recursion (generic angle
/// brackets, unary chains, long `else if` chains) is covered by the 64 MB
/// worker stack, not by the pre-scan.
pub(crate) const MAX_NESTING: usize = 256;

/// The depth of `(`, `[` and `{` nesting in `source` once it passes `limit`,
/// or `None` while it stays within it.
///
/// A small state machine skips comments (block comments nest), string, raw
/// string and character literals, and lifetimes, so delimiters inside them do
/// not count. It may over-count on odd input; the limit sits far above real code.
pub(crate) fn nesting_beyond(source: &str, limit: usize) -> Option<usize> {
    let bytes = source.as_bytes();
    let mut depth = 0usize;
    let mut i = 0;
    while i < bytes.len() {
        match bytes[i] {
            b'(' | b'[' | b'{' => {
                depth += 1;
                if depth > limit {
                    return Some(depth);
                }
            }
            b')' | b']' | b'}' => depth = depth.saturating_sub(1),
            b'/' if bytes.get(i + 1) == Some(&b'/') => {
                while i < bytes.len() && bytes[i] != b'\n' {
                    i += 1;
                }
                continue;
            }
            b'/' if bytes.get(i + 1) == Some(&b'*') => {
                let mut level = 1usize;
                i += 2;
                while i < bytes.len() && level > 0 {
                    if bytes[i] == b'/' && bytes.get(i + 1) == Some(&b'*') {
                        level += 1;
                        i += 2;
                    } else if bytes[i] == b'*' && bytes.get(i + 1) == Some(&b'/') {
                        level -= 1;
                        i += 2;
                    } else {
                        i += 1;
                    }
                }
                continue;
            }
            b'"' => {
                i = skip_quoted(bytes, i + 1, b'"');
                continue;
            }
            b'r' if is_raw_string_start(bytes, i) => {
                let mut hashes = 0;
                let mut j = i + 1;
                while bytes.get(j) == Some(&b'#') {
                    hashes += 1;
                    j += 1;
                }
                // `j` is the opening quote; look for a quote plus as many hashes.
                j += 1;
                while j < bytes.len() {
                    if bytes[j] == b'"' && (1..=hashes).all(|k| bytes.get(j + k) == Some(&b'#')) {
                        j += 1 + hashes;
                        break;
                    }
                    j += 1;
                }
                i = j;
                continue;
            }
            b'\'' => {
                let next = bytes.get(i + 1).copied();
                let is_lifetime = next.is_some_and(|c| c == b'_' || c.is_ascii_alphabetic())
                    && bytes.get(i + 2) != Some(&b'\'');
                if !is_lifetime {
                    i = skip_quoted(bytes, i + 1, b'\'');
                    continue;
                }
            }
            _ => {}
        }
        i += 1;
    }
    None
}

/// Whether the `r` at `at` opens a raw string (`r"`, `r#"`), not an identifier.
fn is_raw_string_start(bytes: &[u8], at: usize) -> bool {
    let after_ident = at > 0 && (bytes[at - 1] == b'_' || bytes[at - 1].is_ascii_alphanumeric());
    // A `b` prefix (`br"..."`) still opens a raw string.
    if after_ident && !(bytes[at - 1] == b'b' && !(at > 1 && is_ident_byte(bytes[at - 2]))) {
        return false;
    }
    let mut j = at + 1;
    while bytes.get(j) == Some(&b'#') {
        j += 1;
    }
    bytes.get(j) == Some(&b'"')
}

/// Whether `byte` can be part of an identifier.
fn is_ident_byte(byte: u8) -> bool {
    byte == b'_' || byte.is_ascii_alphanumeric()
}

/// The index after the `quote` that closes a literal whose body starts at
/// `from`, honouring backslash escapes. An unterminated literal ends the source.
fn skip_quoted(bytes: &[u8], from: usize, quote: u8) -> usize {
    let mut i = from;
    while i < bytes.len() {
        match bytes[i] {
            b'\\' => i += 2,
            byte if byte == quote => return i + 1,
            _ => i += 1,
        }
    }
    bytes.len()
}

#[cfg(test)]
mod tests {
    use super::nesting_beyond;

    /// Nesting limit low enough to write each case by hand.
    const LIMIT: usize = 3;

    #[test]
    fn the_limit_itself_passes_and_one_past_it_trips() {
        assert_eq!(None, nesting_beyond("(((x)))", LIMIT));
        assert_eq!(Some(4), nesting_beyond("((((x))))", LIMIT));
        assert_eq!(Some(4), nesting_beyond("{[({x})]}", LIMIT));
    }

    #[test]
    fn a_lifetime_is_not_a_character_literal() {
        assert_eq!(None, nesting_beyond("fn f<'a>(x:&'a str){((x))}", LIMIT));
        assert_eq!(Some(4), nesting_beyond("fn f<'a>(((( x))))", LIMIT));
    }

    #[test]
    fn character_literals_hide_their_delimiters() {
        let source = "fn f(){ let a='{'; let b='\\''; let c='\"'; ((x)) }";
        assert_eq!(None, nesting_beyond(source, LIMIT));
        assert_eq!(Some(4), nesting_beyond("{ let a='x'; ((((x)))) }", LIMIT));
    }

    #[test]
    fn string_literals_hide_their_delimiters() {
        assert_eq!(None, nesting_beyond("{ let s = \"\\\"((((\"; (x) }", LIMIT));
        assert_eq!(Some(4), nesting_beyond("{ let s = \"a\"; (((x))) }", LIMIT));
    }

    #[test]
    fn raw_strings_hide_their_delimiters() {
        assert_eq!(None, nesting_beyond("{ r#\"((\"((\"#; (x) }", LIMIT));
        assert_eq!(None, nesting_beyond("{ br\"((((\"; (x) }", LIMIT));
        assert_eq!(Some(4), nesting_beyond("{ r#\"a\"#; (((x))) }", LIMIT));
    }

    #[test]
    fn an_identifier_r_does_not_open_a_raw_string() {
        assert_eq!(
            Some(4),
            nesting_beyond("{ let r = 1; let var = r; (((x))) }", LIMIT)
        );
    }

    #[test]
    fn comments_hide_their_delimiters_and_block_comments_nest() {
        assert_eq!(None, nesting_beyond("{ // ((((\n (x) }", LIMIT));
        assert_eq!(None, nesting_beyond("{ /* ((((( */ (x) }", LIMIT));
        assert_eq!(None, nesting_beyond("{ /* /* ((( */ ((( */ (x) }", LIMIT));
        assert_eq!(Some(4), nesting_beyond("/* a */ ((((x))))", LIMIT));
    }

    #[test]
    fn unbalanced_closers_do_not_go_below_zero() {
        assert_eq!(None, nesting_beyond("))))))(((x)))", LIMIT));
        assert_eq!(None, nesting_beyond("}}}}{((x))", LIMIT));
    }
}
