# Gulf Breeze Enrollment & Payment 0.2.0-dev-r4

## Purpose

Focused correction for paid Sandbox order #725:

- Make the one delivered native WooCommerce account email contain WooCommerce's valid first-time password-creation link.
- Add Gulf Breeze course, login, and signed-agreement information to that native email.
- Do not require an old password and do not generate a second reset key that would invalidate WooCommerce's first link.
- Keep returning accounts active without silently replacing their password.
- Remove misleading pre-payment state and blank post-payment fields from the early administrator new-order email.
- Preserve the revision 3 purchaser/student checkout-copy correction.

## Local focused validation

- Static/security/behavior checks: **24 passed, 0 failed**.
- Purchaser-is-student checkout regression: **passed**.
- Verified against current official WooCommerce behavior: an omitted password makes WooCommerce generate the initial password and mark the new-account email for first-time password setup; the native new-account email generates its own set-password URL.
- Local PHP CLI: unavailable in the scratch runtime; PHP 8.4 syntax and exact-stack runtime validation remain required on the public validation branch before installation.

## Live test evidence motivating this revision

- PayPal payment completed.
- Student account and LearnPress enrollment completed successfully.
- Final order state became `paid_enrolled`.
- Only the native WooCommerce welcome email reached the student; the second custom onboarding email did not arrive in inbox or spam.
- The delivered welcome email lacked a first-time password link because revision 3 passed an already-generated password to WooCommerce.

## Restrictions

- Development validation candidate only.
- Do not publish to main.
- Do not create a release.
- Do not install live until PHP 8.4 and exact-stack validation pass and Rodney installs the approved ZIP.
- Keep Under Construction enabled.
