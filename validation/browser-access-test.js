const { chromium } = require('playwright');
const fs = require('fs');

(async () => {
  const origin = 'http://127.0.0.1:8080';
  const browser = await chromium.launch({ headless: true });
  const student = await browser.newContext();
  const studentPage = await student.newPage();

  await studentPage.goto(`${origin}/wp-login.php`);
  await studentPage.locator('#loginform').waitFor({ state: 'visible' });
  const formAction = await studentPage.locator('#loginform').getAttribute('action');
  if (!formAction) {
    throw new Error('WordPress login form did not expose an action URL.');
  }

  const canonicalLoginUrl = new URL(formAction, studentPage.url());
  const basePath = canonicalLoginUrl.pathname.replace(/\/wp-login\.php$/, '');
  const baseUrl = `${canonicalLoginUrl.origin}${basePath}`;
  const homeUrl = `${baseUrl}/`;

  await studentPage.locator('#user_login').fill('gb_validation_tester');
  await studentPage.locator('#user_pass').fill('validation-only-password');
  await studentPage.locator('input[name="redirect_to"]').evaluate((input, url) => {
    input.value = url;
  }, homeUrl);

  await Promise.all([
    studentPage.waitForURL(homeUrl, { waitUntil: 'domcontentloaded' }),
    studentPage.locator('#wp-submit').click(),
  ]);

  const authCookies = (await student.cookies()).filter((cookie) =>
    cookie.name.startsWith('wordpress_logged_in_')
  );
  if (authCookies.length === 0) {
    const loginError = await studentPage.locator('#login_error').textContent().catch(() => null);
    throw new Error(
      `Synthetic student login did not create a WordPress authentication cookie.${loginError ? ` Login error: ${loginError.trim()}` : ''}`
    );
  }

  const courseId = fs.readFileSync(`${process.env.GITHUB_WORKSPACE}/validation/course-id.txt`, 'utf8').trim();
  const courseUrl = `${homeUrl}?post_type=lp_course&p=${encodeURIComponent(courseId)}`;

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
