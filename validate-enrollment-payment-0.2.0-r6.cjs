'use strict';

const fs = require('fs');
const path = require('path');

const root = process.env.GB_EP_PLUGIN_DIR || path.resolve(__dirname, 'combined-r6', 'gulf-breeze-enrollment-payment');
const php = fs.readFileSync(path.join(root, 'gulf-breeze-enrollment-payment.php'), 'utf8');
const readme = fs.readFileSync(path.join(root, 'README.md'), 'utf8');
const checkoutJs = fs.readFileSync(path.join(root, 'assets', 'checkout.js'), 'utf8');
const accountCss = fs.readFileSync(path.join(root, 'assets', 'account.css'), 'utf8');
const returningAccountEmail = php.slice(php.indexOf('private function send_student_onboarding'), php.indexOf('private function enroll_course'));

const checks = [
  ['version r6', /Version:\s*0\.2\.0-dev-r6/.test(php) && /const VERSION = '0\.2\.0-dev-r6'/.test(php)],
  ['working paid-only engine retained', php.indexOf('wc_create_new_customer') > php.indexOf('public function process_paid_order') && php.includes('$order->set_customer_id( $user_id )')],
  ['PayPal verification retained', php.includes("array( 'ppcp-gateway', 'ppcp-credit-card-gateway' )") && php.includes('$order->get_transaction_id()')],
  ['atomic agreement retained', php.includes('META_SNAPSHOT') && php.includes('META_HASH') && php.includes('order_has_valid_agreement')],
  ['prominent first-time password button', php.includes('Create My Password') && php.includes('gb_ep_email_password') && php.includes('No old password is required')],
  ['email password link expires in 24 hours', php.includes('$expires = time() + DAY_IN_SECONDS') && php.includes('$expires >= time()')],
  ['email password link is order/user bound', php.includes("$order->get_id() . '|' . $user->ID") && php.includes("hash_hmac( 'sha256', $payload") && php.includes('hash_equals( $expected, $signature )')],
  ['password link requires active paid enrollment', php.includes("'yes' === $order->get_meta( self::META_PROCESSED )") && php.includes("'yes' !== $order->get_meta( self::META_REVOKED )") && php.includes('META_STUDENT_USER_ID')],
  ['secure WordPress reset form used', php.includes('get_password_reset_key') && php.includes('action=rp&key=')],
  ['invalid email link has recovery path', php.includes('Request a fresh password link') && php.includes('wc_lostpassword_url()')],
  ['no custom verification flag', !/_wc_email_verified|mark_verified|email_verification_status/.test(php)],
  ['no old-password field', !/account_current_password|current_password|gb_ep_old_password/i.test(php)],
  ['no password storage', !/update_meta_data\([^\n]*(password|passphrase)/i.test(php)],
  ['name-based collision-safe username', php.includes('unique_name_username') && php.includes('username_exists') && php.includes('wc_create_new_customer( $email, $this->unique_name_username( $name, $email ) )')],
  ['verified one-time self-purchase login', php.includes('maybe_sign_in_self_purchaser') && php.includes('wp_set_auth_cookie') && php.includes('_gb_ep_auto_login_used_at_utc') && php.includes('purchaser_is_student') && php.includes("hash_equals( $order->get_order_key(), $key )")],
  ['automatic login blocks administrators', php.includes("if ( ! $user || user_can( $user, 'manage_options' ) )")],
  ['direct paid course action', php.includes('course_access_url') && php.includes('Go to My Course')],
  ['My Courses endpoint registered', php.includes("const ACCOUNT_ENDPOINT = 'my-courses'") && php.includes('add_rewrite_endpoint')],
  ['existing enrollment metadata read', php.includes("'_gb_ep_active_course_' . absint( $course_id )") && php.includes('student_course_rows')],
  ['My Courses shows payment/order/access', php.includes('Start/Continue Course') && php.includes("'Payment:'") && php.includes("'Order:'") && php.includes('course_paid_total')],
  ['student login routes to My Courses', php.includes('woocommerce_login_redirect') && php.includes('login_redirect') && php.includes("wc_get_account_endpoint_url( self::ACCOUNT_ENDPOINT )")],
  ['mapped Free label replaced', php.includes('learn_press_course_price_html_free') && php.includes('Purchase through Gulf Breeze') && php.includes('gb-ep-paid-course-price')],
  ['direct free enrollment blocked', php.includes('learn-press/user/can-enroll/course') && php.includes('gb_ep_paid_course_required')],
  ['free enrollment button hidden', php.includes('learnpress/course/template/button-enroll/can-show') && php.includes('hide_mapped_free_enroll_button')],
  ['administrator resend action', php.includes('woocommerce_order_actions') && php.includes('gb_ep_resend_student_access') && php.includes('Resend student course access')],
  ['resend respects existing account', php.includes('META_ACCOUNT_CREATED') && returningAccountEmail.includes('Your existing account remains active')],
  ['resend includes paid context', php.includes('Open My Courses') && php.includes('course_paid_total') && php.includes('administrator_resend_accepted_by_wordpress_mail')],
  ['account presentation asset included', accountCss.includes('.gb-ep-course-card') && php.includes('assets/account.css')],
  ['native first-time account email retained', php.includes('woocommerce_email_additional_content_customer_new_account') && php.includes('wc_create_new_customer( $email,')],
  ['new-account email contains course and agreement', php.includes('native_new_account_content') && php.includes('Signed agreement copy') && php.includes("$snapshot['terms_html']")],
  ['returning account password preserved', returningAccountEmail.includes('Your existing account remains active') && !returningAccountEmail.includes('reset_password_url')],
  ['onboarding idempotency retained', php.includes('META_ONBOARDING_SENT') && php.includes("'yes' === $order->get_meta( self::META_ONBOARDING_SENT )")],
  ['refund revocation retained', php.includes('woocommerce_order_refunded') && php.includes('revoke_access') && php.includes("delete_user_meta( $user_id, '_gb_ep_active_course_'")],
  ['test reset requires admin nonce and phrase', php.includes('current_user_can( \'manage_options\' )') && php.includes("check_admin_referer( 'gb_ep_test_reset' )") && php.includes('RESET TEST ACCOUNT')],
  ['test reset requires explicit marker and blocks admins', php.includes('_gb_ep_test_account') && php.includes('reset_marked_test_account') && php.includes("user_can( $user, 'manage_options' )")],
  ['test reset removes disposable state', php.includes('learnpress_user_items') && php.includes("'_gb_ep_active_course_'") && php.includes("'_gb_ep_account_state', 'test_reset'") && php.includes('wp_set_password')],
  ['test reset preserves evidence and adds audit note', php.includes('wc_get_orders') && php.includes('PayPal reference') && php.includes('signed agreement') && php.includes('retained unchanged')],
  ['no Drive Smart branding', !/drive\s*smart|drivesmart/i.test(php + readme + checkoutJs + accountCss)],
  ['single debounced checkout refresh', checkoutJs.includes("trigger('update_checkout')") && checkoutJs.includes('checkoutUpdateTimer') && !checkoutJs.includes('dispatchEvent(new Event')],
  ['no stale development version', !/0\.2\.0-dev-r[1-5]/.test(php + readme)],
];

let failed = 0;
for (const [name, pass] of checks) {
  process.stdout.write(`${pass ? 'PASS' : 'FAIL'} | ${name}\n`);
  if (!pass) failed += 1;
}
process.stdout.write(`TOTAL | ${checks.length - failed} passed | ${failed} failed\n`);
process.exitCode = failed ? 1 : 0;
