const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const origin = 'http://127.0.0.1:8080';
  const browser = await chromium.launch({ headless: true });
  const student = await browser.newContext();
  const authCookie = JSON.parse(
    fs.readFileSync(`${process.env.GITHUB_WORKSPACE}/validation/auth-cookie.json`, 'utf8')
  );
  await student.addCookies([authCookie]);
  const studentPage = await student.newPage();

  const courseId = fs.readFileSync(`${process.env.GITHUB_WORKSPACE}/validation/course-id.txt`, 'utf8').trim();
  const lessonOneId = fs.readFileSync(`${process.env.GITHUB_WORKSPACE}/validation/lesson-one-id.txt`, 'utf8').trim();
  const lessonTwoId = fs.readFileSync(`${process.env.GITHUB_WORKSPACE}/validation/lesson-two-id.txt`, 'utf8').trim();
  const courseUrl = `${origin}/?post_type=lp_course&p=${encodeURIComponent(courseId)}`;

  const studentResponse = await studentPage.goto(courseUrl);
  if (!studentResponse || studentResponse.status() !== 200) {
    throw new Error(`Approved student expected HTTP 200, received ${studentResponse ? studentResponse.status() : 'no response'}.`);
  }
  if (!(await studentPage.getByRole('heading', { name: 'Controlled Access Validation Course' }).count())) {
    throw new Error('Approved student did not receive the validation course page.');
  }

  const lessonOneLink = studentPage.getByRole('link', { name: 'Validation Timed Lesson One' }).first();
  const lessonTwoLink = studentPage.getByRole('link', { name: 'Validation Locked Lesson Two' }).first();
  if (!(await lessonOneLink.count()) || !(await lessonTwoLink.count())) {
    throw new Error('Disposable course curriculum did not expose both lesson links.');
  }
  const lessonOneUrl = new URL(await lessonOneLink.getAttribute('href'), origin).href;
  const lessonTwoUrl = new URL(await lessonTwoLink.getAttribute('href'), origin).href;
  const firstResponse = await studentPage.goto(lessonOneUrl);
  if (!firstResponse || firstResponse.status() !== 200) {
    throw new Error(`First regulated lesson expected HTTP 200, received ${firstResponse ? firstResponse.status() : 'no response'}.`);
  }
  await studentPage.locator('#gb-seat-time').waitFor({ state: 'visible' });
  const statusText = await studentPage.locator('#gb-seat-status').innerText();
  if (!/0:00 of 2:00|Server-confirmed study time/.test(statusText)) {
    throw new Error(`Secure timer did not initialize: ${statusText}`);
  }

  const secondResponse = await studentPage.goto(lessonTwoUrl);
  if (!secondResponse || secondResponse.status() !== 200) {
    throw new Error(`Locked lesson redirect expected final HTTP 200, received ${secondResponse ? secondResponse.status() : 'no response'}.`);
  }
  const finalUrl = new URL(studentPage.url());
  const expectedFirstUrl = new URL(lessonOneUrl);
  if (finalUrl.pathname !== expectedFirstUrl.pathname || finalUrl.searchParams.get('gb_sequence_locked') !== '1') {
    throw new Error(`Sequential lock did not redirect lesson two to lesson one. Final URL: ${studentPage.url()}`);
  }

  const anonymous = await browser.newContext();
  const anonymousPage = await anonymous.newPage();
  const anonymousResponse = await anonymousPage.goto(courseUrl);
  if (!anonymousResponse || anonymousResponse.status() !== 404) {
    throw new Error(`Anonymous visitor expected HTTP 404, received ${anonymousResponse ? anonymousResponse.status() : 'no response'}.`);
  }

  await browser.close();
  console.log('Chromium student-access and anonymous-404 validation passed.');
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
