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
  "Version: 0.1.0-dev-r2",
  "const VERSION = '0.1.0-dev-r2'",
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
  "CONTRACT_PAGE_OPTION",
  "provision_page",
  "enrollment-agreement",
  "[gb_enrollment_contract]",
  "[gb_mfa_setup]",
  "woocommerce_get_checkout_url",
  "contract_first_checkout_url",
  "redirect_unsigned_checkout",
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

console.log('PASS Enrollment & Payment 0.1.0 static controls');
