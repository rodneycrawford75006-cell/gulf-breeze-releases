import fs from 'node:fs';
import path from 'node:path';

const root = process.env.GITHUB_WORKSPACE || process.cwd();
const pluginDir = process.env.GB_PLUGIN_DIR || path.join(root, 'build-2.3.15-r1', 'gulf-breeze-core');
const mainFile = path.join(pluginDir, 'gulf-breeze-core.php');
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

assert(fs.existsSync(mainFile), 'Plugin source is missing.');
const php = fs.readFileSync(mainFile, 'utf8');
const readme = fs.readFileSync(readmeFile, 'utf8');
const allText = walk(pluginDir).filter((file) => /\.(php|md|js|css|svg|txt)$/i.test(file)).map((file) => fs.readFileSync(file, 'utf8')).join('\n');

assert(/Version:\s*2\.3\.15-dev/.test(php), 'Plugin header is not 2.3.15-dev.');
assert(/GB_CORE_VERSION', '2\.3\.15-dev'/.test(php), 'Runtime version constant is not 2.3.15-dev.');
assert(readme.includes('Core 2.3.15'), 'README does not document Core 2.3.15.');
assert(!/Drive Smart|drivesmart/i.test(allText), 'Package contains prohibited legacy branding.');
assert(php.includes("$target_version = '2.3.14';"), 'Privacy migration target is not 2.3.14.');
assert(php.includes('\\LearnPress\\Models\\CoursePostModel'), 'Native LearnPress CoursePostModel path is missing.');
assert(php.includes('\\LearnPress\\Models\\CourseSectionModel::find'), 'Native LearnPress CourseSectionModel lookup is missing.');
assert(php.includes('$course_post_model->update_section'), 'Native LearnPress section update is missing.');
assert(!php.includes('$wpdb->update( $sections_table'), 'Section descriptions still use a direct legacy-table update.');
assert(php.includes('9 !== count( (array) $sections )'), 'Nine-section preservation gate is missing.');
assert(php.includes('56 !== count( (array) $curriculum_rows )'), '56-item preservation gate is missing.');
assert(php.includes('46 !== $lesson_count'), '46-lesson preservation gate is missing.');
assert(php.includes('10 !== $quiz_count'), '10-quiz preservation gate is missing.');
assert(php.includes('$curriculum_signature !== wp_json_encode( $curriculum_rows_after )'), 'Ordered curriculum signature verification is missing.');
assert(php.indexOf('$curriculum_signature !== wp_json_encode( $curriculum_rows_after )') < php.indexOf("update_option( 'gb_student_privacy_migration_version', $target_version"), 'Migration version advances before preservation verification.');
assert((php.match(/'4\.1\.[1-9]'\s*=>\s*'/g) || []).length >= 9, 'All nine topic descriptions are not present.');
assert(php.includes('Private self-check — no submission required:'), 'Private self-check replacement is missing.');

const requiredIncludes = [
  'adult-topic-one-hardening.php', 'adult-topic-two-hardening.php',
  'adult-topic-three-hardening.php', 'adult-topic-four-hardening.php',
  'adult-topic-five-hardening.php', 'adult-topic-seven-hardening.php',
  'adult-topic-eight-hardening.php', 'adult-topic-nine-hardening.php',
];
for (const filename of requiredIncludes) {
  assert(walk(pluginDir).some((file) => path.basename(file) === filename), `Preserved include is missing: ${filename}`);
}

const topicSeven = fs.readFileSync(path.join(pluginDir, 'includes', 'adult-topic-seven-hardening.php'), 'utf8');
assert(topicSeven.includes('Before proceeding, name the vulnerable user, likely conflict point, yielding duty'), 'Topic 4.1.7 duration correction is missing.');
const boundaryMatch = topicSeven.match(/'adult_en_032'\s*=>\s*<<<'HTML'\n([\s\S]*?)\nHTML,/);
assert(boundaryMatch, 'adult_en_032 hardening source is unavailable.');
const boundarySourceWords = (boundaryMatch[1].replace(/<[^>]*>/g, ' ').match(/\b[\p{L}\p{N}][\p{L}\p{N}’'-]*\b/gu) || []).length;
assert(379 + boundarySourceWords >= 960, `adult_en_032 remains below its eight-minute boundary: ${379 + boundarySourceWords}/960 words.`);
assert(php.includes("update_option('gb_curriculum_migration_version','2.3.3'"), 'Topic 4.1.7 completion gate is missing.');
for (const version of ['2.3.4', '2.3.5', '2.3.6', '2.3.7', '2.3.8', '2.3.9']) {
  assert(php.includes(`update_option('gb_curriculum_migration_version','${version}'`), `Resumable ${version} migration is missing.`);
}

console.log('Static Core 2.3.15 revision 1 build checks passed.');
