# Old Posts Cleanup Runbook

This runbook is the operational companion to the repository. It is written for real cleanup runs against a WordPress site and assumes that you want a repeatable workflow with audit artifacts, dry-runs, confirmation tokens, and resumable logs.

The commands below use the repository wrapper script, so you only need to define the WordPress root and optional defaults once.

## 1. One-time setup

1. Copy the environment template and edit it for the current site.
2. Source the environment file in the current shell.
3. Keep working from the repository root so relative script paths stay stable.

```bash
cp old-posts.env.example old-posts.env
$EDITOR old-posts.env
source ./old-posts.env
```

The wrapper script reads `old-posts.env`, adds `--path="$OLD_POSTS_WP_ROOT"`, applies optional `--skip-themes` and `--skip-plugins`, and defaults generated artifacts to `"$OLD_POSTS_OUTPUT_DIR"`.

## 2. Backup before any destructive phase

Before you run `trash-posts`, `delete-attachments`, `delete-leftovers`, `force-delete-posts`, or WPML repair with `apply=1`, capture fresh backups of the installation components that this toolkit can change.

Minimum backup set:

- a full database backup
- a full backup or snapshot of the uploads directory
- a copy of the current `OLD_POSTS_OUTPUT_DIR`

Why this is the minimum:

- WordPress deletion routines touch more than one table. Even if your target is "old posts", WordPress can update post rows, post meta, taxonomy relationships, comments-related data, and plugin-owned data through hooks.
- Attachment cleanup and leftovers deletion act on the filesystem. If a file is removed by mistake, the uploads backup is your rollback path.
- The output directory contains the manifest, JSONL logs, leftovers reports, reviewed selections, redirect CSVs, and optional WPML reports that explain what the toolkit did.

If WPML is active, treat the database backup as mandatory before every destructive batch. The toolkit can repair many translation-group inconsistencies conservatively, but a repair script is not a substitute for a real rollback.

Recommended preflight checklist:

1. Take a fresh database backup.
2. Take a fresh uploads backup or storage snapshot.
3. Copy or preserve the current `OLD_POSTS_OUTPUT_DIR`.
4. Confirm that you know how to restore those backups.
5. Only then continue to the destructive phase.

## 3. Session variables

Set these once per shell session. They keep the rest of the commands short and make it easy to rerun a single year without editing file paths everywhere.

Important: commands that redirect output to paths like `>"$OLD_POSTS_OUTPUT_DIR/audit.out"` require these variables to already be exported in the current shell. Loading `old-posts.env` inside `run_wp_old_posts.sh` is too late for those outer shell redirections.

```bash
export MANIFEST_PATH="$OLD_POSTS_OUTPUT_DIR/manifest.json"
export REDIRECTS_PATH="$OLD_POSTS_OUTPUT_DIR/redirects.csv"
export REMAINING_POSTS_REPORT="$OLD_POSTS_OUTPUT_DIR/remaining-posts-$YEAR.json"
export REMAINING_POSTS_CSV="$OLD_POSTS_OUTPUT_DIR/remaining-posts-$YEAR.csv"
export MANUAL_INCLUDE_POSTS="$OLD_POSTS_OUTPUT_DIR/manual-include-$YEAR.txt"
export YEAR="2014"
export YEAR_LIST="2011 2012 2013 2014"
export TRASH_LOG="$OLD_POSTS_OUTPUT_DIR/trash-posts-$YEAR.jsonl"
export ATTACH_LOG="$OLD_POSTS_OUTPUT_DIR/delete-attachments-$YEAR.jsonl"
export LEFTOVERS_REPORT="$OLD_POSTS_OUTPUT_DIR/leftovers-$YEAR.json"
export LEFTOVERS_SELECTION="$OLD_POSTS_OUTPUT_DIR/leftovers-approved-$YEAR.txt"
export LEFTOVERS_DELETE_LOG="$OLD_POSTS_OUTPUT_DIR/leftovers-delete-$YEAR.jsonl"
export FORCE_DELETE_LOG="$OLD_POSTS_OUTPUT_DIR/force-delete-$YEAR.jsonl"
export WPML_REPORT="$OLD_POSTS_OUTPUT_DIR/wpml-consistency-$YEAR.json"
export WPML_JSONL="$OLD_POSTS_OUTPUT_DIR/wpml-consistency-$YEAR.jsonl"
```

