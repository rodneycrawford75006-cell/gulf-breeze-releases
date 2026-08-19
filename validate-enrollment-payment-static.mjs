import fs from 'node:fs';
import path from 'node:path';

const root = process.env.GB_EP_PLUGIN_DIR;
if (!root) throw new Error('GB_EP_PLUGIN_DIR is required.');
const php = fs.readFileSync(path.join(root, 'gulf-breeze-enrollment-payment.php'), 'utf8');
const readme = fs.readFileSync(path.join(root, 'README.md'), 'utf8');
const js = fs.readFileSync(path.join(root, 'assets/enrollment.js'), 'utf8');
const qr = fs.readFileSync(path.join(root, 'assets/qr-bundle.js'), 'utf8');
const all = [php, readme, js, qr].join('\n');

const required = [
  "Version: 0.1.0-dev-r6",
  "const VERSION = '0.1.0-dev-r6'",
  "const CONTRACT_VERSION = 'DEV-0.1.0'",
  "gb_ep_contracts",
  "gb_ep_events",
  "signed_unpaid",
  "order_created_unpaid",
  "payment_verification_failed",
  "paid_enrolled_mfa_required",
  "paypal_only",
	"take_checkout_control",
  "block_store_api_checkout",
  "CHECKOUT_MODE_OPTION",
  "enforce_classic_checkout_page",
  "[woocommerce_checkout]",
  "remove_express_checkout_blocks",
  "woocommerce/checkout-express-payment-block",
  "woocommerce/cart-express-payment-block",
  "classic-r6:",
  "CONTRACT_PAGE_OPTION",
  "provision_page",
  "enrollment-agreement",
  "[gb_enrollment_contract]",
  "[gb_mfa_setup]",
  "redirect_unsigned_checkout",
  "woocommerce_add_to_cart",
  "invalidate_contract_on_new_enrollment",
  "abandoned_restarted",
  "woocommerce_paypal_payments_selected_button_locations",
  "limit_paypal_button_locations",
  "duplicate_contract_blocked",
  "gb_ep_duplicate_active_course",
  "ensure_woocommerce_cart",
  "handle_frontend_contract_submission",
  "gb_ep_contract_submit",
  "ppcp-gateway",
	"ppcp-credit-card-gateway",
	"woocommerce_paypal_payments_pay_later_enabled",
  "get_transaction_id",
  "hash_equals( $contract['snapshot_hash']",
  "woocommerce_checkout_order_created",
  "woocommerce_payment_complete",
  "woocommerce_order_refunded",
  "partial_refund_admin_cancel",
  "payment_reversal",
  "chargeback_or_failed_capture",
  "_gb_ep_account_state",
  "dormant",
  "gb_preferred_locale",
  "gb_enrollment_locale",
  "student_email_hash",
  "ip_hash",
  "user_agent_hash",
  "student_signature",
  "guardian_signature",
  "purchaser_email",
  "gb_core_provider_profile",
  "gb_ep_mfa_reset",
  "manage_woocommerce",
  "permission_callback",
  "Gulf Breeze Order Health",
  "recovery_codes_issued",
  "wp_check_password",
  "data-gb-mfa-uri",
  "GBLocalQRCode",
  "Copy manual key",
  "Copiar clave manual",
  "DEVELOPMENT / SAMPLE DATA · PAYPAL SANDBOX",
];
for (const needle of required) {
  if (!all.includes(needle)) throw new Error(`Missing required control: ${needle}`);
}

if (/Drive Smart|drivesmart/i.test(all)) throw new Error('Prohibited Drive Smart branding found.');
if (/api\.qrserver|chart\.googleapis|quickchart/i.test(all)) throw new Error('External QR service reference found.');
if (/wp_mail\s*\([^,]+,[^,]+,\s*\$secret/.test(php)) throw new Error('Possible MFA secret transmission.');
if (!php.includes("unset($safe[$forbidden])")) throw new Error('Audit secret suppression is missing.');
if (!php.includes("current_user_can('manage_woocommerce')")) throw new Error('Order Health capability gate is missing.');
if (!php.includes("current_user_can( 'manage_options' )")) throw new Error('Administrator bypass/privacy boundary is missing.');
if (!php.includes("has_block( 'woocommerce/checkout', $content )")) throw new Error('Checkout Block migration guard is missing.');
if (!php.includes("'post_content' => '[woocommerce_checkout]'")) throw new Error('Classic checkout migration is missing.');
if (!php.includes("add_filter( 'render_block', array( $this, 'remove_express_checkout_blocks' )")) throw new Error('Express checkout suppression is not registered.');
if (!php.includes("return array( 'checkout' );")) throw new Error('PayPal placement allowlist is missing.');
if (php.includes("add_filter( 'woocommerce_get_checkout_url'")) throw new Error('Checkout URL filter would corrupt WooCommerce order-received URLs.');

const contextStart = php.indexOf('private function enrollment_purchase_context()');
const contextEnd = php.indexOf('public function limit_paypal_button_locations', contextStart);
const contextBody = php.slice(contextStart, contextEnd);
const adminGuard = contextBody.indexOf("is_admin() && ! wp_doing_ajax()");
const functionGuard = contextBody.indexOf("function_exists( 'wc_get_cart_item_data_hash' )");
const cartInspection = contextBody.indexOf('$this->current_cart_item()');
if (contextStart < 0 || adminGuard < 0 || functionGuard < 0 || cartInspection < 0 || adminGuard > cartInspection || functionGuard > cartInspection) {
  throw new Error('PayPal placement callback can inspect the cart before its administrator/function guards.');
}
if (!php.includes("! function_exists( 'wc_get_cart_item_data_hash' ) || ! class_exists( 'WC_Cart' )")) {
  throw new Error('WooCommerce cart initialization fail-safe is missing.');
}

console.log('PASS Enrollment & Payment 0.1.0 revision 6 static controls');
