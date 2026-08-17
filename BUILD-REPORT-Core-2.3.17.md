# Gulf Breeze Core 2.3.17 Development Checkpoint

Date: 2026-08-17  
Scope: Adult English timing rebalance only  
Public release: not authorized  
Live installation: not performed  

## Outcome

Core 2.3.17-dev adds the bounded curriculum migration checkpoint `2.3.10` for the 18 audited overfilled lessons: 1–16, 18, and 38. The proposed content preserves the assigned minutes and falls between 120 and 150 readable words per minute.

| Lesson | Minutes | Proposed readable words | Required band |
|---:|---:|---:|---:|
| 1 | 2 | 260 | 240–300 |
| 2 | 3 | 398 | 360–450 |
| 3 | 3 | 409 | 360–450 |
| 4 | 2 | 264 | 240–300 |
| 5 | 4 | 567 | 480–600 |
| 6 | 5 | 610 | 600–750 |
| 7 | 5 | 638 | 600–750 |
| 8 | 5 | 624 | 600–750 |
| 9 | 5 | 607 | 600–750 |
| 10 | 5 | 603 | 600–750 |
| 11 | 8 | 970 | 960–1,200 |
| 12 | 7 | 843 | 840–1,050 |
| 13 | 7 | 851 | 840–1,050 |
| 14 | 8 | 966 | 960–1,200 |
| 15 | 7 | 844 | 840–1,050 |
| 16 | 4 | 491 | 480–600 |
| 18 | 6 | 726 | 720–900 |
| 38 | 5 | 631 | 600–750 |

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
- ZIP integrity test: PASS.
- Package root: `gulf-breeze-core/`.
- Package SHA-256: `f0bbc39fc1fd0f22ef389e1e7e335a3a8c23ba75ca54817748f0ca55dab89738`.

## Remaining release gate

The current workspace has no PHP runtime or disposable WordPress installation. A dedicated WordPress + LearnPress 4.4.4 regression fixture and manually dispatched CI workflow are staged beside the package but have not been published or run. They cover PHP syntax, forced preservation failure and full rollback, successful migration, idempotent forced rerun, exact curriculum-order comparison, objective and timer metadata, course status, registry mapping, galleries, and the 330-minute ledger.

Before publishing or installing this checkpoint, obtain explicit approval to place the development checkpoint and validation workflow on a GitHub validation branch, run that disposable test, and review its evidence. Public release publication and live installation remain separate approval steps.

Under Construction remains enabled. No live WordPress content, settings, publication state, enrollment, or access controls were changed during this checkpoint.
