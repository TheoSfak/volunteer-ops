# Contributing to VolunteerOps

Thanks for wanting to help. VolunteerOps is used by volunteer rescue teams during
real emergencies, so the bar for changes is "would I trust this at 3am in the rain".

## Before you write code: the licensing agreement

VolunteerOps is **dual-licensed** — AGPL-3.0 for everyone, plus commercial licences
for organisations that cannot accept the AGPL's obligations. That only works if the
project holds the rights to relicense all of its code.

**By submitting a pull request you agree that:**

1. your contribution is licensed under the AGPL-3.0, **and**
2. the maintainer may include it in commercially licensed distributions of
   VolunteerOps, and
3. you have the right to grant this — the code is yours, or your employer has
   agreed to it.

If you are not comfortable with that, please open an issue to discuss before
writing code rather than sending a pull request that cannot be merged.

## Reporting bugs

Open an issue with:

- what you expected and what happened instead
- the exact steps to reproduce
- your PHP and MySQL/MariaDB versions
- the version or commit you are running
- relevant log output, with personal data removed

**Security problems do not go in issues** — see [SECURITY.md](SECURITY.md).

## Suggesting features

Say what operational problem it solves, not just what the feature is. "Team leaders
lose track of which sectors were already swept when shifts change" is actionable;
"add a sectors tab" is not. Field experience from real missions is especially
welcome — that is where most of this system came from.

## Pull requests

Keep them focused. One change per pull request; a bug fix and a refactor in the
same branch is two pull requests.

**House rules, matching the existing codebase:**

- Plain PHP 8.0+. No framework, no build step, no Composer runtime dependency.
- **Every** SQL query uses PDO prepared statements. Never concatenate input into SQL.
- **Every** output is escaped through `h()` / `htmlspecialchars`.
- **Every** POST form carries and validates a CSRF token.
- Check the caller's role before any privileged action — do not rely on the UI
  having hidden the button.
- Log administrative actions to the audit log.
- User-facing strings go through the translation layer; the interface ships in
  Greek and English and must stay usable in both.
- Match the surrounding code's naming and structure rather than introducing a new
  style.

**Anything touching personal data or location** — volunteers, citizens, GPS tracks,
field media — gets extra scrutiny. Say in the pull request description what data
your change stores, exposes, or retains.

## Database changes

Schema changes need a migration in `sql/migrations/` that runs cleanly against an
existing installation. Do not modify `schema.sql` alone — existing deployments will
never see it.

## Testing your change

There is no automated test suite covering most of the application. Before opening a
pull request, exercise the affected screens manually against a local installation
and say in the description what you tested and in which roles.

## Questions

Open an issue, or email theodore.sfakianakis@gmail.com.
