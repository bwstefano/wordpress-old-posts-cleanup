# Old Posts Cleanup Toolkit for WordPress

This repository contains a guarded WordPress cleanup workflow for removing short legacy posts and the media tied to them. It was designed for repeatable WP-CLI operations with audit artifacts, dry-runs, resumable logs, and optional WPML-aware cleanup.

The toolkit is meant for operators who want more than a one-off SQL script:

- a manifest of candidate posts and attachments
- explicit dry-run and real-run phases
- redirect export support
- media usage checks before attachment deletion
- filesystem leftovers reporting and controlled cleanup
- orphan `post_tag` relationship cleanup after force-deleting posts
- optional WPML translation consistency checks after destructive phases

## What the toolkit does

At a high level, the workflow is:

1. Audit the site and generate a manifest of deletion candidates.
2. Export redirects for public posts if needed.
3. Move posts to the trash year by year.
4. Optionally report the posts that remained in the same years and manually approve extra post IDs.
5. Delete only attachments that are considered safe.
6. Audit leftovers on disk and build a reviewed deletion list.
7. Delete approved leftovers from disk.
8. Force-delete the posts after validation.
9. Clean orphan `post_tag` relationships and recount tag counts.
10. Optionally verify and repair WPML translation consistency.

## Safety model

The scripts are designed around guardrails instead of raw speed:

- destructive phases require explicit confirmation tokens
- post deletion is checked against a manifest fingerprint
- attachments are not deleted when external usage is detected
- leftovers deletion is restricted to files present in the leftovers report
- leftovers deletion rechecks live attachment references before each `unlink()`
- orphan tag cleanup is hard-limited to `post_tag` relationships whose `object_id` no longer exists in `wp_posts`
- WPML-specific repair logic runs only when WPML is available and the group is unambiguous

## Repository layout

- `run_wp_old_posts.sh`
  The wrapper used by the documentation. It loads `old-posts.env`, sets the WP-CLI bootstrap path, applies optional skip flags, and defaults generated artifacts to `OLD_POSTS_OUTPUT_DIR`.
- `old-posts.env.example`
  Shell configuration template. Copy it to `old-posts.env` and edit it once per project or environment.
- `wp_old_posts_audit.php`
  Builds the manifest of candidate posts and candidate attachments.
- `wp_old_posts_execute.php`
  Runs the main phases: `export-redirects`, `trash-posts`, `delete-attachments`, and `force-delete-posts`.
- `wp_old_posts_orphan_tag_relationships.php`
  Reports and optionally removes orphan `post_tag` rows from `wp_term_relationships`, then recounts `post_tag` terms after a real cleanup.
- `wp_old_posts_remaining_posts.php`
  Reports the posts that remained live in the target years after `trash-posts`, with review reasons and an optional CSV for manual triage.
- `wp_old_posts_attachment_leftovers.php`
  Audits files left on disk after real attachment deletion.
- `wp_old_posts_leftovers_selection.php`
  Turns a leftovers report into a reviewed selection file while excluding still-referenced paths.
- `wp_old_posts_leftovers_delete.php`
  Deletes approved leftovers from disk with another live-reference check before each delete.
- `wp_old_posts_wpml_consistency.php`
  Optionally inspects and conservatively repairs WPML translation groups affected by destructive phases.
- `wp_old_posts_common.php`
  Shared helpers used across the PHP scripts.
- `RUNBOOK.md`
  Step-by-step operational documentation.

## Requirements

- WordPress with WP-CLI access
- filesystem access to the WordPress uploads directory
- database access through WordPress runtime
- permission to execute `wp eval-file`

WPML is optional. The core audit and deletion workflow works without it. When WPML is not installed or not active, the WPML-specific consistency script exits cleanly with a skipped report.

## Backup requirements

Do not use this toolkit against a real site unless you have current backups of every part of the installation that the workflow can change.

At minimum, back up:

- the full WordPress database
- the full uploads directory
- the generated operation artifacts under `OLD_POSTS_OUTPUT_DIR`

Why these backups matter:

- The database backup is the rollback path for post deletion, attachment deletion, orphan tag relationship cleanup, post meta changes, redirect export inputs, and any side effects triggered by WordPress hooks during deletion.
- The uploads backup is the rollback path for real attachment deletion and approved leftovers deletion. Even when you are operating year by year, shared attachment files can be referenced by more than one post or translation group, so a narrow per-year filesystem backup is usually a poor substitute for a full uploads backup.
- The operation-artifact backup preserves the manifest, JSONL logs, leftovers reports, orphan tag relationship reports, reviewed selection files, and WPML consistency reports that explain what happened during each phase.

If WPML is active, treat the database backup as mandatory, not optional. Translation-group consistency is repairable in many cases, but only a database backup gives you a reliable full rollback.

The safest operational pattern is:

1. Take a fresh database backup.
2. Take a fresh uploads backup or storage snapshot.
3. Preserve or copy the current `OLD_POSTS_OUTPUT_DIR`.
4. Verify that you know how to restore those backups before starting any destructive run.

## One-time setup

1. Copy the environment template.
2. Edit the required variables.
3. Source the file in your shell.

```bash
cp old-posts.env.example old-posts.env
$EDITOR old-posts.env
source ./old-posts.env
```

Required variable:

- `OLD_POSTS_WP_ROOT`

Common optional variables:

