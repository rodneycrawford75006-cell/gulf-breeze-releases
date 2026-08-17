import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const pluginDir = process.env.GB_PLUGIN_DIR || path.join(root, 'core-2.3.17-dev', 'gulf-breeze-core');
const mainFile = path.join(pluginDir, 'gulf-breeze-core.php');
const sourceFile = path.join(pluginDir, 'includes', 'adult-timing-rebalance.php');
const readmeFile = path.join(pluginDir, 'README.md');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

function walk(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const target = path.join(directory, entry.name);
    return entry.isDirectory() ? walk(target) : [target];
  });
}

function words(html) {
  // Match WordPress's wp_strip_all_tags() + PHP Unicode regex behavior.
  return (html.replace(/<[^>]*>/g, '').match(/\b[\p{L}\p{N}][\p{L}\p{N}’'-]*\b/gu) || []).length;
}

const php = fs.readFileSync(mainFile, 'utf8');
const source = fs.readFileSync(sourceFile, 'utf8');
const readme = fs.readFileSync(readmeFile, 'utf8');
const allText = walk(pluginDir).filter((file) => /\.(php|md|js|css|svg|txt)$/i.test(file)).map((file) => fs.readFileSync(file, 'utf8')).join('\n');

assert(/Version:\s*2\.3\.17-dev/.test(php), 'Plugin header is not 2.3.17-dev.');
assert(/GB_CORE_VERSION', '2\.3\.17-dev'/.test(php), 'Runtime version constant is not 2.3.17-dev.');
assert(/CURRICULUM_MIGRATION_TARGET = '2\.3\.10'/.test(php), 'Curriculum migration target is not 2.3.10.');
assert(readme.includes('Core 2.3.17 Adult English timing rebalance'), 'README does not document the timing rebalance.');
assert(!/Drive Smart|drivesmart/i.test(allText), 'Package contains prohibited legacy branding.');

const expected = ['001','002','003','004','005','006','007','008','009','010','011','012','013','014','015','016','018','038'].map((n) => `adult_en_${n}`);
const entries = [];
const entryPattern = /'(adult_en_\d+)'\s*=>\s*array\((.*?)\n\t\),/gs;
for (const match of source.matchAll(entryPattern)) {
  const body = match[2];
  const mode = body.match(/'mode'\s*=>\s*'([^']+)'/)?.[1];
  const removeVersion = body.match(/'remove_version'\s*=>\s*'([^']+)'/)?.[1];
  const html = body.match(/<<<'HTML'\n([\s\S]*?)\nHTML,/)?.[1] || '';
  const safetyHtml = body.match(/'safety_html'\s*=>\s*'([^']*)'/)?.[1] || '';
  entries.push({ key: match[1], mode, removeVersion, html, safetyHtml });
}
assert(JSON.stringify(entries.map((entry) => entry.key)) === JSON.stringify(expected), 'Timing source does not contain the exact 18 approved lesson keys in order.');
assert(entries.every((entry) => ['replace', 'rebalance'].includes(entry.mode) && entry.removeVersion), 'A timing-source instruction is incomplete.');

// Installed baselines were captured after removing the prior versioned hardening block.
const baseWords = { 1:439,2:488,3:486,4:453,5:850,6:610,7:577,8:624,9:607,10:465,11:638,12:639,13:612,14:734,15:659,16:358,18:446,38:487 };
const minutes = { 1:2,2:3,3:3,4:2,5:4,6:5,7:5,8:5,9:5,10:5,11:8,12:7,13:7,14:8,15:7,16:4,18:6,38:5 };
for (const entry of entries) {
  const number = Number(entry.key.slice(-3));
  const total = entry.mode === 'replace' ? words(entry.html) : baseWords[number] + words(`${entry.html}\n${entry.safetyHtml}`);
  assert(total >= minutes[number] * 123 && total <= minutes[number] * 150, `${entry.key} lacks the 123-150 words/minute validation margin: ${total}.`);
}

for (const needle of [
  "$target_version==='2.3.10'",
  "update_option('gb_curriculum_migration_version','2.3.10'",
  "'timing_rebalance_2_3_10_dev'",
  '120)||$word_count>($minutes*150)',
  "false!==strpos($original,'gb-sign-gallery')",
  '$baseline_sections===$after_sections',
  '$baseline_items===$after_items',
  '$baseline_course_status===(string)get_post_status($course_id)',
  '$baseline_registry===get_option(self::COURSE_OPTION,array())',
  '$rollback();',
]) {
  assert(php.includes(needle), `Migration preservation control is missing: ${needle}`);
}
assert(php.indexOf("update_option('gb_curriculum_migration_version','2.3.10'") > php.indexOf('$structure_ok='), 'Migration version advances before final preservation verification.');

console.log('PASS Core 2.3.17 static timing-rebalance and preservation checks');
