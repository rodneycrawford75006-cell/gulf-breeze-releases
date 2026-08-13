# Gulf Breeze Core 2.2.0

Core 2.0.0 preserves the verified Adult English curriculum at 46 instructional lessons and 330 minutes. Its automatic migration adds seven zero-seat-time participation-check shells, one after each Topic 4.1.2–4.1.8 section, so Course Mastery Gate checks can occur without skipping an instructional lesson or placing internal question banks in the visible curriculum.

The course remains a draft and Under Construction protection remains unchanged.

Core 2.1.0 added two dedicated zero-seat-time video-check placeholders after the Topic 4.1.7 CSEA video and Topic 4.1.8 recreational-water-safety video. Core 2.1.1 corrected migration initialization and safely reused shells created by an interrupted run.

Core 2.2.0 corrects the post-import video-check placement. It promotes the two reviewed imported four-question video banks into the Adult English course immediately after the required CSEA and recreational-water-safety videos, retires rather than deletes the empty 2.1 placeholder quizzes, preserves an existing Video Quiz Gate mapping that referenced a placeholder, and refuses to finalize unless the regulated ledger remains exactly 46 instructional lessons / 330 minutes.

The executable 2.2.0 source is contained in `releases/gulf-breeze-core-2.2.0.zip`. The updater package intentionally contains only the changed Core PHP file and README; unchanged 2.1.1 assets remain in place during Deployment Bridge overlay installation.
