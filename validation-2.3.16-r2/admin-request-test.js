import fs from 'node:fs';

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

const auth = JSON.parse(
  fs.readFileSync(`${process.env.GITHUB_WORKSPACE}/validation-2.3.16-r2/admin-auth-cookies.json`, 'utf8')
);
const cookie = auth.map(({ name, value }) => `${name}=${value}`).join('; ');
const requestCount = 20;
const started = Date.now();
const responses = await Promise.all(
  Array.from({ length: requestCount }, () => fetch('http://127.0.0.1:8080/wp-admin/', {
    headers: { cookie },
    redirect: 'manual',
  }))
);
const elapsedMs = Date.now() - started;

for (const response of responses) {
  assert(response.status === 200, `Administrator request returned HTTP ${response.status}.`);
  const body = await response.text();
  assert(body.includes('Dashboard') || body.includes('wp-admin-bar-site-name'), 'Administrator request did not render WordPress administration.');
}
assert(elapsedMs < 10000, `Concurrent administrator requests were not bounded: ${elapsedMs}ms.`);

console.log(`PASS ${requestCount} concurrent wp-admin requests completed in ${elapsedMs}ms without running the migration body.`);
