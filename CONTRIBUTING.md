# Contributing to JobRadar

Thank you for helping improve JobRadar. Please open an issue before starting a large behavioral or architectural change.

## Development

JobRadar requires PHP 8.4, Composer 2, Node.js 20.19 or newer, and MySQL 8.4. Install dependencies and build assets with:

```bash
composer install
npm install
npm run build
```

Run the quality checks before submitting a change:

```bash
composer check
npm run build
```

GitHub Actions repeats these checks against MySQL 8.4, verifies that generated frontend assets are committed, and builds the standalone Docker image. Pull requests should keep the `CI` workflow green.

Functional changes must update `docs/implementation-plan.md`. Keep domain behavior outside presenters and API handlers so the web UI and versioned API use the same services.

## Safety and test data

Never commit real job offers, candidate profiles, CVs, application correspondence, database dumps, access tokens, cookies, MFA codes, logs, or credentials. Tests and documentation must use visibly synthetic identities and data.

Classification actions must never submit an application, send a message, disclose personal data, purchase anything, or accept legal terms. A submission workflow requires a separate audited action and explicit user approval.
