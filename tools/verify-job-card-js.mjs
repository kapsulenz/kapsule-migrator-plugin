/**
 * THE BROWSER HALF OF THE SAME RULE: one phase value drives the badge, the heading and the body, and
 * a status read that FAILS moves nothing at all.
 *
 * WHY THIS EXISTS SEPARATELY FROM THE PHP CHECK. The PHP paints the card once. Everything a customer
 * reads after that comes from this file, and the 2026-08-31 defect lived in the seam between them:
 * the badge was correct at page load and then never changed for twenty minutes, because the poll
 * updated the number and could not reach the words.
 *
 * The 1.5.7 release had already tried to fix that and the fix was INERT. It called
 * `$('#km-chip-label').text(...)`, which is correct code aimed at an id that existed only on the
 * UPLOAD card. On the job card it matched nothing, and jQuery is silent about a selector that matches
 * nothing, so the line ran on every poll and did nothing forever. Nothing anywhere went red.
 *
 * THAT IS WHAT THIS HARNESS IS FOR: it counts writes PER SELECTOR against a DOM that declares which
 * ids the card really has, so a write to an id that is not on the card is not a pass, it is a miss.
 *
 * Run:  node tools/verify-job-card-js.mjs
 *       node tools/verify-job-card-js.mjs --self-test    (must go red on the pre-fix source)
 */