## 4. Operating conventions

- Use direct terminal runs for quick dry-runs and short diagnostic commands.
- Use `nohup` for long audits, destructive phases, and heavy reports.
- Watch long-running jobs with `tail -f`.
- Keep each destructive phase in its own JSONL log.
- If a destructive command is missing `confirm=`, rerun it once without the token to capture the expected value printed by the script.
- The wrapper script auto-creates `"$OLD_POSTS_OUTPUT_DIR"` if it does not exist.

Recommended `nohup` pattern:

```bash
source ./old-posts.env
mkdir -p "$OLD_POSTS_OUTPUT_DIR"
nohup sh -c '
./run_wp_old_posts.sh ...command...
' >"$OLD_POSTS_OUTPUT_DIR/some-job.out" 2>&1 &
```

Then monitor it with:

```bash
tail -f "$OLD_POSTS_OUTPUT_DIR/some-job.out"
```

## 5. Generate the manifest

This is the source of truth for the whole workflow. Generate a fresh manifest immediately before a real destructive run.

```bash
nohup sh -c '
./run_wp_old_posts.sh eval-file wp_old_posts_audit.php \
  output="$MANIFEST_PATH" \
  before="$OLD_POSTS_BEFORE" \
  limit="$OLD_POSTS_CHARACTER_LIMIT" \
  post-type="$OLD_POSTS_POST_TYPE" \
  statuses="$OLD_POSTS_STATUSES" \
  batch-size="$OLD_POSTS_AUDIT_BATCH_SIZE" \
  scan-usage=1 \
  progress-every="$OLD_POSTS_PROGRESS_EVERY" \
  progress-seconds="$OLD_POSTS_PROGRESS_SECONDS"
' >"$OLD_POSTS_OUTPUT_DIR/audit.out" 2>&1 &
```

Validation checklist:

- The command finishes successfully.
- The manifest file exists and is readable.
- `summary.by_year` looks plausible for the site you are targeting.
- `summary.safe_attachment_delete_count` is not unexpectedly zero.

## 6. Export redirects

This step is optional, but recommended if you expect to publish redirect or status-code mappings externally.

```bash
./run_wp_old_posts.sh eval-file wp_old_posts_execute.php \
  manifest="$MANIFEST_PATH" \
  phase=export-redirects \
  output="$REDIRECTS_PATH"
```

Notes:

- Only posts marked `redirect.eligible_for_export=true` are exported.
- Drafts remain eligible for deletion, but they are excluded from the redirect CSV by design.

## 7. Trash posts

### Dry-run

```bash
./run_wp_old_posts.sh eval-file wp_old_posts_execute.php \
  manifest="$MANIFEST_PATH" \
  phase=trash-posts \
  year="$YEAR" \
  batch-size="$OLD_POSTS_POST_BATCH_SIZE" \
  dry-run=1
```

### Real run

```bash
nohup sh -c '
./run_wp_old_posts.sh eval-file wp_old_posts_execute.php \
  manifest="$MANIFEST_PATH" \
  phase=trash-posts \
  year="$YEAR" \
  batch-size="$OLD_POSTS_POST_BATCH_SIZE" \
  dry-run=0 \
  log="$TRASH_LOG" \
  confirm=CONFIRM-XXXXXXXX
' >"$OLD_POSTS_OUTPUT_DIR/trash-posts-$YEAR.out" 2>&1 &
```

### Batch run across multiple years

```bash
nohup sh -c '
for year in $YEAR_LIST; do
  echo "=== $(date) | trash-posts year=$year ==="
  ./run_wp_old_posts.sh eval-file wp_old_posts_execute.php \
    manifest="$MANIFEST_PATH" \
    phase=trash-posts \
    year="$year" \
    batch-size="$OLD_POSTS_POST_BATCH_SIZE" \
    dry-run=0 \
    log="$OLD_POSTS_OUTPUT_DIR/trash-posts-$year.jsonl" \
    confirm=CONFIRM-XXXXXXXX || break
done
' >"$OLD_POSTS_OUTPUT_DIR/trash-posts-batch.out" 2>&1 &
```

Validation checklist:

