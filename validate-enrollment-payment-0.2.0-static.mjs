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
  'Version: 0.2.0-dev-r1',
  "const VERSION = '0.2.0-dev-r1'",
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
  "add_action( 'template_redirect'",
  'admin_post_gb_ep_contract',
  'gb_enrollment_contract',
  'CONTRACT_PAGE_OPTION',
  'enrollment-agreement',
  'Drive Smart',
  'drivesmart',
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

console.log('PASS Enrollment & Payment 0.2.0-dev-r1 static architecture controls');
