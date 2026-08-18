import fs from 'node:fs';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const fixture = JSON.parse(fs.readFileSync(process.env.GBVQG_FIXTURE_JSON, 'utf8'));
const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
await page.route(/\\.(?:css|png|jpe?g|gif|svg|webp|woff2?|ttf)(?:\\?.*)?$/i, route => route.abort());

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
const expectText = async (text) => {
  await page.getByText(text, { exact: false }).first().waitFor({ state: 'visible', timeout: 15000 });
};

const loginUrl = new URL('/wp-login.php', fixture.base_url).href;
await page.goto(loginUrl, { waitUntil: 'commit', timeout: 15000 });
await page.locator('#user_login').waitFor({ state: 'visible', timeout: 15000 });
await page.locator('#user_login').fill(fixture.student.username);
await page.locator('#user_pass').fill(fixture.student.password);
await page.locator('#loginform').evaluate((form, action) => { form.action = action; }, loginUrl);
await page.locator('#wp-submit').click();
try {
  await page.waitForURL(url => !url.pathname.endsWith('/wp-login.php'), { timeout: 15000 });
} catch {
  const loginError = await page.locator('#login_error, .message').allTextContents();
  assert.fail(`Disposable student login failed at ${page.url()}: ${loginError.join(' | ') || 'no WordPress login error was rendered'}`);
}

async function openLesson(gate) {
  await page.goto(gate.lesson_url, { waitUntil: 'domcontentloaded' });
  await page.bringToFront();
  await page.locator('iframe[src*="youtube-nocookie.com/embed/"]').waitFor({ state: 'attached' });
  const config = await page.evaluate(() => {
    const scripts = [...document.scripts].map(s => s.textContent || '').join('\n');
    const nonce = scripts.match(/var ajaxNonce = ("[^"]+")/);
    const ajax = scripts.match(/var ajaxUrl = ("[^"]+")/);
    const gate = scripts.match(/var gateId = ("[^"]+")/);
    return {
      nonce: nonce ? JSON.parse(nonce[1]) : '',
      ajaxUrl: ajax ? JSON.parse(ajax[1]) : '',
      gateId: gate ? JSON.parse(gate[1]) : '',
    };
  });
  assert.equal(config.gateId, gate.gate_id, 'Lesson did not expose the expected gate configuration.');
  assert(config.nonce && config.ajaxUrl, 'Lesson did not expose an authenticated playback nonce and endpoint.');
  return config;
}

async function post(config, action, data = {}) {
  return page.evaluate(async ({ config, action, data }) => {
    const body = new URLSearchParams({ action, gate_id: config.gateId, nonce: config.nonce });
    for (const [key, value] of Object.entries(data)) body.set(key, String(value));
    const response = await fetch(config.ajaxUrl, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString(),
    });
    return { status: response.status, payload: await response.json() };
  }, { config, action, data });
}

async function verifyIncomplete(config, label) {
  const result = await post(config, 'gbvqg_verify_video_completion');
  assert.equal(result.status, 200, `${label}: verification request failed.`);
  assert.equal(result.payload?.data?.completed, false, `${label}: bypass created completion evidence.`);
}

async function start(config, duration = 10) {
  const result = await post(config, 'gbvqg_playback_start', { position: 0, duration });
  assert.equal(result.status, 200, 'Playback start failed.');
  assert.equal(result.payload?.success, true, 'Playback start was not accepted.');
  return result.payload.data.token;
}

async function progress(config, token, sequence, values = {}) {
  return post(config, 'gbvqg_playback_progress', {
    token, sequence, position: values.position ?? sequence, duration: values.duration ?? 10,
    rate: values.rate ?? 1, playing: values.playing ?? 1, ended: values.ended ?? 0,
    visible: values.visible ?? 1, focused: values.focused ?? 1, seeking: values.seeking ?? 0,
  });
}

async function testBypasses(gate) {
  const config = await openLesson(gate);

  let token = await start(config);
  await sleep(1100);
  let result = await progress(config, token, 1, { position: 9, ended: 1, seeking: 1 });
  assert.equal(result.payload?.data?.complete, false, 'Forward seeking completed the video.');
  assert.equal(result.payload?.data?.accepted, false, 'Forward seeking earned playback credit.');
  await verifyIncomplete(config, 'forward seek');

  token = await start(config);
  await sleep(1100);
  result = await progress(config, token, 1, { position: 1, visible: 0 });
  assert.equal(result.payload?.data?.accepted, false, 'Hidden-tab playback earned credit.');
  await verifyIncomplete(config, 'hidden tab');

  token = await start(config);
  await sleep(1100);
  result = await progress(config, token, 1, { position: 1, focused: 0 });
  assert.equal(result.payload?.data?.accepted, false, 'Unfocused playback earned credit.');
  await verifyIncomplete(config, 'unfocused window');

  token = await start(config);
  await sleep(1100);
  result = await progress(config, token, 1, { position: 2, rate: 2 });
  assert.equal(result.payload?.data?.accepted, false, 'Speed manipulation earned credit.');
  await verifyIncomplete(config, 'speed manipulation');

  token = await start(config);
  await sleep(1100);
  result = await progress(config, token, 2, { position: 1 });
  assert.equal(result.status, 409, 'Out-of-order heartbeat was not rejected.');
  await verifyIncomplete(config, 'out-of-order heartbeat');

  token = await start(config);
  await sleep(1100);
  result = await progress(config, token, 1, { position: 1 });
  assert.equal(result.payload?.data?.accepted, true, 'Ordinary partial playback was not accepted as partial credit.');
  await verifyIncomplete(config, 'incomplete playback');

  await page.goto(gate.quiz_url, { waitUntil: 'domcontentloaded' });
  const lessonSlug = new URL(gate.lesson_url).pathname.split('/').filter(Boolean).at(-1);
  assert(page.url().includes(lessonSlug), 'Direct quiz URL bypassed missing playback evidence.');
  await page.goto(gate.next_url, { waitUntil: 'domcontentloaded' });
  assert(!page.url().includes(new URL(gate.next_url).pathname), 'Later-item URL bypassed the gate.');
}

async function installYouTubeStub() {
  await page.route('https://www.youtube.com/iframe_api', async route => {
    await route.fulfill({
      contentType: 'application/javascript',
      body: `(() => {
        window.YT = { PlayerState: { PLAYING: 1, ENDED: 0 }, Player: function(id, options) {
          let state = -1, started = 0, ended = false;
          const player = this;
          this.getDuration = () => 10;
          this.getCurrentTime = () => ended ? 10 : (state === 1 ? Math.min(10, (Date.now() - started) / 1000) : 0);
          this.getPlaybackRate = () => 1;
          this.setPlaybackRate = () => {};
          this.getPlayerState = () => state;
          this.play = () => { state = 1; started = Date.now(); options.events.onStateChange({ data: 1 }); };
          this.end = () => { ended = true; state = 0; options.events.onStateChange({ data: 0 }); };
          window.__gbvqgTestPlayer = player;
        }};
        setTimeout(() => window.onYouTubeIframeAPIReady && window.onYouTubeIframeAPIReady(), 0);
      })();`,
    });
  });
}

async function completeThroughRealAdapter(gate) {
  const config = await openLesson(gate);
  await page.waitForFunction(() => window.__gbvqgTestPlayer, null, { timeout: 10000 });
  await page.evaluate(() => window.__gbvqgTestPlayer.play());
  await sleep(10300);
  await page.evaluate(() => window.__gbvqgTestPlayer.end());
  await sleep(1200);
  const verified = await post(config, 'gbvqg_verify_video_completion');
  assert.equal(verified.payload?.data?.completed, true, 'Real browser YouTube adapter did not create verified completion evidence.');
  const iframeSrc = await page.locator('iframe[src*="youtube-nocookie.com/embed/"]').getAttribute('src');
  assert(iframeSrc.includes('enablejsapi=1'), 'YouTube adapter did not enable the player API.');
  return config;
}

async function submitAnswer(correct) {
  const hidden = page.locator('input[name="question_ids[]"]');
  const questionId = await hidden.first().getAttribute('value');
  const answerPattern = correct ? /^\s*Correct \d+\s*$/ : /^\s*Incorrect \d+\s*$/;
  const answer = page.locator('.gbvqg-answer').filter({ hasText: answerPattern }).first();
  await answer.locator('input[type="radio"]').check();
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    page.locator('.gbvqg-submit').click(),
  ]);
  return questionId;
}

