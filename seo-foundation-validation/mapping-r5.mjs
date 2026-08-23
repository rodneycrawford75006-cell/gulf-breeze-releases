import fs from "node:fs";
import { fileURLToPath } from "node:url";

const phpPath = process.env.GB_SEO_PLUGIN_PHP || fileURLToPath(new URL("./gulf-breeze-seo-foundation/gulf-breeze-seo-foundation.php", import.meta.url));
const php = fs.readFileSync(phpPath, "utf8");

const failures = [];
const expect = (condition, message) => {
  if (!condition) failures.push(message);
};

function entry(slug) {
  const escaped = slug.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const match = php.match(
    new RegExp(`'${escaped}'\\s*=>\\s*array\\(([^]*?)\\n\\s*\\),`, "m"),
  );
  if (!match) return null;
  const fields = {};
  for (const field of match[1].matchAll(/'([^']+)'\s*=>\s*'([^']*)'/g)) {
    fields[field[1]] = field[2];
  }
  return fields;
}

const english = entry("texas-parent-taught-drivers-ed-guide");
const spanish = entry("guia-de-educacion-vial-impartida-por-padres-en-texas");

expect(english, "PG-013 mapping missing");
expect(spanish, "PG-014 mapping missing");

if (english && spanish) {
  expect(english.language === "en-US", "PG-013 language must be en-US");
  expect(spanish.language === "es-US", "PG-014 language must be es-US");
  expect(
    english.canonical === "/texas-parent-taught-drivers-ed-guide/",
    "PG-013 canonical is not locked",
  );
  expect(
    spanish.canonical === "/es/guia-de-educacion-vial-impartida-por-padres-en-texas/",
    "PG-014 canonical is not locked",
  );
  expect(english.alternate === spanish.canonical, "PG-013 alternate must equal PG-014 canonical");
  expect(spanish.alternate === english.canonical, "PG-014 alternate must equal PG-013 canonical");
  expect(english.og_locale === "en_US" && english.alt_locale === "es_US", "PG-013 Open Graph locales are wrong");
  expect(spanish.og_locale === "es_US" && spanish.alt_locale === "en_US", "PG-014 Open Graph locales are wrong");
  expect([...english.title].length <= 65, "PG-013 title exceeds 65 characters");
  expect([...spanish.title].length <= 65, "PG-014 title exceeds 65 characters");
  expect([...english.description].length >= 120 && [...english.description].length <= 160, "PG-013 description must be 120–160 characters");
  expect([...spanish.description].length >= 120 && [...spanish.description].length <= 160, "PG-014 description must be 120–160 characters");
  expect(english.page_name === "Texas Parent-Taught Drivers Ed Requirements", "PG-013 schema page name must match its H1");
  expect(spanish.page_name === "Requisitos de educación vial impartida por padres en Texas", "PG-014 schema page name must match its H1");
}

const authorityCount = [...php.matchAll(/^\s*'language'\s*=>\s*'(?:en-US|es-US)'/gm)].length;
expect(authorityCount === 10, `Expected 10 mapped authority pages, found ${authorityCount}`);
expect(!php.includes("'@type' => 'Course'"), "Course schema must remain gated out");
expect(!php.includes("'@type' => 'FAQPage'"), "FAQ schema must remain gated out");
expect(!/meta[^\n]{0,30}keywords/i.test(php), "Obsolete meta-keywords output detected");

if (failures.length) {
  console.error(failures.map((failure) => `FAIL: ${failure}`).join("\n"));
  process.exit(1);
}

console.log("PASS: PG-013 and PG-014 use the locked canonicals and reciprocal bilingual pairing.");
console.log("PASS: language, Open Graph locales, H1/schema names, title lengths and description lengths are controlled.");
console.log("PASS: 10 authority pages are mapped; Course schema, FAQ schema and meta keywords remain excluded.");
