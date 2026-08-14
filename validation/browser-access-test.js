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
  const courseUrl = `${origin}/?post_type=lp_course&p=${encodeURIComponent(courseId)}`;

  const studentResponse = await studentPage.goto(courseUrl);
  if (!studentResponse || studentResponse.status() !== 200) {
    throw new Error(`Approved student expected HTTP 200, received ${studentResponse ? studentResponse.status() : 'no response'}.`);
  }
  if (!(await studentPage.getByRole('heading', { name: 'Controlled Access Validation Course' }).count())) {
    throw new Error('Approved student did not receive the validation course page.');
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