await installYouTubeStub();
await testBypasses(fixture.csea);

let config = await completeThroughRealAdapter(fixture.csea);
await page.goto(fixture.csea.quiz_url, { waitUntil: 'domcontentloaded' });
await expectText('Quick Video Check');
const firstQuestion = await submitAnswer(false);
await expectText('Video Check Not Passed');

config = await openLesson(fixture.csea);
await verifyIncomplete(config, 'wrong answer invalidation');
await page.goto(fixture.csea.next_url, { waitUntil: 'domcontentloaded' });
assert(!page.url().includes(new URL(fixture.csea.next_url).pathname), 'Wrong answer allowed later-item access.');

await completeThroughRealAdapter(fixture.csea);
await page.goto(fixture.csea.quiz_url, { waitUntil: 'domcontentloaded' });
await expectText('Quick Video Check');
const secondQuestion = await page.locator('input[name="question_ids[]"]').first().getAttribute('value');
assert.notEqual(secondQuestion, firstQuestion, 'Failed retake repeated the same question before the four-question bank was exhausted.');
await submitAnswer(true);
await expectText('Video Check Passed');
await page.goto(fixture.csea.next_url, { waitUntil: 'domcontentloaded' });
await expectText('CSEA Follow-up Available');

await completeThroughRealAdapter(fixture.water);
await page.goto(fixture.water.quiz_url, { waitUntil: 'domcontentloaded' });
await expectText('Quick Video Check');
await submitAnswer(true);
await expectText('Video Check Passed');
await page.goto(fixture.water.next_url, { waitUntil: 'domcontentloaded' });
await expectText('Recreational Water Safety Follow-up Available');

await browser.close();
console.log('PASS Video Gate 0.3.1 real-browser CSEA/water-safety playback, bypass, wrong-answer, retake, and later-item enforcement');
