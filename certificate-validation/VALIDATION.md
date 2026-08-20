# Gulf Breeze Certificates 0.1.0-dev-r3 — Focused Validation

Date: 2026-08-20

Local result: 60 passed, 0 failed.

Revision 3 adds a frozen student certificate-download term of exactly one calendar year from the signed enrollment timestamp and a private append-only download ledger.

Validated locally and revalidated in the exact-stack workflow:

- Package SHA-256: `7451c0dedb250e4e0b01bb9b28eec4ac9ae15318cb7946608fde57465a210b36`.
- PHP 8.4 syntax for every packaged PHP file and the focused runtime fixture.
- WordPress 7.0.4, WooCommerce 11.0.1, LearnPress 4.4.4, Gulf Breeze Core 2.3.26-dev-r2, Catalog Cart 0.1.4, PayPal Payments 4.1.2, and Enrollment Payment 0.2.0-dev-r8.
- Contract signing UTC is required, persisted on the issuance, and frozen on the order with an expiration exactly one calendar year later.
- Existing issuance records can backfill their signing and expiration timestamps from the linked order.
- Active owner downloads are allowed; wrong-owner, expired, revoked, refunded, cancelled, and failed enrollment paths are denied.
- Administrator retrieval remains permanent while student retrieval stops at the frozen deadline.
- Successful and denied download attempts append actor, subject, outcome, reason, certificate identity, and request UTC to the private ledger.
- A PDF is not released when its integrity check fails or its successful audit row cannot be written.
- English and Spanish My Certificates views disclose the deadline, hide the button after cutoff, and show localized ended-access guidance.
- The administrator page shows frozen access deadlines and the recent download audit.
- The original ADEE templates, static three-page PDFs, 21-column TDLR export contract, mock serial isolation, and localized email behavior remain covered.
- Under Construction configuration is asserted unchanged.

Boundary: this branch is validation-only. Nothing in this workflow publishes to main, creates a release, or installs the plugin on the live site.
