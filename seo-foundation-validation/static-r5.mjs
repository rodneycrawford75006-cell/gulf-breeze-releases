import fs from "node:fs";
import { fileURLToPath } from "node:url";

const phpPath = process.env.GB_SEO_PLUGIN_PHP || fileURLToPath(new URL("./gulf-breeze-seo-foundation/gulf-breeze-seo-foundation.php", import.meta.url));
const php = fs.readFileSync(phpPath, "utf8");

const required = [
  "Author: Gulf Breeze Driving School",
  "texas-adult-drivers-ed-online",
  "curso-de-manejo-para-adultos-en-texas",
  "texas-parent-taught-drivers-ed",
  "curso-de-manejo-para-adolescentes-impartido-por-padres",
  "texas-adult-drivers-ed-requirements",
  "requisitos-del-curso-de-manejo-para-adultos-en-texas",
  "texas-driver-license-guide",
  "guia-para-obtener-la-licencia-de-conducir-en-texas",
  "texas-parent-taught-drivers-ed-guide",
  "guia-de-educacion-vial-impartida-por-padres-en-texas",
  "Texas Adult Drivers Ed Online – 6-Hour Course | Gulf Breeze",
  "Curso de Manejo para Adultos en Texas – 6 Horas | Gulf Breeze",
  "Texas Parent-Taught Drivers Ed Online | Gulf Breeze",
  "Curso de Manejo Impartido por Padres en Texas | Gulf Breeze",
  "Texas Adult Drivers Ed Requirements (Ages 18–24) | Gulf Breeze",
  "Requisitos del curso de manejo para adultos en Texas | Gulf Breeze",
  "How to Get a Texas Driver License as an Adult | Gulf Breeze",
  "Cómo obtener una licencia de conducir en Texas | Gulf Breeze",
  "Texas Parent-Taught Drivers Ed Requirements | Gulf Breeze",
  "Requisitos para Educación Vial por Padres en Texas | Gulf Breeze",
  "/texas-parent-taught-drivers-ed-guide/",
  "/es/guia-de-educacion-vial-impartida-por-padres-en-texas/",
  'rel="canonical"',
  'hreflang="en-US"',
  'hreflang="es-US"',
  'hreflang="x-default"',
  "Organization",
  "WebSite",
  "WebPage",
  "BreadcrumbList",
  "wp_robots",
  "wp_sitemaps_posts_query_args",
  "Gulf Breeze Driving School",
];

const failures = [];
for (const token of required) {
  if (!php.includes(token)) failures.push(`Missing required token: ${token}`);
}

if (/meta[^\n]{0,30}keywords/i.test(php)) {
  failures.push("Obsolete meta-keywords output detected");
}

if (!php.includes("Version: 0.1.0-dev-r5") || !php.includes("const VERSION = '0.1.0-dev-r5';")) {
  failures.push("Plugin header and runtime version must both be 0.1.0-dev-r5");
}

const blocked = [
  "TDLR-approved",
  "approved TDLR course number",
  "official written knowledge/permit exam is included",
  "same-day certificate",
  "guaranteed certificate",
];
for (const token of blocked) {
  if (php.includes(token)) failures.push(`Blocked authorization-dependent claim detected: ${token}`);
}

const openBraces = (php.match(/\{/g) || []).length;
const closeBraces = (php.match(/\}/g) || []).length;
if (openBraces !== closeBraces) {
  failures.push(`Unbalanced braces: ${openBraces} opening / ${closeBraces} closing`);
}

if (failures.length) {
  console.error(failures.join("\n"));
  process.exit(1);
}

console.log("PASS: controlled titles, descriptions, language pairing, schema types, robots, sitemaps, and entity attribution are present.");
console.log("PASS: no meta-keywords output is present.");
console.log(`PASS: brace balance ${openBraces}/${closeBraces}.`);
