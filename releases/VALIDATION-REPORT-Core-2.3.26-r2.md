# Gulf Breeze Core 2.3.26 Revision 2 Validation Report

Date: 2026-08-19  
Disposition: **PASS — development checkpoint only**

## Verified artifact

- Package: `gulf-breeze-core-2.3.26-dev-r2.zip`
- SHA-256: `83f9e865eaf89546dec810467c4246de5c5e1e9da68a2488c8e7d67e3928b1f9`
- Required archive root: `gulf-breeze-core/`
- Validation branch: `agent/core-2.3.26-validation`
- Validation commit: `73446dca9a81933f945abc7120182d0264fc8497`
- GitHub Actions run: https://github.com/rodneycrawford75006-cell/gulf-breeze-releases/actions/runs/32253032137
- Workflow job: `96068225419`

## Revision 2 correction

Legacy provider-profile submissions now use the schema default when a newly introduced field is absent, while preserving an explicitly submitted value. This prevents default-backed legacy records from being normalized to blank values and incorrectly beginning at profile version 2.

## Full public-branch validation

All validation stages passed:

1. Exact SHA-256 verification.
2. ZIP layout and corrected plugin-root verification.
3. Static provider-profile controls, including the revision 2 normalization regression assertion.
4. PHP 8.4 syntax validation across all PHP files.
5. Prohibited-brand scan.
6. Disposable WordPress installation.
7. LearnPress 4.4.4 installation and activation.
8. Gulf Breeze Core package installation and activation in the disposable environment.
9. Provider-profile runtime regression: legacy record begins at version 1 with schema defaults; unchanged save remains version 1 with stable hash/effective timestamp; material change advances to version 2; API, privacy, invalid-email, snapshot, and locked Adult English 330-minute checks pass.
10. Validation fixture did not advance curriculum migrations.

## Safety boundary

The artifact was uploaded only to the public validation branch. It was not published to `main`, no GitHub release was created, and it was not installed on the live site.
