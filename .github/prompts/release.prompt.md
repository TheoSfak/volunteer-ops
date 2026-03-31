---
description: "Release a new version: bump APP_VERSION, sync 3 folders, git commit, tag, push, and create GitHub release"
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

Read `APP_VERSION` from [config.php](../../config.php). Increment the PATCH number (e.g., 3.58.40 → 3.58.41). If the user specifies a version, use that instead.

### 2. Bump APP_VERSION

Edit `config.php` — update the `APP_VERSION` constant to the new version.

### 3. Check migration version (if migrations changed)

If `includes/migrations.php` was modified, verify that `$LATEST_MIGRATION_VERSION` in `includes/migrations.php` and `LATEST_MIGRATION_VERSION` in `bootstrap.php` are identical. Fix if mismatched.

Run PHP syntax check: `C:\xampp\php\php.exe -l includes\migrations.php`

### 4. Sync 3 folders

```powershell
robocopy "c:\Users\theo\Desktop\VolunteerOps\volunteer-ops-github" "c:\Users\theo\Desktop\VolunteerOps\volunteerops" /MIR /XD .git node_modules /XF .gitignore .gitattributes /NFL /NDL /NJH /NJS /NC /NS /NP
robocopy "c:\Users\theo\Desktop\VolunteerOps\volunteer-ops-github" "C:\xampp\htdocs\volunteerops" /MIR /XD .git node_modules /XF .gitignore .gitattributes /NFL /NDL /NJH /NJS /NC /NS /NP
```

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
