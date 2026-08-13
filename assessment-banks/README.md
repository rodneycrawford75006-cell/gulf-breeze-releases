# Gulf Breeze Adult English Final Assessment Pack

This pack uses the proven GulfBreeze LearnPress Question Importer and Course Mastery Gate architecture.

## Reviewed banks

- `gulf-breeze-adult-final-highway-signs-bank.csv`: 50 highway-sign questions with official sign images.
- `gulf-breeze-adult-final-traffic-laws-bank.csv`: 50 traffic-law questions.

The original assessment wording was developed from the Texas Driver Handbook DL-7, revised January 2026, and the TDLR Adult Six-Hour POI, revised May 2026. Correct-answer positions are deterministically balanced instead of placing every correct answer in the same column.

## Installation order

1. Install and activate Gulf Breeze Core 1.9.0. Confirm Topic 4.1.9 contains three instructional-review lessons totaling 52 minutes plus the separate zero-seat-time final-assessment shell.
2. Install and activate GulfBreeze LearnPress Question Importer 0.1.2.
3. In **GB Question Importer**, preview and then import each CSV.
4. Do not add either internal bank to the visible course curriculum.
5. Install and activate GulfBreeze Course Mastery Gate 0.2.0.
6. Use its Course Item Reader to obtain the Adult English course ID and the item ID for **Adult English Comprehensive Final Assessment**.
7. Add one active mapping:
   - Type: `Adult Final Exam`
   - Start item: `Adult English Comprehensive Final Assessment`
   - Primary bank: `Gulf Breeze Adult Final — Highway Signs Bank`
   - Second bank: `Gulf Breeze Adult Final — Traffic Laws Bank`
   - Required bank size: `15`
   - Questions to show: `30`
   - Passing score: `70`
   - Next item: `0` when the final is the last curriculum item
8. Save and confirm the mapping health result is **OK**.

## Enforced result

The controlled final draws 15 highway-sign questions and 15 traffic-law questions, rotates questions across retests, suppresses answer disclosure, records selection and response audit events, requires 70 percent mastery, blocks forward completion after a failure, and locks the final after three failed attempts until an administrator reviews and clears the lockout.

Version 0.2.0 also applies a server-enforced 45-minute final-assessment limit, binds an attempt to the signed-in WordPress session and browser identity, signs the server-selected question set, preserves a stable randomized answer order for the attempt, requires a student integrity attestation, and records blocked security submissions without changing a valid score.

The final assessment carries zero instructional minutes and does not alter the locked 330-minute Adult English seat-time total.
