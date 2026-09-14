/**
 * Serialized bytes one `scan/input_hashes` notification carries at most, well
 * under the core's 1,000,000-byte line cap. A single entry longer than this
 * still travels alone, and an entry is bounded by the core's path rules, so no
 * frame this produces approaches the cap.
 */
export const INPUT_HASHES_PART_BYTES = 256_000;

/**
 * Split a request's `input_hashes` map into parts that each fit one frame.
 *
 * The map covers every project file a request's programs read, which for a
 * large program outgrows the one line a scan result travels on however few
 * files the batch names. All parts but the last go out as `scan/input_hashes`
 * notifications; the last is the result's own field, which marks that the
 * worker finished reporting, so there is always at least one part, `{}` when
 * nothing was read.
 *
 * @param {Record<string, string|null>} inputHashes
 * @param {number} [partBytes]
 * @returns {Array<Record<string, string|null>>}
 */
export function inputHashesParts(
    inputHashes,
    partBytes = INPUT_HASHES_PART_BYTES,
) {
    const parts = [];
    let part = {};
    let entries = 0;
    // The serialized part: its braces, less the comma its last entry lacks.
    let bytes = 1;
    for (const [relative, hash] of Object.entries(inputHashes)) {
        // `"path":"<64 hex>",` or `"path":null,`
        const entryBytes =
            Buffer.byteLength(JSON.stringify(relative)) +
            (hash === null ? 4 : 66) +
            2;
        if (entries > 0 && bytes + entryBytes > partBytes) {
            parts.push(part);
            part = {};
            entries = 0;
            bytes = 1;
        }
        part[relative] = hash;
        entries += 1;
        bytes += entryBytes;
    }
    parts.push(part);
    return parts;
}