- `OLD_POSTS_OUTPUT_DIR`
- `OLD_POSTS_SKIP_THEMES`
- `OLD_POSTS_SKIP_PLUGINS`
- `OLD_POSTS_BEFORE`
- `OLD_POSTS_CHARACTER_LIMIT`
- `OLD_POSTS_STATUSES`
- `OLD_POSTS_AUDIT_BATCH_SIZE`
- `OLD_POSTS_POST_BATCH_SIZE`
- `OLD_POSTS_ATTACHMENT_BATCH_SIZE`
- `OLD_POSTS_PROGRESS_EVERY`
- `OLD_POSTS_PROGRESS_SECONDS`
- `OLD_POSTS_RECHECK_USAGE`

## Character threshold

The character threshold is controlled by the audit phase. By default, the repository examples use:

```bash
limit="$OLD_POSTS_CHARACTER_LIMIT"
```

If you want to include longer or shorter posts, change `OLD_POSTS_CHARACTER_LIMIT` in `old-posts.env` or pass a different `limit=` value directly to `wp_old_posts_audit.php`.

Important: if you change the threshold, generate a new manifest before doing any destructive run. The manifest is the contract used by the later phases.

## WPML behavior

WPML support is deliberately optional:

- the audit manifest records WPML context when available
- localized permalinks are resolved through the official WPML filters when WPML is active
- grouped attachment deletion logic only applies when the surviving attachments belong to the same WPML translation group and share the exact same `_wp_attached_file`
- the dedicated WPML consistency script skips itself cleanly when WPML is unavailable

If your site does not use WPML, you can ignore the WPML consistency phase entirely.

## Output layout

By default, the wrapper exports:

- `OLD_POSTS_TOOL_DIR`
- `OLD_POSTS_OUTPUT_DIR`

and creates the output directory automatically. This makes it practical to keep manifests, logs, CSVs, and reports under the repository rather than scattering them across ad hoc temporary directories.

## How the main scripts fit together

### 1. `wp_old_posts_audit.php`

Purpose:

- scan posts older than a cutoff date
- keep only posts under a plain-text character threshold
- collect candidate attachments
- optionally scan global attachment usage
- produce a manifest with summaries and deletion metadata

Key inputs:

- `before=`
- `limit=`
- `post-type=`
- `statuses=`
- `batch-size=`
- `scan-usage=`
- `include-post-ids=`
- `include-post-ids-file=`

Key outputs:

- manifest JSON

### 2. `wp_old_posts_execute.php`

Purpose:

- export redirects
- move posts to the trash
- delete safe attachments
- force-delete posts already in the trash

Key phases:

- `phase=export-redirects`
- `phase=trash-posts`
- `phase=delete-attachments`
- `phase=force-delete-posts`

Key protections:

- confirmation tokens for destructive real runs
- manifest fingerprint check for posts
- optional external usage recheck for attachments
- `delete-attachments` can reuse one or more prior JSONL logs through `resume-log=...`
- runtime cache cleanup between destructive batches to keep long WP-CLI runs stable
- JSONL logs for resumable operations

### 3. `wp_old_posts_remaining_posts.php`

Purpose:

- inspect the target years after `trash-posts`
- list the posts that are still live
- flag whether each post is already in the manifest or would need manual inclusion
- optionally export the review list as CSV

### 4. `wp_old_posts_attachment_leftovers.php`

Purpose:

- inspect the uploads directory after real attachment deletion
- report base files and derivatives still present on disk
- map grouped deletions back to the correct manifest attachment when WPML grouped deletion was used

### 5. `wp_old_posts_leftovers_selection.php`

Purpose:

- convert the leftovers report into a text file of candidate paths for deletion
- exclude items whose base file is still referenced
- exclude exact leftover paths still referenced by live attachments

### 6. `wp_old_posts_leftovers_delete.php`

Purpose:

- consume a reviewed selection file
- delete only files present in the leftovers report
- revalidate live attachment references before deleting each file

### 7. `wp_old_posts_wpml_consistency.php`

Purpose:

- read the manifest and destructive-phase logs
- inspect affected WPML translation groups
- report orphan translation rows and inconsistent originals
- optionally apply conservative repairs

### 8. `wp_old_posts_orphan_tag_relationships.php`

Purpose:

- report orphan `post_tag` relationships left behind after posts have been force-deleted
- group the report by `term_id`, `term_taxonomy_id`, tag `name`, `slug`, stored count, orphan relationship count, and orphan object IDs
- delete only `wp_term_relationships` rows where the joined taxonomy is `post_tag` and the related `object_id` is missing from `wp_posts`
- recount all `post_tag` terms after a real cleanup

Key protections:

- dry-run by default
- confirmation token required for `dry-run=0`
- report and JSONL log paths default under `OLD_POSTS_OUTPUT_DIR`
- no option exists to target other taxonomies

## Operational guidance

- Take fresh database and uploads backups before every destructive batch, not only before the first test run.
- Preserve `OLD_POSTS_OUTPUT_DIR` together with your infrastructure backups so the manifest and logs stay available for audit and rollback analysis.
- Use `nohup` for long audits and destructive runs.
- Use fresh log files for reruns whenever possible, then pass the older `delete-attachments` logs back through `resume-log=` when you want to skip work that already finished.
- Validate the site between phases, not only at the end.
- Treat leftovers reports as review artifacts, not as auto-delete commands.
- Run the orphan tag relationship cleanup after the final `force-delete-posts` run for the cleanup scope, especially when tag counts look inflated after old posts are gone.
- If you change the deletion criteria, generate a new manifest.

For the step-by-step commands, use `RUNBOOK.md`.

🤖 Created with Codex.