- The target year is present in the log.
- The admin UI shows the posts in the trash.
- Frontend spot checks still behave as expected.

### Optional: review the posts that remained in the same year

Use this when you want a review queue of posts that were not trashed by the current manifest, so you can decide which ones should also be removed before attachment cleanup starts.

Use `year="$YEAR"` for a single year or swap it for `year-list="$YEAR_LIST"` when you want one combined report for all active years in the current run.

```bash
nohup sh -c '
./run_wp_old_posts.sh eval-file wp_old_posts_remaining_posts.php \
  manifest="$MANIFEST_PATH" \
  year="$YEAR" \
  output="$REMAINING_POSTS_REPORT" \
  csv-output="$REMAINING_POSTS_CSV" \
  batch-size="$OLD_POSTS_AUDIT_BATCH_SIZE" \
  progress-every="$OLD_POSTS_PROGRESS_EVERY" \
  progress-seconds="$OLD_POSTS_PROGRESS_SECONDS"
' >"$OLD_POSTS_OUTPUT_DIR/remaining-posts-$YEAR.out" 2>&1 &
```

What the report tells you:

- `review_reason=not_in_manifest_over_char_limit`: the post stayed out because it exceeded the current character limit
- `review_reason=eligible_now_but_missing_from_manifest`: the post currently fits the cutoff but is not present in the manifest; regenerate the manifest before doing more destructive work
- `review_reason=manifest_candidate_still_live`: the post is already in the manifest but is still live; check the `trash-posts` log for why it was skipped
- `review_reason=manifest_candidate_changed_since_manifest`: the post changed after the manifest was generated; regenerate the manifest before continuing

The CSV is usually the easiest file to review manually. When you approve extra posts for deletion, create `"$MANUAL_INCLUDE_POSTS"` with one post ID per line:

```text
# manual include for YEAR=2014
123
456
789
```

Then regenerate the manifest with those approved IDs included:

```bash
nohup sh -c '
./run_wp_old_posts.sh eval-file wp_old_posts_audit.php \
  output="$MANIFEST_PATH" \
  before="$OLD_POSTS_BEFORE" \
  limit="$OLD_POSTS_CHARACTER_LIMIT" \
  post-type="$OLD_POSTS_POST_TYPE" \
  statuses="$OLD_POSTS_STATUSES" \
  batch-size="$OLD_POSTS_AUDIT_BATCH_SIZE" \
  scan-usage=1 \
  include-post-ids-file="$MANUAL_INCLUDE_POSTS" \
  progress-every="$OLD_POSTS_PROGRESS_EVERY" \
  progress-seconds="$OLD_POSTS_PROGRESS_SECONDS"
' >"$OLD_POSTS_OUTPUT_DIR/audit-manual-include-$YEAR.out" 2>&1 &
```

After the manifest is rebuilt, rerun `trash-posts` for the same year. Already trashed posts will be skipped, and the newly approved posts will enter the normal attachment-deletion flow in the next steps.

## 8. Delete attachments

### Dry-run

```bash
./run_wp_old_posts.sh eval-file wp_old_posts_execute.php \
  manifest="$MANIFEST_PATH" \
  phase=delete-attachments \
  year="$YEAR" \
  batch-size="$OLD_POSTS_ATTACHMENT_BATCH_SIZE" \
  dry-run=1 \
  progress-every="$OLD_POSTS_PROGRESS_EVERY" \
  progress-seconds="$OLD_POSTS_PROGRESS_SECONDS"
```

### Real run

```bash
nohup sh -c '
./run_wp_old_posts.sh eval-file wp_old_posts_execute.php \
  manifest="$MANIFEST_PATH" \
  phase=delete-attachments \
  year="$YEAR" \
  batch-size="$OLD_POSTS_ATTACHMENT_BATCH_SIZE" \
  dry-run=0 \
  recheck-usage="$OLD_POSTS_RECHECK_USAGE" \
  log="$ATTACH_LOG" \
  progress-every="$OLD_POSTS_PROGRESS_EVERY" \
  progress-seconds="$OLD_POSTS_PROGRESS_SECONDS" \
  confirm=CONFIRM-XXXXXXXX
' >"$OLD_POSTS_OUTPUT_DIR/delete-attachments-$YEAR.out" 2>&1 &
```

