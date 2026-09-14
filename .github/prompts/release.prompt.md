---
description: "Release a new version: bump APP_VERSION and sw.js CACHE_VERSION, sync the XAMPP copy, git commit, tag, push, and create GitHub release"
agent: "agent"
argument-hint: "Short description of what changed"
---

Release a new version of VolunteerOps. Follow every step below in order. Do NOT skip any step.

Refer to [copilot-instructions.md](../copilot-instructions.md) for project conventions.

## Input

The user provides: **{{ input }}**

Use this as the release description. If empty, check `git diff --cached --stat` or recent uncommitted changes to generate a description.

## Steps

### 1. Determine version

Read `APP_VERSION` from [config.php](../../config.php). If the user specifies a
version, use that instead.

Otherwise default to a **MINOR** bump — `3.232.0` → `3.233.0` — for any release
that ships new work. That is what this project actually does: 28 of the 40 tags
before 2026-09-15 were minor bumps. The patch position is reserved for a
follow-up fix to a release that has already gone out (`v3.223.0` → `.1` → `.2`,
`v3.216.0` → `.1` → `.2` → `.3`).

This step used to say "increment the PATCH number", which had not matched
practice for well over a hundred releases.

### 2. Bump APP_VERSION

Edit `config.php` — update the `APP_VERSION` constant to the new version.

### 2b. Bump CACHE_VERSION to match — every release, no exceptions

Edit `sw.js` — set `CACHE_VERSION` to `'vo-v<the same version>'`.

This is not optional and not a judgement call about whether a cached asset
"really" changed. `CACHE_VERSION` is the **only version marker this app exposes
publicly**, so it is the only way to confirm what a live site is actually
running without logging in. It was left at `vo-v3.212.0` for seventeen
releases, and the result was two deploys that could not be verified afterwards
at all.

CI enforces it: the `Version markers agree` step in
[ci.yml](../workflows/ci.yml) fails the build when the two disagree, so a
release that skips this will not go green.

Bumping costs a one-off refetch of CDN assets and static images. It does not
touch the map tile cache, which is deliberately kept out of `CACHE_VERSION` —
a volunteer who panned their mission area into cache must not lose it to an
unrelated release.

To verify a deployment afterwards, with no credentials needed:

```powershell
curl.exe -s https://yphresies.gr/sw.js | Select-String "CACHE_VERSION"
curl.exe -s https://epidrasis.iloveweb.gr/sw.js | Select-String "CACHE_VERSION"
```

### 3. Check migration version (if migrations changed)

If `includes/migrations.php` was modified, verify that `$LATEST_MIGRATION_VERSION` in `includes/migrations.php` and `LATEST_MIGRATION_VERSION` in `bootstrap.php` are identical. Fix if mismatched.

Run PHP syntax check: `C:\xampp\php\php.exe -l includes\migrations.php`

### 4. Sync the XAMPP copy

```powershell
robocopy "c:\Users\theo\Desktop\VolunteerOps\volunteer-ops-github" "C:\xampp\htdocs\volunteerops" /MIR /XD .git .claude node_modules uploads vendor tests /XF .gitignore .gitattributes config.local.php /NFL /NDL /NJH /NJS /NC /NS /NP
```

**`.claude` is excluded for the same reason `.git` is, and it matters more than
it looks.** `.claude/worktrees/` holds real git worktrees — each one a complete
second checkout of this application, `config.php` included. Without that
exclusion the sync copies every one of them into the web root, where they are
reachable over HTTP and drift silently out of date. This was found on
2026-09-15 with two worktrees mirrored into `C:\xampp\htdocs\volunteerops\.claude\`
(922 files, 28 MB); they were deleted, and the real worktrees in the repo were
unaffected because the copies were only ever plain files, never registered
worktrees. `.claude/settings.local.json` rides along for free.

**XAMPP only.** The old second target,
`c:\Users\theo\Desktop\VolunteerOps\volunteerops`, must not be synced any
more — the owner said so explicitly.

**`/MIR` mirrors, which means it deletes anything in the destination that is
not in the source.** `config.local.php` is gitignored, so it exists only in the
XAMPP copy and `/MIR` would wipe it — taking `DEBUG_MODE = true` with it, which
is what makes this machine surface the PHP warnings the live sites surface.
That is why it is in `/XF` above. If anything else untracked is ever kept in
the web root, add it there too, and check **before** running this, not after.

To actually do that check, run the same command with `/L` (list only) and
**without** `/NFL /NDL /NC`, then look for robocopy's `*EXTRA` marker:

```powershell
$dry = robocopy "c:\Users\theo\Desktop\VolunteerOps\volunteer-ops-github" "C:\xampp\htdocs\volunteerops" /MIR /L /XD .git .claude node_modules uploads vendor tests /XF .gitignore .gitattributes config.local.php /NJH /NJS /NP
$extra = $dry | Where-Object { $_ -match '^\s*\*EXTRA' }
Write-Output "MIR would delete: $($extra.Count)"
$extra
```

Both suppression flags matter, and both were got wrong on 2026-09-15:

- `/NFL /NDL` hide file and directory names, so **no `*EXTRA` line is printed
  at all** and the check silently reports zero.
- `/NC` hides the class column, which is *where the word `*EXTRA` lives* — same
  false all-clear, for a different reason.

Anchor the match with `^\s*\*EXTRA`. A bare substring search for `EXTRA` also
lies: it matches build directories with names like
`extractDeepLinksDebug`, which produced 15 false positives on a run whose real
deletion count was zero.

Do not trust the exit code as a substitute. Exit 3 means "files copied **and**
extras present", but it is also returned in cases where the extras were only
listed, so it cannot tell you on its own whether anything will be destroyed.

Dev-only tooling (`vendor/`, `tests/`) never belongs in a deployed copy — Composer dev dependencies and test source have no reason to be reachable from a web root. If a real production deploy is ever done by copying this repo directly (rather than a checkout), exclude the same two directories there too.

Robocopy exit code 1 = files copied (success).

### 5. Git commit

```powershell
cd "c:\Users\theo\Desktop\VolunteerOps\volunteer-ops-github"
git add -A
git status
```

Review staged files, then commit with message: `vX.Y.Z: <description>`

### 6. Tag and push

```powershell
git tag vX.Y.Z
git push origin main --tags
```

### 7. GitHub release

```powershell
gh release create vX.Y.Z --title "vX.Y.Z - <Short Title>" --notes "<release notes with ## heading and bullet points>"
```

Generate meaningful release notes from the staged changes — group by feature area.

### 8. Confirm

Report the final version, release URL, and number of files changed.
