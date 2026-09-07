# Security policy

## Supported versions

JobRadar is pre-release software. Security fixes currently target the latest commit on the default branch. A version support table will be published with the first tagged release.

## Reporting a vulnerability

Do not disclose suspected vulnerabilities in a public issue. Contact the maintainer privately through the security contact published on the repository owner profile. Include the affected version, impact, reproduction steps, and any suggested mitigation. Do not include real credentials or personal job-search data.

## Security boundaries

- JobRadar stores private career and application data in the operator's own database; that data is never suitable for the source repository.
- Browser sessions, portal passwords, cookies, MFA codes, and password-manager content must not be stored in JobRadar.
- Runner and MCP clients use scoped, revocable API tokens and never connect directly to MySQL.
- `React`/`Uninteresting` classification changes internal state only. It cannot submit applications, send messages, share personal data, buy credits, or accept terms.
- Text imported from external offers is untrusted content and must be escaped or strictly sanitized before display.