### Batch run across multiple years

```bash
nohup sh -c '
for year in $YEAR_LIST; do
  echo "=== $(date) | delete-attachments year=$year ==="
  ./run_wp_old_posts.sh eval-file wp_old_posts_execute.php \
    manifest="$MANIFEST_PATH" \
    phase=delete-attachments \
    year="$year" \
    batch-size="$OLD_POSTS_ATTACHMENT_BATCH_SIZE" \
    dry-run=0 \
    recheck-usage="$OLD_POSTS_RECHECK_USAGE" \
    log="$OLD_POSTS_OUTPUT_DIR/delete-attachments-$year.jsonl" \
    progress-every="$OLD_POSTS_PROGRESS_EVERY" \
    progress-seconds="$OLD_POSTS_PROGRESS_SECONDS" \
    confirm=CONFIRM-XXXXXXXX || break
done
' >"$OLD_POSTS_OUTPUT_DIR/delete-attachments-batch.out" 2>&1 &
```

Validation checklist:

- The JSONL log shows `delete_attachment` and `progress_checkpoint` entries.
- Frontend spot checks confirm that shared files were not removed incorrectly.
- Media-library spot checks match expectations.

### Optional performance tuning

Use these only when the site content is frozen and the manifest is fresh:

- `recheck-usage=0` skips per-attachment live usage rechecks during the real run.
- `limit-ignore-already-removed=1` makes rerun benchmarks more meaningful.
- `--skip-themes` and a targeted `OLD_POSTS_SKIP_PLUGINS` value can reduce bootstrap overhead.

Benchmark pattern:

```bash
nohup sh -c '
time ./run_wp_old_posts.sh eval-file wp_old_posts_execute.php \
  manifest="$MANIFEST_PATH" \
  phase=delete-attachments \
  year="$YEAR" \
  batch-size=20 \
  limit=30 \
  dry-run=0 \
  recheck-usage=0 \
  limit-ignore-already-removed=1 \
  log="$OLD_POSTS_OUTPUT_DIR/delete-attachments-benchmark-$YEAR.jsonl" \
  confirm=CONFIRM-XXXXXXXX
' >"$OLD_POSTS_OUTPUT_DIR/delete-attachments-benchmark-$YEAR.out" 2>&1 &
```

## 9. Run the optional WPML consistency check

This phase is optional. If WPML is not active on the current site, the script exits cleanly and writes a skipped report instead of failing.

### Report only

```bash
nohup sh -c '
./run_wp_old_posts.sh eval-file wp_old_posts_wpml_consistency.php \
  manifest="$MANIFEST_PATH" \
  log="$ATTACH_LOG" \
  output="$WPML_REPORT" \
  jsonl="$WPML_JSONL"
' >"$OLD_POSTS_OUTPUT_DIR/wpml-consistency-$YEAR.out" 2>&1 &
```

### Apply conservative repairs

Run this only after reviewing the report and only when it marks groups as repairable.

```bash
nohup sh -c '
./run_wp_old_posts.sh eval-file wp_old_posts_wpml_consistency.php \
  manifest="$MANIFEST_PATH" \
  log="$ATTACH_LOG,$FORCE_DELETE_LOG" \
  output="$WPML_REPORT" \
  jsonl="$WPML_JSONL" \
  apply=1 \
  confirm=CONFIRM-XXXXXXXX
' >"$OLD_POSTS_OUTPUT_DIR/wpml-consistency-$YEAR-apply.out" 2>&1 &
```

What this script does when WPML is available:

- inspects affected `trid` groups after destructive phases
- reports orphan rows, missing originals, multiple originals, and inconsistent `source_language_code`
- can apply conservative repairs only when the group is unambiguous

## 10. Generate the leftovers report

Run this after real attachment deletion. It audits files that still exist on disk for deleted attachments or grouped deletions.

```bash
nohup sh -c '
./run_wp_old_posts.sh eval-file wp_old_posts_attachment_leftovers.php \
  manifest="$MANIFEST_PATH" \
  year="$YEAR" \
  log="$ATTACH_LOG" \
  output="$LEFTOVERS_REPORT"
' >"$OLD_POSTS_OUTPUT_DIR/leftovers-$YEAR.out" 2>&1 &
```

### Batch report generation

