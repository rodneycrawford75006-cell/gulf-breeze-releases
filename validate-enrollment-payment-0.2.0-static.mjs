import fs from 'node:fs';
import path from 'node:path';

const root = process.env.GB_EP_PLUGIN_DIR;
if (!root) throw new Error('GB_EP_PLUGIN_DIR is required.');

const php = fs.readFileSync(path.join(root, 'gulf-breeze-enrollment-payment.php'), 'utf8');
const readme = fs.readFileSync(path.join(root, 'README.md'), 'utf8');
const js = fs.readFileSync(path.join(root, 'assets/checkout.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets/checkout.css'), 'utf8');
const all = [php, readme, js, css].join('\n');

const required = [
  'Version: 0.2.0-dev-r4',
  "const VERSION = '0.2.0-dev-r4'",
  'woocommerce_checkout_before_customer_details',
  'take_checkout_control',
  'block_checkout_stage',
  'disable_payment_gateways',
  'woocommerce_after_checkout_validation',
  'woocommerce_checkout_create_order',
  'woocommerce_checkout_order_processed',
  'woocommerce_store_api_checkout_order_processed',
  'woocommerce_checkout_registration_required',
  'woocommerce_checkout_registration_enabled',
  'woocommerce_checkout_customer_id',
  'woocommerce_payment_complete',
  'woocommerce_order_status_processing',
  'woocommerce_order_status_completed',
  'woocommerce_order_refunded',
  'woocommerce_order_status_refunded',
  'woocommerce_order_status_cancelled',
  'woocommerce_order_status_failed',
  'woocommerce_email_after_order_table',
  'woocommerce_thankyou',
  'handle_create_password_link',
  'send_student_onboarding',
  'woocommerce_created_customer',
  'woocommerce_email_additional_content_customer_new_account',
  'capture_new_account_order',
  'native_new_account_content',
  '_gb_ep_initial_order_id',
  'wc_create_new_customer( $email )',
  'set your new password',
  'no old password is required',
  'native_new_account_email_requested',
  'get_password_reset_key',
  'Create my password',
  'You do not need an old password',
  '_gb_ep_student_onboarding_sent',
  '_gb_ep_student_onboarding_result',
  '_gb_ep_student_account_created',
  'gb_ep_agreement_nonce',
  '_gb_ep_contract_snapshot_json',
  '_gb_ep_contract_snapshot_hash',
  'hash_equals',
  'signed_awaiting_payment',
  'paid_enrolled',
  'paid_learnpress_enrollment_failed',
  'ppcp-gateway',
  'ppcp-credit-card-gateway',
  'get_transaction_id',
  'purchaser_is_student',
  'gb_preferred_locale',
  '_gb_ep_active_course_',
  'UserCourseModel',
  "item->status = 'cancel'",
  'access_revocation_failed',
  'repurchase is allowed',
  'DEVELOPMENT / SAMPLE DATA',
  'nothing is entered twice',
  'no tendrá que ingresar nada dos veces',
  'hash_hmac',
  "wp_salt( 'auth' )",
  'Signed agreement copy',
  'Purchaser email:',
  'Student email:',
  'Final payment and enrollment status is recorded on the WooCommerce order after PayPal confirms payment.',
  'checkoutUpdateTimer',
  "trigger('update_checkout')",
];

for (const needle of required) {
  if (!all.includes(needle)) throw new Error(`Missing required control: ${needle}`);
}

const forbidden = [
  "add_filter( 'woocommerce_get_checkout_url'",
  'woocommerce_paypal_payments_selected_button_locations',
  'limit_paypal_button_locations',
  'wc_get_cart_item_data_hash',
  'initialize_session',
  'initialize_cart',
  'setcookie(',
  'admin_post_gb_ep_contract',
  'gb_enrollment_contract',
  'CONTRACT_PAGE_OPTION',
  'enrollment-agreement',
  'Drive Smart',
  'drivesmart',
  'wp_set_auth_cookie',
  'old_password',
  'current_password',
];

for (const needle of forbidden) {
  if (all.includes(needle)) throw new Error(`Forbidden legacy coupling found: ${needle}`);
}

const constructorStart = php.indexOf('private function __construct()');
const constructorEnd = php.indexOf('public function dependency_notice', constructorStart);
const constructor = php.slice(constructorStart, constructorEnd);
if (constructorStart < 0 || constructorEnd < 0 || constructor.includes('woocommerce_get_checkout_url')) {
  throw new Error('Constructor boundary is invalid.');
}

const cartStart = php.indexOf('private function cart_context()');
const cartEnd = php.indexOf('private function order_context', cartStart);
const cart = php.slice(cartStart, cartEnd);
if (cartStart < 0 || cartEnd < 0 || !cart.includes("is_admin() && ! wp_doing_ajax()") || !cart.includes('! WC()->cart')) {
  throw new Error('Frontend cart guard is missing.');
}

const passwordHandlerStart = php.indexOf('public function handle_create_password_link()');
const passwordHandlerEnd = php.indexOf('public function handle_refund', passwordHandlerStart);
const passwordHandler = php.slice(passwordHandlerStart, passwordHandlerEnd);
for (const needle of ['is_paid()', 'META_PROCESSED', 'hash_equals', 'purchaser_is_student', 'reset_password_url']) {
  if (passwordHandlerStart < 0 || passwordHandlerEnd < 0 || !passwordHandler.includes(needle)) {
    throw new Error(`Password-creation endpoint guard missing: ${needle}`);
  }
}

if (/update_meta_data\s*\([^\n]*(?:password|reset_key)/i.test(php)) {
  throw new Error('Password or reset key appears to be stored in order metadata.');
}

if (php.includes('wc_create_new_customer( $email,')) {
  throw new Error('Plugin supplies a password and suppresses WooCommerce first-time password creation.');
}

const returningEmailStart = php.indexOf('private function send_student_onboarding');
const returningEmailEnd = php.indexOf('private function enroll_course', returningEmailStart);
const returningEmail = php.slice(returningEmailStart, returningEmailEnd);
if (returningEmailStart < 0 || returningEmailEnd < 0 || returningEmail.includes('reset_password_url')) {
  throw new Error('Returning-account notice replaces or resets an existing password.');
}

const adminEmailStart = php.indexOf('if ( $sent_to_admin )');
const adminEmailEnd = php.indexOf("if ( ! in_array( $email->id", adminEmailStart);
const adminEmail = php.slice(adminEmailStart, adminEmailEnd);
if (adminEmailStart < 0 || adminEmailEnd < 0 || adminEmail.includes("echo 'Enrollment state: '") || adminEmail.includes("echo 'Student user ID: '")) {
  throw new Error('Early administrator email exposes stale post-payment fields.');
}

if (js.includes('dispatchEvent(new Event')) {
  throw new Error('Purchaser-is-student copy still dispatches one WooCommerce change event per field.');
}

console.log('PASS Enrollment & Payment 0.2.0-dev-r4 static architecture controls');
