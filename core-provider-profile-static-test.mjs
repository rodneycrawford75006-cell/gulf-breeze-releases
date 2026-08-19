import fs from 'node:fs';
import path from 'node:path';

const pluginDir = process.env.GB_PLUGIN_DIR;
if (!pluginDir) throw new Error('GB_PLUGIN_DIR is required.');

const php = fs.readFileSync(path.join(pluginDir, 'gulf-breeze-core.php'), 'utf8');
const readme = fs.readFileSync(path.join(pluginDir, 'README.md'), 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

assert(/Version:\s*2\.3\.26-dev/.test(php), 'Plugin header version mismatch.');
assert(/GB_CORE_VERSION', '2\.3\.26-dev'/.test(php), 'Runtime version mismatch.');
assert(php.includes("const PROFILE_SCHEMA_VERSION = '1.0';"), 'Provider schema version missing.');
assert(php.includes('function gb_core_provider_profile()'), 'Provider-profile API missing.');
assert(php.includes('function gb_core_provider_profile_snapshot()'), 'Snapshot API missing.');
assert(php.includes("'phone'              => array( 'label' => 'Public phone number', 'required' => true )"), 'Phone is not required.');
assert(php.includes("'support_email'      => array( 'label' => 'Student support email', 'type' => 'email', 'required' => true )"), 'Support email is not required.');
assert(php.includes("'legal_email'        => array( 'label' => 'Legal/compliance email', 'type' => 'email', 'required' => true )"), 'Legal email is not required.');
assert(php.includes("'default_locale'           => array( 'label' => 'Default customer locale', 'default' => 'en-US' )"), 'Default locale missing.');
assert(php.includes("'enabled_locales'          => array( 'label' => 'Enabled customer locales', 'default' => 'en-US,es-US' )"), 'Enabled locales missing.');
assert(php.includes("'environment_label'        => array( 'label' => 'Environment label', 'default' => 'DEVELOPMENT / SAMPLE DATA', 'required' => true )"), 'Environment label missing.');
assert(php.includes("unset( $hash_values['_profile_hash'], $hash_values['_updated_at_utc'], $hash_values['_updated_by_user_id'] );"), 'Mutable audit fields are not excluded from the profile hash.');
assert(readme.includes('Core 2.3.26 enrollment configuration foundation'), 'README checkpoint entry missing.');
assert(!/Drive Smart|drivesmart/i.test(php + '\n' + readme), 'Prohibited legacy branding found.');
assert(php.includes('46 focused lessons ranging from 2–22 minutes = exactly 330 instructional minutes'), 'Locked Adult English ledger statement changed.');

console.log('PASS Core 2.3.26 static provider-profile controls');
