# Gulf Breeze Core 2.3.17 Development Checkpoint

Date: 2026-08-17  
Scope: Adult English timing rebalance only  
Public release: not authorized  
Live installation: not performed  

## Outcome

Core 2.3.17-dev adds the bounded curriculum migration checkpoint `2.3.10` for the 18 audited overfilled lessons: 1–16, 18, and 38. The proposed content preserves the assigned minutes and falls between 120 and 150 readable words per minute. Validation now uses the same tag-stripping and Unicode word-token rules as WordPress/PHP and requires a development safety floor of 123 words per minute.

| Lesson | Minutes | Proposed readable words | Required band |
|---:|---:|---:|---:|
| 1 | 2 | 256 | 240–300 |
| 2 | 3 | 391 | 360–450 |
| 3 | 3 | 402 | 360–450 |
| 4 | 2 | 260 | 240–300 |
| 5 | 4 | 559 | 480–600 |
| 6 | 5 | 639 | 600–750 |
| 7 | 5 | 637 | 600–750 |
| 8 | 5 | 624 | 600–750 |
| 9 | 5 | 632 | 600–750 |
| 10 | 5 | 626 | 600–750 |
| 11 | 8 | 992 | 960–1,200 |
| 12 | 7 | 871 | 840–1,050 |
| 13 | 7 | 870 | 840–1,050 |
| 14 | 8 | 988 | 960–1,200 |
| 15 | 7 | 873 | 840–1,050 |
| 16 | 4 | 508 | 480–600 |
| 18 | 6 | 748 | 720–900 |
| 38 | 5 | 630 | 600–750 |

## Migration controls

- Preflights all 18 lesson bodies before the first database write.
- Replaces only the approved complete lessons or removes the matching prior versioned hardening block.
- Preserves POI objective, source, title, timer, and other lesson metadata by changing only lesson content plus audit/status metadata.
- Preserves any installed `gb-sign-gallery` found in an affected lesson.
- Rolls back prior lesson content and audit/status metadata if a write or final preservation check fails.
- Refuses to finalize unless the course retains its status and registry mapping, 9 sections, exact ordered 56-item curriculum, 46 lessons, 10 quizzes, and 330 instructional minutes.
- Advances only from curriculum version 2.3.9 to 2.3.10 through the existing bounded background worker and lock.

## Completed verification

- Static source, key-order, branding, migration-control, and word-band test: PASS.
- Transactional model for rollback, preservation, and idempotent retry: PASS.
- Disposable WordPress + LearnPress 4.4.4 migration regression: PASS.
- Forced post-write preservation failure and full rollback: PASS.
- Successful migration and forced idempotent rerun: PASS.
- Exact curriculum order, objective/timer metadata, course status, registry, sign galleries, and 330-minute ledger preservation: PASS.
- ZIP integrity test: PASS.
- Package root: `gulf-breeze-core/`.
- Package SHA-256: `2a703e34030779fc2611322820d9b6a8ae2c17d4cd754c32f48ac9ef1fb318a8`.
- Validation branch head: `7aa67f0c02986f17430b863af14c33b7952ed0e5`.
- Successful GitHub Actions run: `32077703757` (50 seconds; August 17, 2026).

## Remaining release boundary

The development checkpoint is validated on the isolated branch. Public release publication and live installation remain separate approval steps and were not authorized by this validation task.

Under Construction remains enabled. No live WordPress content, settings, publication state, enrollment, or access controls were changed during this checkpoint.
