# Gulf Breeze Core 2.3.11 Development Validation

Date: 2026-08-14

Artifact: `gulf-breeze-core-2.3.11-dev-checkpoint.zip`

SHA-256: `c394bcdd30aa1d17a6dc5d5375327fb52d7828997839139d1c47f8e74b93c0af`

## Purpose

Correct the production-discovered internal-testing access defect in Core 2.2.1. The installed Core returns a 404 for every regulated LearnPress course, lesson, and quiz for every non-administrator, including the approved synthetic test student.

Core 2.3.11-dev adds an administrator-managed list of approved WordPress test-user IDs. An approved tester is allowed only when the regulated course registry status is `internal_testing`. All other visitors and authenticated users retain the 404 response. The change does not grant capabilities, alter Under Construction, change publication state, claim TDLR approval, or alter instructional content or timers.

## Completed checks

- ZIP integrity: pass.
- Exact `gulf-breeze-core/` plugin root: pass.
- Version header and runtime constant agree at `2.3.11-dev`: pass.
- Prohibited Drive Smart branding scan: pass; no matches.
- Administrator access remains allowed: pass.
- Listed tester plus Internal testing status: allowed.
- Listed tester plus Building status: 404.
- Unlisted authenticated user plus Internal testing status: 404.
- Anonymous visitor plus Internal testing status: 404.
- Non-LMS pages remain unaffected: pass.
- Default WordPress 404 handling remains present: pass.
- Submitted tester IDs are normalized, deduplicated, and limited to existing WordPress users: pass.
- PHP 8.4 lint across every plugin PHP file: pass.
- Disposable WordPress installation with LearnPress: pass.
- Upgrade from public Core 2.2.1 to 2.3.11-dev: pass.
- Existing Gulf Breeze configuration and sentinel options preserved across upgrade: pass.
- Runtime access matrix in disposable WordPress: pass.
- Chromium access as the approved synthetic student: HTTP 200 and expected course heading: pass.
- Chromium access as an anonymous visitor: HTTP 404: pass.

Public validation branch: `agent/core-2.3.11-validation`

Successful workflow commit: `6f4381db884833647cb6f85f60ccf7032d529bb6`

Successful GitHub Actions run: https://github.com/rodneycrawford75006-cell/gulf-breeze-releases/actions/runs/31828702771

## Remaining protected-site checks

- Install the validated checkpoint on the protected live site.
- Add exact WordPress test user ID `2` to the Adult English course registry tester list while the registry remains `internal_testing`.
- Verify the real test-student course and lesson path, lesson timer, early-completion lock, sequential access, mastery gates, and video gates.
- Reconfirm an anonymous visitor still receives the Under Construction page and cannot reach regulated course objects.

The public validation branch and workflow are complete. `main` and `release.json` remain unchanged. Publishing to the release channel or deploying to the protected live site requires a separate explicit authorization.
