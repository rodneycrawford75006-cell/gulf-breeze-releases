import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';

const pluginDir = process.env.GB_CERT_PLUGIN_DIR;
const workbook = process.env.GB_CERT_WORKBOOK;
const sourceTemplate = process.env.GB_CERT_SOURCE_TEMPLATE;
if (!pluginDir || !workbook || !sourceTemplate) throw new Error('Focused validation paths are required.');
const pluginPath = `${pluginDir}/gulf-breeze-certificates.php`;
const php = fs.readFileSync(pluginPath, 'utf8');
let passed = 0;
let failed = 0;
const check = (name, condition) => {
  if (condition) { passed += 1; process.stdout.write(`PASS ${name}\n`); }
  else { failed += 1; process.stdout.write(`FAIL ${name}\n`); }
};

const sharedXml = execFileSync('unzip', ['-p', workbook, 'xl/sharedStrings.xml'], { encoding: 'utf8' });
const officialHeaders = [...sharedXml.matchAll(/<t(?:\s[^>]*)?>([\s\S]*?)<\/t>/g)].map((m) => m[1]
  .replaceAll('&amp;', '&').replaceAll('&lt;', '<').replaceAll('&gt;', '>'));
const constant = php.match(/const REPORT_HEADERS = array\(([\s\S]*?)\n\t\);/);
const implementedHeaders = constant ? [...constant[1].matchAll(/'([^']*)'/g)].map((m) => m[1]) : [];
check('official upload template has 21 headers', officialHeaders.length === 21);
check('plugin headers exactly match supplied upload template', JSON.stringify(implementedHeaders) === JSON.stringify(officialHeaders));
check('received ADEE source checksum matches', createHash('sha256').update(fs.readFileSync(sourceTemplate)).digest('hex') === 'b66904cd34154281c80f6655712a2bbbd6141e140e454072bc857414be132f26');

const required = [
  ["r3 version", "const VERSION = '0.1.0-dev-r3'"],
  ["r3 schema", "const SCHEMA_VERSION = '0.4.0'"],
  ['immutable test mode', "const MODE = 'test'"],
  ['eight-digit mock serial floor', '90000001'],
  ['transactional allocation lock', 'LIMIT 1 FOR UPDATE'],
  ['unique certificate number', 'UNIQUE KEY type_serial'],
  ['unique eligibility', 'UNIQUE KEY eligibility_key'],
  ['verified payment', "'paid_enrolled'"],
  ['Adult final evidence', "'adult_final'"],
  ['minimum score 70', 'max( 70'],
  ['LearnPress completion', "array( 'completed', 'finished' )"],
  ['15-day reporting period', 'const REPORTING_DAYS = 15'],
  ['exact Adult report values', "'ADE', 'ADEE'"],
  ['online Adult course type', "'ONLINE ADULT'"],
  ['six classroom hours', "'6'"],
  ['invalid test export name', 'TEST-NOT-FOR-TDLR-'],
  ['test export cannot be accepted', 'Test-mode records cannot be marked as accepted by TDLR'],
  ['PHP 8.4 CSV escape argument', "fputcsv( $stream, self::REPORT_HEADERS, ',', '\"', '' )"],
  ['anonymous download denial hook', "admin_post_nopriv_gb_cert_download"],
  ['download nonce verification', "wp_verify_nonce( $nonce, 'gb_cert_download_'"],
  ['download ownership', "absint( $row['user_id'] ?? 0 ) !== $actor_user_id"],
  ['administrator permanent access', "'administrator_permanent_access'"],
  ['signed contract source', "'_gb_ep_contract_signed_at_utc'"],
  ['frozen expiration order meta', "'_gb_ep_contract_expires_at_utc'"],
  ['one calendar year calculation', "->modify( '+1 year' )"],
  ['expiration conflict is rejected', "'gb_cert_contract_conflict'"],
  ['issuance freezes signed UTC', "'contract_signed_utc' => $contract_dates['signed_utc']"],
  ['issuance freezes download deadline', "'student_download_until_utc' => $contract_dates['expires_utc']"],
  ['schema backfills deadlines', '$this->backfill_contract_dates();'],
  ['student expiration denial', "'contract_expired'"],
  ['revocation denial', "'_gb_ep_access_revoked'"],
  ['refunded cancelled and failed denial', "array( 'refunded', 'cancelled', 'failed' )"],
  ['private append-only download ledger', "'gb_certificate_download_log'"],
  ['ledger success and denial outcome', "'outcome' => sanitize_key( $outcome )"],
  ['PDF integrity denial is audited', "'pdf_integrity_failed'"],
  ['successful release fails closed if unaudited', 'No file was released.'],
  ['student active success reason', "'student_contract_active'"],
  ['English access deadline', 'Download available until:'],
  ['Spanish access deadline', 'Descarga disponible hasta:'],
  ['English expired message', 'The student download period has ended.'],
  ['Spanish expired message', 'El período de descarga para estudiantes terminó.'],
  ['admin audit table', 'Certificate download audit'],
  ['compound-name review', 'gb_cert_name_review'],
  ['English instructions', 'What to do next'],
  ['Spanish instructions', 'Qué hacer después'],
  ['90-day instructions', 'dentro de los 90 días'],
  ['ADEE plus mock serial', "'ADEE' . ( $row['serial_number']"],
  ['preserved PDF blob', "'pdf_blob_b64' => base64_encode( $pdf )"],
  ['PDF integrity check', 'gb_cert_pdf_integrity'],
  ['template and output hashes', "'template_sha256' => hash_file"],
  ['course-language template selection', "'adee-1317-test-es.pdf' : 'adee-1317-test-en.pdf'"],
  ['PDF email attachment', "'Gulf-Breeze-ADEE-TEST-' . $row['serial_number'] . '.pdf'"],
];
for (const [name, snippet] of required) check(name, php.includes(snippet));

for (const language of ['en', 'es']) {
  const path = `${pluginDir}/assets/adee-1317-test-${language}.pdf`;
  const bytes = fs.readFileSync(path);
  const info = execFileSync('pdfinfo', [path], { encoding: 'utf8' });
  check(`${language} package has three Letter pages`, /Pages:\s+3/.test(info) && /612 x 792 pts/.test(info));
  check(`${language} package is static`, /Form:\s+none/.test(info));
  check(`${language} package has visible test watermark`, bytes.includes(Buffer.from('TEST - NOT VALID FOR DPS OR TDLR')));
  for (const token of ['GBCTRL00000000000000', 'GBLAST00000000000000000000000000', 'GBINSTRUCTOR000000000000000000000000000000', 'GBDATE0000']) {
    check(`${language} ${token} appears on both copies`, bytes.toString('latin1').split(token).length - 1 === 2);
  }
}

process.stdout.write(`RESULT ${passed} passed, ${failed} failed\n`);
if (failed) process.exit(1);
