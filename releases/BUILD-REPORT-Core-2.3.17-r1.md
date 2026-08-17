# Gulf Breeze Core 2.3.17 Release 1

Date: 2026-08-17  
Scope: Adult English timing rebalance only  
Package: `gulf-breeze-core-2.3.17-r1.zip`  
SHA-256: `4436eecd90cead727d7488703af8f7ae67680ffa1a07246e0a623c27cc599e2f`

## Outcome

Core 2.3.17 adds the bounded curriculum migration checkpoint `2.3.10` for the 18 audited overfilled lessons: 1–16, 18, and 38. Every proposed lesson remains within 120–150 readable words per assigned minute, with a validated safety floor of 123 words per minute.

## Verification

- Exact production-conversion diff reviewed: plugin header, runtime version, migration audit label, and release wording only.
- Static source, key-order, branding, migration-control, and word-band test: PASS.
- Transactional model for rollback, preservation, and idempotent retry: PASS.
- Disposable WordPress + LearnPress 4.4.4 migration regression on the executable checkpoint: PASS.
- Forced post-write preservation failure and full rollback: PASS.
- Successful migration and forced idempotent rerun: PASS.
- Exact curriculum order, objective/timer metadata, course status, registry, sign galleries, and 330-minute ledger preservation: PASS.
- ZIP integrity and package-root checks: PASS.
- Successful GitHub Actions validation run: `32077703757`.

## Preservation boundary

The migration preserves all lesson timers and required objectives. It fails closed unless the Adult English course retains its course status and registry mapping, nine sections, exact ordered 56-item curriculum, 46 lessons, 10 quizzes, existing sign galleries, and 330 instructional minutes. It does not change Under Construction, publication, enrollment, or controlled tester access.
