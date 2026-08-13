import fs from 'node:fs';
import path from 'node:path';

const source = process.argv[2];
const output = process.argv[3];
if (!source || !output) {
  throw new Error('Usage: node generate-assessment-banks.mjs SEED_PHP OUTPUT_DIR');
}

const text = fs.readFileSync(source, 'utf8');

function section(name, nextName) {
  const start = text.indexOf(`'${name}' => array(`);
  const end = nextName ? text.indexOf(`'${nextName}' => array(`, start + 1) : text.lastIndexOf(');');
  if (start < 0 || end < 0) throw new Error(`Seed section not found: ${name}`);
  return text.slice(start, end);
}

function parseRow(line) {
  const open = line.indexOf('array(');
  const close = line.lastIndexOf(')');
  if (open < 0 || close < 0) return null;
  const input = line.slice(open + 6, close);
  const values = [];
  let i = 0;
  while (i < input.length) {
    while (i < input.length && /[\s,]/.test(input[i])) i++;
    if (i >= input.length) break;
    if (input[i] === "'") {
      i++;
      let value = '';
      while (i < input.length) {
        if (input[i] === '\\' && i + 1 < input.length) {
          value += input[i + 1];
          i += 2;
        } else if (input[i] === "'") {
          i++;
          break;
        } else {
          value += input[i++];
        }
      }
      values.push(value);
    } else {
      const comma = input.indexOf(',', i);
      const end = comma < 0 ? input.length : comma;
      values.push(input.slice(i, end).trim());
      i = end;
    }
  }
  return values;
}

function rows(name, nextName) {
  return section(name, nextName)
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line.startsWith('array('))
    .map(parseRow)
    .filter(Boolean);
}

function csvCell(value) {
  const string = String(value ?? '');
  return /[",\r\n]/.test(string) ? `"${string.replaceAll('"', '""')}"` : string;
}

function crc32(value) {
  let crc = 0xffffffff;
  for (const byte of Buffer.from(value, 'utf8')) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit++) {
      crc = (crc >>> 1) ^ (crc & 1 ? 0xedb88320 : 0);
    }
  }
  return (crc ^ 0xffffffff) >>> 0;
}

const headers = [
  'question_title', 'question_text', 'answer_a', 'answer_b', 'answer_c', 'answer_d',
  'correct_answer', 'bank_title', 'course_type', 'module', 'objective_code', 'poi_topic',
  'points', 'question_type', 'hint', 'explanation', 'source_url', 'bank_description', 'image_file'
];

function writeBank(filename, bankTitle, bankDescription, sourceRows, kind) {
  const outputRows = sourceRows.map((row) => {
    const [chapter, key, topic, question, a, b, c, d, correct, image = ''] = row;
	const options = [a, b, c, d];
	const shift = crc32(key) % 4;
	for (let index = 0; index < shift; index++) options.push(options.shift());
	const correctAnswer = ['A', 'B', 'C', 'D'][(4 - shift) % 4];
	const questionText = image
	  ? `<p><img src="https://gulfbreezedrivingschool.com/wp-content/plugins/gulf-breeze-core/assets/signs/${image}" alt="${topic} highway sign" style="display:block;width:100%;max-width:360px;height:240px;object-fit:contain;margin:0 auto 18px"></p><p>${question}</p>`
	  : question;
    return {
      question_title: `${key} — ${topic}`,
	  question_text: questionText,
	  answer_a: options[0],
	  answer_b: options[1],
	  answer_c: options[2],
	  answer_d: options[3],
	  correct_answer: correctAnswer,
      bank_title: bankTitle,
      course_type: 'adult_en',
      module: '4.1.9',
      objective_code: '4.1.9.1(A)',
      poi_topic: kind === 'signs' ? `Highway signs — Handbook Chapter ${chapter}` : `Traffic law — Handbook Chapter ${chapter}`,
      points: '1',
      question_type: 'single_choice',
      hint: '',
      explanation: '',
      source_url: 'https://www.dps.texas.gov/internetforms/forms/dl-7.pdf',
      bank_description: bankDescription,
      image_file: image,
    };
  });
  const csv = [headers.join(','), ...outputRows.map((row) => headers.map((header) => csvCell(row[header])).join(','))].join('\r\n') + '\r\n';
  fs.writeFileSync(path.join(output, filename), csv, 'utf8');
}

const rules = rows('rules', 'signs');
const signs = rows('signs', 'final');
if (rules.length !== 50 || signs.length !== 50) {
  throw new Error(`Unexpected reviewed-bank counts: rules=${rules.length}, signs=${signs.length}`);
}

fs.mkdirSync(output, { recursive: true });
writeBank(
  'gulf-breeze-adult-final-traffic-laws-bank.csv',
  'Gulf Breeze Adult Final — Traffic Laws Bank',
  'Internal reviewed traffic-law question bank for the controlled Adult English final assessment.',
  rules,
  'rules'
);
writeBank(
  'gulf-breeze-adult-final-highway-signs-bank.csv',
  'Gulf Breeze Adult Final — Highway Signs Bank',
  'Internal reviewed highway-sign question bank for the controlled Adult English final assessment.',
  signs,
  'signs'
);
console.log(`Generated reviewed banks: rules=${rules.length}, signs=${signs.length}`);