import { readFileSync, mkdtempSync, writeFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const PRE_FIX_REF = '115ad10';

/*
 * THE IDS THE JOB CARD ACTUALLY CARRIES, read out of the PHP rather than listed here.
 *
 * A hand-written list is a guess about the markup that goes quietly wrong the day somebody edits the
 * template, which is exactly the failure being tested. This parses the awaiting-import card's own
 * markup out of `render_job_outcome`, so the DOM this harness offers the JS is the DOM the plugin
 * emits. If the card stops carrying an id, the write to it stops landing here too.
 */
function cardIdsFromPhp(phpPath) {
  const php = readFileSync(phpPath, 'utf8');
  const start = php.indexOf('// PENDING, RUNNING, PAUSED, NO_JOB');
  const alt = php.indexOf('PENDING, RUNNING, PAUSED, NO_JOB');
  const from = start >= 0 ? start : alt;
  if (from < 0) throw new Error('BLIND: could not find the running-job card in ' + phpPath);
  const body = php.slice(from, php.indexOf('render_complete_card', from));
  return new Set([...body.matchAll(/id="([a-z0-9-]+)"/g)].map(m => m[1]));
}

/** A jQuery small enough to be honest: it only pretends to find ids the card really has. */
function makeJQuery(ids, log) {
  const nodes = new Map();
  for (const id of ids) nodes.set(id, { text: '', attrs: {}, shown: true });

  function node(sel) {
    const id = sel.startsWith('#') ? sel.slice(1) : null;
    const present = id !== null && nodes.has(id);
    const rec = present ? nodes.get(id) : null;
    const api = {
      length: present ? 1 : 0,
      text(v) {
        if (v === undefined) return rec ? rec.text : '';
        log.push({ sel, op: 'text', v, hit: present });
        if (rec) rec.text = String(v);
        return api;
      },
      attr(k, v) {
        if (v === undefined) return rec ? rec.attrs[k] : undefined;
        log.push({ sel, op: 'attr:' + k, v, hit: present });
        if (rec) rec.attrs[k] = String(v);
        return api;
      },
      css() { log.push({ sel, op: 'css', hit: present }); return api; },
      html(v) { if (v !== undefined) log.push({ sel, op: 'html', v, hit: present }); return api; },
      show() { log.push({ sel, op: 'show', hit: present }); if (rec) rec.shown = true; return api; },
      hide() { log.push({ sel, op: 'hide', hit: present }); if (rec) rec.shown = false; return api; },
      each() { return api; },
      find() { return api; },
      on() { return api; },
      prop() { return api; },
      focus() { return api; },
      val() { return ''; },
      remove() { return api; },
      prependTo() { return api; },
      addClass() { return api; },
      removeClass() { return api; },
    };
    return api;
  }

  const $ = (sel) => (typeof sel === 'function' ? sel() : node(String(sel)));
  $.post = () => ({ done: () => ({ fail: () => {} }), fail: () => ({}), always: () => ({}) });
  $.getJSON = () => ({ done: () => ({ fail: () => {} }) });
  $.trim = (s) => String(s || '').trim();
  return { $, nodes };
}

/**
 * Load the plugin JS with a fake jQuery and a fake wp.i18n, then hand back the two entry points the
 * job card uses. The file is an IIFE, so the functions are reached by evaluating it inside a wrapper
 * that captures them; nothing about the source is rewritten.
 */
function loadPlugin(jsPath, phpPath, cfg) {
  const ids = cardIdsFromPhp(phpPath);
  const log = [];
  const { $, nodes } = makeJQuery(ids, log);

  const src = readFileSync(jsPath, 'utf8');
  const wrapper = `
    (function (jQuery, window, captured) {
      ${src}
      // Reach into the closure by re-declaring nothing: the plugin defines these as function
      // declarations at IIFE scope, so they are visible to the appended code below.
      try { captured.pollJob = pollJob; } catch (e) {}
      try { captured.applyJobPhase = applyJobPhase; } catch (e) {}
      try { captured.showJobRetry = showJobRetry; } catch (e) {}
    })
  `;
  // The appended lines have to run INSIDE the IIFE, so the source is spliced before its closing
  // paren rather than concatenated after it. Everything else is untouched.
  const closing = src.lastIndexOf('})(jQuery);');
  if (closing < 0) throw new Error('BLIND: could not find the IIFE tail in ' + jsPath);
  const spliced =
    src.slice(0, closing) +
    `\n  try { window.__captured.pollJob = pollJob; } catch (e) {}\n` +
    `  try { window.__captured.applyJobPhase = applyJobPhase; } catch (e) {}\n` +
    `  try { window.__captured.showJobRetry = showJobRetry; } catch (e) {}\n` +
    src.slice(closing);

  const captured = {};
  const win = {
    kapsuleMigrator: cfg,
    __captured: captured,
    wp: { i18n: { __: (s) => s, _n: (s, p, n) => (n === 1 ? s : p), sprintf: (f, ...a) => {
      let i = 0; return String(f).replace(/%(\d+\$)?[sd]/g, (m, pos) => (pos ? a[parseInt(pos, 10) - 1] : a[i++]));
    } } },
    location: { reload() { log.push({ sel: 'window', op: 'reload', hit: true }); } },
  };
  const fn = new Function('jQuery', 'window', 'document', 'setTimeout', 'Date', spliced);
  fn($, win, { }, () => {}, Date);
  return { captured, log, nodes, ids };
}

// ── Drive it ─────────────────────────────────────────────────────────────────────────────────────

const selfTest = process.argv[2] === '--self-test';
let jsPath = join(ROOT, 'assets/js/migrator.js');
let phpPath = join(ROOT, 'admin/class-admin-page.php');

if (selfTest) {
  const dir = mkdtempSync(join(tmpdir(), 'km-js-prefix-'));
  for (const [rel, out] of [['assets/js/migrator.js', 'migrator.js'], ['admin/class-admin-page.php', 'card.php']]) {
    const body = execFileSync('git', ['show', `${PRE_FIX_REF}:${rel}`], { cwd: ROOT, encoding: 'utf8' });
    writeFileSync(join(dir, out), body);
  }
  jsPath = join(dir, 'migrator.js');
  phpPath = join(dir, 'card.php');
  console.log(`SELF-TEST: driving the PRE-FIX browser code at ${PRE_FIX_REF}`);
}

console.log(`  subject js:  ${jsPath}`);
console.log(`  subject php: ${phpPath}`);

const JOB_COPY = {
  '':            { badge: 'Working',                 head: 'KapsuleHost is putting your site together', body: 'generic body' },
  preflight:     { badge: 'Checking the connection',  head: 'KapsuleHost is checking what arrived',      body: 'preflight body' },
  importing_db:  { badge: 'Importing your database',  head: 'KapsuleHost is importing your database',    body: 'importing body' },
};

const failures = [];
const fail = (m) => failures.push(m);

const cfg = { status: 'awaiting_import', ajaxUrl: '/x', nonce: 'n', maxAttempts: 5, jobCopy: JOB_COPY };
const { captured, log, nodes, ids } = loadPlugin(jsPath, phpPath, cfg);

console.log(`  ids on the job card: ${[...ids].join(', ') || '(none)'}`);

/*
 * CHECK A. The card carries the three ids the poll must be able to write to.
 *
 * This is the assertion that would have caught the inert 1.5.7 fix. It is about the MARKUP, checked
 * from the JS side, because a selector and a template are only correct together.
 */
for (const id of ['km-chip-label', 'km-head', 'kapsule-status-text', 'km-job-retry-text']) {
  if (!ids.has(id)) fail(`CHECK A: the job card has no #${id}, so nothing can update that field`);
}

/*
 * CHECK B. One call writes all three, and every write LANDS.
 */
if (typeof captured.applyJobPhase !== 'function') {
  fail('CHECK B: there is no applyJobPhase(), so no single call sets the badge, heading and body');
} else {
  log.length = 0;
  captured.applyJobPhase('importing_db');
  const wrote = (sel) => log.some((e) => e.sel === sel && e.op === 'text' && e.hit);
  for (const [sel, want] of [
    ['#km-chip-label', 'Importing your database'],
    ['#km-head', 'KapsuleHost is importing your database'],
    ['#kapsule-status-text', 'importing body'],
  ]) {
    if (!wrote(sel)) fail(`CHECK B: applyJobPhase did not land a write on ${sel}`);
    else {
      const got = log.filter((e) => e.sel === sel && e.op === 'text').pop().v;
      if (got !== want) fail(`CHECK B: ${sel} got ${JSON.stringify(got)}, expected ${JSON.stringify(want)}`);
    }
  }
  // And a second phase must move all three, which is what proves none of them is a constant.
  log.length = 0;
  captured.applyJobPhase('preflight');
  for (const sel of ['#km-chip-label', '#km-head', '#kapsule-status-text']) {
    if (!wrote(sel)) fail(`CHECK B: changing the phase did not rewrite ${sel}`);
  }
}

/*
 * CHECK C. A FAILED READ MOVES NOTHING. This is Jesse's second test in one assertion: could the
 * customer think something is happening when it is not.
 */
if (typeof captured.showJobRetry !== 'function') {
  fail('CHECK C: there is no showJobRetry(), so a failed read has nowhere to go but over the step wording');
} else {
  // Put the card into a known good state first.
  if (captured.applyJobPhase) captured.applyJobPhase('importing_db');
  const before = {
    chip: nodes.get('km-chip-label')?.text,
    head: nodes.get('km-head')?.text,
    lede: nodes.get('kapsule-status-text')?.text,
    pct:  nodes.get('km-job-pct')?.text,
    note: nodes.get('km-job-note')?.text,
  };
  log.length = 0;
  captured.showJobRetry('KapsuleHost did not answer in time. We are checking again in a moment.');

  for (const [key, id] of [['chip', 'km-chip-label'], ['head', 'km-head'], ['lede', 'kapsule-status-text'],
                           ['pct', 'km-job-pct'], ['note', 'km-job-note']]) {
    const now = nodes.get(id)?.text;
    if (now !== before[key]) {
      fail(`CHECK C: a failed read changed #${id} from ${JSON.stringify(before[key])} to ${JSON.stringify(now)}`);
    }
  }
  if (nodes.get('km-job-retry-text')?.text === '') fail('CHECK C: the retry line was left empty');
  if (nodes.get('km-rail')?.attrs['data-live'] !== '0') fail('CHECK C: the rail still claims bytes are moving');
}

/*
 * CHECK D. The file carries no second phase-label table.
 */
const srcNow = readFileSync(jsPath, 'utf8');
const tableRows = (srcNow.match(/^\s*(?:preflight|importing_db|search_replace|placing_files):\s*__\(/gm) || []).length;
if (tableRows > 0) {
  fail(`CHECK D: the JS still declares its own phase-label table (${tableRows} rows). One table, in the PHP.`);
}

if (failures.length) {
  console.log(`\n  RED (${failures.length})`);
  for (const f of failures) console.log(`    - ${f}`);
  if (selfTest) {
    console.log('\nSELF-TEST PASSED: the browser checks go red on the code that shipped the defect.');
    process.exit(0);
  }
  process.exit(1);
}

if (selfTest) {
  console.log('\nBLIND: the pre-fix browser code PASSED these checks. They cannot see the defect they exist for.');
  process.exit(2);
}
console.log('\n  GREEN: one call writes all three fields, and a failed read moves nothing.');
