const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const student = await browser.newContext();
  const studentPage = await student.newPage();

  await studentPage.goto('http://127.0.0.1:8080/wp-login.php');
  await studentPage.locator('#user_login').fill('gb_validation_tester');
  await studentPage.locator('#user_pass').fill('validation-only-password');
  await Promise.all([
    studentPage.waitForNavigation(),
    studentPage.locator('#wp-submit').click(),
  ]);

  const studentResponse = await studentPage.goto('http://127.0.0.1:8080/courses/controlled-access-validation-course/');
  if (!studentResponse || studentResponse.status() !== 200) {
    throw new Error(`Approved student expected HTTP 200, received ${studentResponse ? studentResponse.status() : 'no response'}.`);
  }
  if (!(await studentPage.getByRole('heading', { name: 'Controlled Access Validation Course' }).count())) {
    throw new Error('Approved student did not receive the validation course page.');
  }

  const anonymous = await browser.newContext();
  const anonymousPage = await anonymous.newPage();
  const anonymousResponse = await anonymousPage.goto('http://127.0.0.1:8080/courses/controlled-access-validation-course/');
  if (!anonymousResponse || anonymousResponse.status() !== 404) {
    throw new Error(`Anonymous visitor expected HTTP 404, received ${anonymousResponse ? anonymousResponse.status() : 'no response'}.`);
  }

  await browser.close();
  console.log('Chromium student-access and anonymous-404 validation passed.');
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
