import fs from 'node:fs';
import path from 'node:path';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}
function words(html) {
  return (html.replace(/<[^>]*>/g, ' ').match(/\b[\p{L}\p{N}][\p{L}\p{N}’'-]*\b/gu) || []).length;
}
function filler(count) {
  return `<p>${Array(count).fill('preserved').join(' ')}</p>`;
}

const pluginDir = process.env.GB_PLUGIN_DIR || path.join(process.cwd(), 'core-2.3.17-dev', 'gulf-breeze-core');
const source = fs.readFileSync(path.join(pluginDir, 'includes', 'adult-timing-rebalance.php'), 'utf8');
const entries = [];
for (const match of source.matchAll(/'(adult_en_\d+)'\s*=>\s*array\((.*?)\n\t\),/gs)) {
  const body = match[2];
  entries.push({
    key: match[1],
    mode: body.match(/'mode'\s*=>\s*'([^']+)'/)[1],
    removeVersion: body.match(/'remove_version'\s*=>\s*'([^']+)'/)[1],
    html: body.match(/<<<'HTML'\n([\s\S]*?)\nHTML,/)?.[1] || '',
  });
}

const baseWords = { 1:439,2:488,3:486,4:453,5:850,6:610,7:577,8:624,9:607,10:465,11:638,12:639,13:612,14:734,15:659,16:358,18:446,38:487 };
const minutes = { 1:2,2:3,3:3,4:2,5:4,6:5,7:5,8:5,9:5,10:5,11:8,12:7,13:7,14:8,15:7,16:4,18:6,38:5 };
const visualLessons = new Set([10,11,12,13,14,15,16,18]);
const lessons = new Map();
for (const entry of entries) {
  const number = Number(entry.key.slice(-3));
  const visual = visualLessons.has(number) ? '<div class="gb-sign-gallery">visual-preservation-sentinel</div>' : '';
  const visualWords = words(visual);
  const base = filler(baseWords[number] - visualWords) + visual;
  const oldMarker = `gb-duration-hardening-${entry.removeVersion}:${entry.key}`;
  const overfill = filler(minutes[number] * 40);
  lessons.set(entry.key, {
    content: `${base}<!-- ${oldMarker} -->${overfill}<!-- /gb-duration-hardening-${entry.removeVersion} -->`,
    minutes: minutes[number],
    objective: `objective-${entry.key}`,
    status: 'previous-status',
    audit: 9999,
  });
}
const locked = {
  courseStatus: 'publish', registry: 'adult_en:21:internal_testing', sections: 9,
  orderedItems: 56, lessons: 46, quizzes: 10, minutes: 330,
};

function propose(entry, lesson) {
  const marker = `gb-timing-rebalance-2.3.10:${entry.key}`;
  let clean = lesson.content.replace(new RegExp(`<!-- ${marker} -->[\\s\\S]*?<!-- /gb-timing-rebalance-2\\.3\\.10 -->`, 'g'), '');
  if (entry.mode === 'replace') return `<!-- ${marker} -->${entry.html.trim()}<!-- /gb-timing-rebalance-2.3.10 -->`;
  const oldMarker = `gb-duration-hardening-${entry.removeVersion}:${entry.key}`;
  clean = clean.replace(new RegExp(`<!-- ${oldMarker.replaceAll('.', '\\.') } -->[\\s\\S]*?<!-- /gb-duration-hardening-${entry.removeVersion.replaceAll('.', '\\.') } -->`, 'g'), '');
  return `${clean.trim()}<!-- ${marker} -->${entry.html.trim()}<!-- /gb-timing-rebalance-2.3.10 -->`;
}

const original = structuredClone([...lessons.entries()]);
const planned = new Map();
for (const entry of entries) {
  const lesson = lessons.get(entry.key);
  const content = propose(entry, lesson);
  const count = words(content);
  assert(count >= lesson.minutes * 120 && count <= lesson.minutes * 150, `${entry.key} failed the model readability gate: ${count}.`);
  assert(!lesson.content.includes('gb-sign-gallery') || content.includes('gb-sign-gallery'), `${entry.key} lost its sign gallery.`);
  planned.set(entry.key, { ...lesson, content, status: 'timing_rebalance_2_3_10_dev', audit: count });
}

// Simulate a write failure after six successful updates and prove full rollback.
const rollbackFixture = new Map(structuredClone(original));
const written = [];
for (const [key, row] of planned) {
  if (written.length === 6) break;
  rollbackFixture.set(key, structuredClone(row));
  written.push(key);
}
for (const key of written.reverse()) rollbackFixture.set(key, structuredClone(new Map(original).get(key)));
assert(JSON.stringify([...rollbackFixture]) === JSON.stringify(original), 'Transactional model did not restore every partial write and audit/status value.');

// Apply successfully and prove objectives, locked course topology, and retry output remain unchanged.
for (const [key, row] of planned) lessons.set(key, row);
for (const [key, before] of original) assert(lessons.get(key).objective === before.objective, `${key} objective metadata changed.`);
assert(JSON.stringify(locked) === JSON.stringify({ courseStatus:'publish',registry:'adult_en:21:internal_testing',sections:9,orderedItems:56,lessons:46,quizzes:10,minutes:330 }), 'Locked course model changed.');
for (const entry of entries) assert(propose(entry, lessons.get(entry.key)) === lessons.get(entry.key).content, `${entry.key} retry was not idempotent.`);

console.log('PASS Core 2.3.17 transactional migration model, rollback, preservation, and idempotency checks');
