# JobRadar

**English** | [Česky](README.cs.md)

[![CI](https://github.com/vrapa/jobradar/actions/workflows/ci.yml/badge.svg)](https://github.com/vrapa/jobradar/actions/workflows/ci.yml)

JobRadar is an open-source, self-hosted system for discovering, saving, translating, assessing, and managing job opportunities, with privacy built into its workflow. Each user's records stay in their own private instance.

JobRadar does not automatically apply for jobs. Choosing **Respond** (`Reagovat` in the Czech interface) only adds an opportunity to an internal preparation queue. Sending an application is a separate, audited step that requires explicit approval.

The full specification and implementation sequence are in the [implementation plan](docs/implementation-plan.md) (in Czech). The interface and most detailed documentation are currently in Czech.

## Quick start

The repository includes a standalone Docker Compose stack with no dependency on a particular directory, domain, or reverse proxy. Default passwords are intended only for an initial local run; set your own in `.env` before any other deployment.

```powershell
Copy-Item .env.example .env
docker compose up -d --build
docker compose exec web php bin/jobradar database:migrate
docker compose exec web php bin/jobradar user:create-admin user@example.test "Administrator"
```

On Linux or macOS, use `cp .env.example .env` for the first command. The application is available only on the local machine at [http://localhost:8080](http://localhost:8080).

## Operation and configuration

Run the stack from any directory with Docker and Docker Compose available. In `.env`, configure the public application URL (`APP_URL`), secure database passwords, and optionally `APP_PORT`. The default `APP_BIND_ADDRESS` is `127.0.0.1`; change it only when deliberately exposing the application through your own network security or reverse proxy.

Manage the database and administrator account inside the application container:

```powershell
docker compose exec web php bin/jobradar database:migrate
docker compose exec web php bin/jobradar user:create-admin user@example.test "Administrator"
```

The administrator password is entered interactively with hidden input, never as a command argument.

An administrator can create an API client and an expiring token:

```powershell
docker compose exec web php bin/jobradar api:create-client user@example.test "Local MCP" mcp --scope=sources:read --scope=opportunities:read --days=30
```

The full token is shown only when it is created. JobRadar stores its hash and a safe prefix. Specify each permission with a separate `--scope` option.

Source checks are performed by Codex through Chrome and a separate execution MCP with the `search:execute` scope. The demonstration `runner:work-once` command is disabled, and its claim API returns HTTP 410. See the [executor documentation](docs/executor.md) (in Czech) for deployment, security, recovery, and testing.

```powershell
.\executor\install.ps1 -Container <web-container-name>
.\executor\launch.ps1 -Probe
python executor/smoke_stdio.py
```

Replace `<web-container-name>` with the name of your deployed web container. On Windows, installation protects the token using DPAPI and does not claim any source check. Enable production automation only after verifying a scheduled run and completing a pilot with configured sources, a candidate profile, and assessment rules. The [integration verification guide](docs/execution-verification.md) describes the checks to perform.

The general-purpose MCP server uses the official PHP SDK and standard STDIO transport. Start it with `php bin/jobradar-mcp`. It reads the API URL from `JOBRADAR_MCP_API_URL` and its separate token from `JOBRADAR_MCP_TOKEN`. Grant only the scopes required by the client's tools; a read-only client does not need write permissions.

The server provides tools for sources, checks, opportunities, the response queue, assessments, and reversible individual or atomic batch decisions under a time-limited delegation. Creating a delegation requires the separate `decisions:delegate` scope. Grant it only to a client that needs to create delegations following your explicit instruction. No tool sends an application.

Example MCP host configuration (supply an absolute path and keep the token outside Git):

```json
{
  "mcpServers": {
    "jobradar": {
      "command": "php",
      "args": ["C:/absolute/path/jobradar/bin/jobradar-mcp"],
      "env": {
        "JOBRADAR_MCP_API_URL": "http://localhost:8080/api/v1",
        "JOBRADAR_MCP_TOKEN": "<separate MCP token>"
      }
    }
  }
}
```

Inspect configuration and service status:

```powershell
docker compose config
docker compose ps
docker compose logs -f web db
```

Stop the environment while preserving the database:

```powershell
docker compose down
```

Deleting local data is a separate, deliberate operation and is not part of a normal shutdown.

## Backup and restore

SQL backups use a consistent snapshot and never overwrite an existing file. They contain private instance data: keep them out of Git and public repositories, and encrypt them for transfer or long-term storage.

```powershell
docker compose exec web php bin/jobradar database:backup var/backups/jobradar-backup.sql
docker compose cp web:/var/www/html/var/backups/jobradar-backup.sql C:\private-backups\jobradar-backup.sql
```

For safety, `database:restore` refuses a database containing any tables. First create a separate, empty MySQL database, configure its `DB_NAME` and the application user's permissions, then run:

```powershell
docker compose exec -e DB_NAME=jobradar_restore web php bin/jobradar database:restore var/backups/jobradar-backup.sql
```

The password is neither printed nor passed as a process argument. The `mysqldump` and `mysql` clients receive it only through the child process environment. After restoring, verify sign-in, opportunity counts, and the most recent run before using the restored database in production.

## Quality checks

PHP integration tests require `APP_ENV=test` and a separate database named `jobradar_test` or `jobradar_test_<suffix>`. Provision that database and its access permissions before running the suite; never point tests at your normal instance database.

```powershell
docker compose exec -e APP_ENV=test -e DB_NAME=jobradar_test web composer check
npm ci
npm run build
```

GitHub Actions runs the checks against MySQL 8.4 and also verifies repository privacy, reproducible frontend assets, and the standalone Docker image build.

Before committing or pushing, run the privacy guard and review the diff for personal context:

```powershell
python scripts/privacy-check.py --staged
python scripts/privacy-check.py --history
```

Enable the included local hooks with `git config core.hooksPath .githooks`. Automated scanning supplements a human review; it cannot reliably identify personal plans or preferences in ordinary prose.

## Project status

Implemented features include a Nette application for opportunities, assessments, reversible decisions, audited source checks, a versioned API, general-purpose and execution MCP servers, and verifiable backup and restore. Execution supports checkpoints, isolation of assigned work, and a dashboard of incomplete source checks. Each deployment needs its own configuration and integration pilot before production portal traversal.

Concrete follow-up steps are stored as internal `action_items`. Optional Todoist synchronization mirrors them and records links in `external_tasks`. Choosing **Respond** alone does not create a task. The [target workflow](docs/target-workflow.md) (in Czech) describes responsibilities and integration boundaries.

## License

JobRadar is available under the [MIT license](LICENSE). Source code and synthetic demonstration data may be public. Real opportunities, candidate profiles, tokens, logs, and databases do not belong in this repository.

See [SECURITY.md](SECURITY.md) for security reporting and [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidelines.