```bash
nohup sh -c '
for year in $YEAR_LIST; do
  echo "=== $(date) | attachment-leftovers year=$year ==="
  ./run_wp_old_posts.sh eval-file wp_old_posts_attachment_leftovers.php \
    manifest="$MANIFEST_PATH" \
    year="$year" \
    log="$OLD_POSTS_OUTPUT_DIR/delete-attachments-$year.jsonl" \
    output="$OLD_POSTS_OUTPUT_DIR/leftovers-$year.json" || break
done
' >"$OLD_POSTS_OUTPUT_DIR/leftovers-batch.out" 2>&1 &
```

## 11. Build the approved leftovers selection

This phase takes the leftovers report and creates a candidate deletion list. It automatically excludes:

- report items whose base file is still referenced by a live attachment
- exact leftover paths that still have a live `_wp_attached_file` reference

```bash
nohup sh -c '
./run_wp_old_posts.sh eval-file wp_old_posts_leftovers_selection.php \
  report="$LEFTOVERS_REPORT" \
  output="$LEFTOVERS_SELECTION"
' >"$OLD_POSTS_OUTPUT_DIR/leftovers-selection-$YEAR.out" 2>&1 &
```

Review the generated file manually before deleting anything from disk.

## 12. Delete approved leftovers

### Dry-run

```bash
./run_wp_old_posts.sh eval-file wp_old_posts_leftovers_delete.php \
  report="$LEFTOVERS_REPORT" \
  selection="$LEFTOVERS_SELECTION" \
  dry-run=1 \
  log="$LEFTOVERS_DELETE_LOG"
```

### Real run

```bash
nohup sh -c '
./run_wp_old_posts.sh eval-file wp_old_posts_leftovers_delete.php \
  report="$LEFTOVERS_REPORT" \
  selection="$LEFTOVERS_SELECTION" \
  dry-run=0 \
  log="$LEFTOVERS_DELETE_LOG" \
  confirm=CONFIRM-XXXXXXXX
' >"$OLD_POSTS_OUTPUT_DIR/leftovers-delete-$YEAR.out" 2>&1 &
```

Important safeguards:

- The script only deletes files listed in the leftovers report.
- It revalidates live attachment references again before each `unlink()`.
- A stale selection file is still protected by the runtime recheck.

## 13. Force delete posts

Run this only after attachment cleanup and validation are complete for the same year.

### Dry-run

```bash
./run_wp_old_posts.sh eval-file wp_old_posts_execute.php \
  manifest="$MANIFEST_PATH" \
  phase=force-delete-posts \
  year="$YEAR" \
  batch-size="$OLD_POSTS_POST_BATCH_SIZE" \
  dry-run=1
```

### Real run

```bash
nohup sh -c '
./run_wp_old_posts.sh eval-file wp_old_posts_execute.php \
  manifest="$MANIFEST_PATH" \
  phase=force-delete-posts \
  year="$YEAR" \
  batch-size="$OLD_POSTS_POST_BATCH_SIZE" \
  dry-run=0 \
  log="$FORCE_DELETE_LOG" \
  confirm=CONFIRM-XXXXXXXX
' >"$OLD_POSTS_OUTPUT_DIR/force-delete-$YEAR.out" 2>&1 &
```

## 14. Final validation

Repeat this checklist before moving to the next year:

- review the phase log(s)
- review the leftovers report and leftovers delete log when applicable
- confirm that expected posts are gone from the trash after `force-delete-posts`
- run the optional WPML consistency step after the final destructive phase for that year
- perform spot checks on the frontend and in the media library

## 15. Troubleshooting

### A destructive command asks for `CONFIRM-...`

This is expected. The script is protecting the phase. Copy the token from the error output and rerun with `confirm=...`.

### A rerun shows many `already_removed` entries

That is normal after interrupted or repeated runs. Use a new JSONL log for reruns so the log remains easier to inspect.

### The leftovers selection step is slow

The latest scripts batch `_wp_attached_file` lookups, so selection and deletion no longer issue one database query per path. If it is still slow, confirm that you are running the updated versions from this repository.

### You lose the SSH session mid-run

That is exactly why the runbook uses `nohup` for long phases. Reconnect, then inspect the process and follow the `.out` file with `tail -f`.
