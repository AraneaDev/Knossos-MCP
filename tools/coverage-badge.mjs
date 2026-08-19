#!/usr/bin/env node
/**
 * Print a shields.io endpoint badge for the current coverage run.
 *
 * The badge is self-hosted: CI publishes this JSON to the `gh-pages` branch and the README
 * points shields at it. No third-party coverage account is involved, so the number can never
 * silently go stale behind an unconfigured integration the way a Codecov badge reads
 * "unknown" until someone activates the repository.
 *
 * Knossos is three languages in one repository, so the badge sums them rather than reporting
 * whichever one is easiest to read. PHP is the bulk of the code; a badge showing only the
 * Node and Python workers would look like a project number while describing a small corner of
 * it. Every source is a line count, so they add up directly:
 *
 *   - PHP    `coverage/php/summary.json`            (tools/pcov-report.php)
 *   - JS     `coverage/js/coverage-summary.json`    (c8 --reporter=json-summary)
 *   - Python `coverage/python/coverage.json`        (coverage json)
 *
 * A missing or unreadable source is a hard failure, not a skipped term. Silently dropping one
 * would publish a number that looks like whole-project coverage while quietly excluding a
 * language, which is the exact failure this repository keeps finding in other people's badges.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const ROOT = process.cwd();

/** Shields' own scale, so the colour means the same thing here as on any other badge. */
function colourFor(pct) {
  if (pct >= 90) return 'brightgreen';
  if (pct >= 80) return 'green';
  if (pct >= 70) return 'yellowgreen';
  if (pct >= 60) return 'yellow';
  if (pct >= 50) return 'orange';
  return 'red';
}

function read(relative) {
  const path = join(ROOT, relative);
  try {
    return JSON.parse(readFileSync(path, 'utf8'));
  } catch (error) {
    process.stderr.write(`coverage-badge: cannot read ${path}: ${error.message}\n`);
    process.stderr.write('Run tools/coverage first; it writes all three language summaries.\n');
    process.exit(1);
  }
}

/** @returns {[covered: number, valid: number]} */
function linesOf(label, covered, valid) {
  if (typeof covered !== 'number' || typeof valid !== 'number') {
    process.stderr.write(`coverage-badge: ${label} summary has no usable line counts\n`);
    process.exit(1);
  }
  return [covered, valid];
}

const php = read('coverage/php/summary.json');
const js = read('coverage/js/coverage-summary.json');
const python = read('coverage/python/coverage.json');

const parts = [
  linesOf('php', php.lines?.covered, php.lines?.valid),
  linesOf('js', js.total?.lines?.covered, js.total?.lines?.total),
  linesOf('python', python.totals?.covered_lines, python.totals?.num_statements),
];

const covered = parts.reduce((sum, [c]) => sum + c, 0);
const valid = parts.reduce((sum, [, v]) => sum + v, 0);
if (valid === 0) {
  process.stderr.write('coverage-badge: no measurable lines across the three languages\n');
  process.exit(1);
}

const pct = Math.round(((100 * covered) / valid) * 10) / 10;
process.stdout.write(
  `${JSON.stringify({
    schemaVersion: 1,
    label: 'coverage',
    message: `${pct}%`,
    color: colourFor(pct),
  })}\n`,
);
