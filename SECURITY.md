# Security policy

VolunteerOps is used during live emergencies and stores personal data of volunteers
and citizens, including real-time location. Security reports are taken seriously and
handled privately.

## Reporting a vulnerability

**Do not open a public issue for a security problem.**

Report privately to **theodore.sfakianakis@gmail.com** with:

- what the issue is and which file or endpoint is affected
- the steps to reproduce it
- the version or commit you tested against
- what an attacker could obtain or do

You will get an acknowledgement within 72 hours. Please allow a reasonable period
for a fix before disclosing publicly.

## Supported versions

Only the latest release receives security fixes. Older versions are not patched —
if you are running an older deployment, upgrade first.

| Version | Supported |
| --- | --- |
| 3.x (latest release) | ✅ |
| < 3.x | ❌ |

## What is in scope

- Authentication and session handling, privilege escalation between roles
- SQL injection, XSS, CSRF, path traversal, arbitrary file upload or inclusion
- Unauthorised access to volunteer or citizen personal data
- Unauthorised access to mission location data, GPS tracks or field media
- Exposure of configuration, credentials or backups

## What is out of scope

- Vulnerabilities in a deployment's own server, PHP version or web server configuration
- Missing hardening headers on someone else's installation
- Reports produced solely by an automated scanner with no demonstrated impact
- Social engineering of an organisation's own members
- The `install.php` wizard being reachable on a host where the operator failed to
  delete it after installation — this is documented in the README as a required step

## Deployment security expectations

VolunteerOps assumes the operator will:

- serve it over HTTPS only, never plain HTTP
- delete `install.php` immediately after the installer finishes
- keep `config.local.php` outside version control (it is git-ignored by default)
- set a strong administrator password during installation
- restrict database access to the application host

An installation that skips these is insecure regardless of the application code.

## Data protection

VolunteerOps processes personal data and real-time location of identified
individuals. Organisations deploying it are the data controller and are responsible
for their own lawful basis, retention policy and — where required — a Data
Protection Impact Assessment. See [docs/](docs/) for the data inventory.
