# Floxum wave 3: where Millwright stopped (paused 2026-11-19)

Paused on Ernest's word ("Yes, pause rewrites") until the usage reset on Nov 26.
`main` holds the work **untagged** (`v1.25.6..5299351`, 36 commits). Do not tag
or deploy it until the Command Center reviews the range. An urgent fix before then
is cut from the `v1.25.6` tag on its own branch, not from `main`.

## Done (all on main, tested)
- **Floxum's two charged findings (v1.25.6 scored 90):**
  - `resolve()` replaced with constructor injection.
  - `ComposerSteps` (1,390 lines) split into Planner, SiteCheck and Finaliser over a shared RunContext.
- **Pre-floxum round 1:** all 18 findings fixed. The highlights:
  - Partial unzip guard.
  - Resumable, locked migration undo.
  - Checked and atomic writes.
  - Start and undo locks.
  - Parallel, time-limited Packagist calls through Host\Web.
  - One route per settings or trash action.
  - FlarumUpdater never sends database credentials.
- **Pre-floxum round 2:** all 23 findings fixed, apart from one that was wrong.
  - An unwritten package list could make an update cover the whole site.
  - An unwritten migration baseline.
  - StateFile for every state write.
  - Run\Starter shared by the CLI and the API.
  - Version restored through the settings repository.
  - Composer settings refused while a run is going.
  - Queue::push for the worker job.
  - One process runner with a time limit.
  - Every function 50 lines or fewer, comments included.
- **Disproved:** "CoreUpgrade counts are never filled" was wrong, because `CorePanel.applyVerdicts` updates them in the browser.

The full list is in the Command Center notes: `side-session-notes/…/millwright-floxum-wave3-review.md`.

## Last results
- **PHPUnit:** 371 pass on SQLite, MySQL and PostgreSQL, with the driver confirmed.
  - Run on MySQL or PostgreSQL with `MW_TEST_DB=mysql|pgsql`. See `tests/unit/Fakes.php::db()` for the container command.
- **Other checks:** PHPStan clean, `tsc` clean, vitest 38 pass, `js/dist` matches a fresh `npm ci` build.
- **Local forum** (`~/Herd/millwrightdev`, https://millwrightdev.test, MySQL, prefix `mw_`, Millwright symlinked from this repo; the admin password is in `~/Herd/millwrightdev-admin.txt`):
  - fof/polls install and undo both pass.
  - Every tab screenshotted light, dark and at 390px.
  - Every new route returns 403 for guests and works for admins.
- **Env matrix** (`tests/env-matrix/run.sh --remote root@103.195.100.103`), at 4d50ef6:
  - fiab, docker, zip, composer-cli and composer-web all pass.
  - The final run on 5299351: **all 134 checks passed** across those five environments.
- **Nightly scenarios fail, already on v1.25.6.** Flarum's current 2.x-dev `flarum/lang-english` calls `LanguagePack::code()`, which only exists in the new core. Core is applied last, so the forum 500s mid-run. This is a separate fix.
- **Pre-floxum round 2** ran at 8c0bde9; every finding has since been fixed. No third pass has been run (paused).

## Released from v1.25.6 during the pause
- **1.25.7 (2026-11-24), the update-check fix** (Claudius, edonline d/83 post 2233): Check for updates offers only versions the forum could install (stability rules plus each version's flarum/core requirement). It was tagged from `fix/update-check-core-constraint` and cherry-picked into main as `f186a7b`, already resolved against the wave. The wave's range now includes it, so the wave's release follows 1.25.7, and its notes shouldn't list this fix again.
- **1.25.8 (2026-11-24)**, after Ian's review of flarum/framework#5604: private-repository versions and the saved check (current(), Repin) are held to the installed core and the stability rules too, an unparseable constraint is a no, 2.x-dev cores are compared, and a check saved before RULES = 2 counts as stale. It was tagged from `fix/update-check-saved-and-private` and cherry-picked into main as `442a085`, resolved against the wave (parallel Packagist read, `currentFor()` via the new `StabilityRule::fromArray()`, offer() and noteVersion() split out for the 50-line rule).
- **1.25.9 (2026-11-26)**, Claudius d/83 posts 2237 and 2239. Installed betas that the minimum stability no longer allows no longer block every update. The fix: ask by name, then add a per-package `@beta` flag on the admin's yes (`allowPrereleases` / `--allow-prereleases`), and refuse dependency-only betas with advice. It was tagged from `fix/locked-prereleases` and ported onto main by hand as `d278dd1`: the check is in Run\Starter, the flags go through RunContext to Planner::editRequirements(), and the question is in prereleaseQuestion.ts. The announcer change that shows plain names on GitHub releases is on main as `da150ad`.

## Left, in order, after Nov 26
1. Confirm CI is green on main (the GitHub API was rate-limited at the pause).
2. Optionally, one more pre-floxum pass to confirm the list is empty.
3. Send the Command Center the range, score and tests (the review note above), and wait for approval.
4. Tag (the drafter bumps from the `fix:` subjects).
5. Deploy: dev, then the showcase via `bin/deploy`, then the live forums in the rules' order, one at a time.
6. Post the changelog on edonline only; Discuss is paused.
7. Separately: fix the nightly language-pack ordering, either by applying core first for a nightly or by swapping the flarum/* packages together.
