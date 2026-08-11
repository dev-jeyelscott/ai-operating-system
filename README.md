# AI Operating System

The AI Operating System is a simulation-first, provider-independent software-development orchestration platform presented through an interactive 3D office and an accessible application dashboard.

The product coordinates three controlled delivery layers:

1. **Planning and Project Intelligence** — analyzes approved project documentation, generates the roadmap, decomposes work into phases and tickets, and publishes approved tickets to Notion.
2. **Development Execution** — selects the next workable ticket, executes or simulates the implementation, and prepares pull-request evidence targeting `develop` only.
3. **Independent QA and Merge Advisory** — validates completed work, identifies risks, and provides an evidence-backed merge recommendation.

The MVP uses real workflow infrastructure, real Notion ticket publication, and simulated engineering execution. Simulated output must never be represented as verified implementation, CI, review, merge, or deployment evidence.

---

## Documentation source of truth

The active project documents are:

1. **`ai-operating-system-full-specification-v1.2.md`** — complete product, domain, workflow, security, reliability, and delivery specification.
2. **`ai-operating-system-baseline-v1.2.md`** — concise, non-negotiable product and architecture baseline.
3. **`ai-operating-system-mvp-specification-v1.0.md`** — buildable MVP scope, modules, integrations, scenarios, acceptance criteria, and Definition of Done.
4. **Notion: AI Operating System — Delivery Tracker** — the canonical detailed
   roadmap and task authority. Database ID:
   `ab04e5c5-cea3-8310-b536-81092834fdba`; data-source ID:
   `15f4e5c5-cea3-8362-9ce6-07add7b903ab`. Retrieve the database by its stable
   ID, then query the data source by `Ticket ID`. AIOS-294 governs release
   candidate evidence and approval.
5. **`CHANGELOG-v1.2.md`** — documentation changes introduced in version 1.2.

When documents overlap, apply this decision order:

1. Full Product and Technical Specification v1.2
2. Final Baseline Architecture v1.2
3. Final MVP Specification v1.0 for MVP implementation details
4. Approved ADRs and later explicitly approved change decisions

---

## Technology stack

### Application

- Laravel 13
- PHP 8.5
- Inertia.js 3
- React
- TypeScript
- Vite
- pnpm

### Interface

- Tailwind CSS
- shadcn/ui
- React Three Fiber
- Drei

### Infrastructure

- PostgreSQL
- Redis
- Laravel Horizon
- Laravel Reverb
- Amazon S3 in production
- MinIO for local object storage
- Mailpit for local email testing

### Testing and delivery

- PHPUnit
- Vitest
- React Testing Library
- Playwright
- Docker Compose through Laravel Sail
- GitHub Actions

---

# Local development setup

## Supported development environment

The current local setup uses:

- Windows 11
- Docker Desktop with WSL integration
- Ubuntu on WSL2
- Laravel Sail

Use **Ubuntu/WSL for all project commands**. Use PowerShell only for Windows-level operations such as restarting or updating WSL.

The project should live in the Linux filesystem for better Docker and filesystem performance:

```text
/home/leward/projects/ai-operating-system
```

Avoid running the repository from `/mnt/c/...`.

---

## Prerequisites

Install and configure:

1. Git
2. Docker Desktop
3. Ubuntu through WSL2
4. Docker Desktop WSL integration for the Ubuntu distribution

Verify Docker from Ubuntu:

```bash
docker version
docker compose version
docker info
```

If Docker reports permission denied for `/var/run/docker.sock`, run:

```bash
sudo usermod -aG docker "$USER"
newgrp docker
docker info
```

Do not use `sudo chmod 666 /var/run/docker.sock`.

---

## First-time project setup

Run these commands in Ubuntu:

```bash
git clone <repository>
cd ai-operating-system
./bin/bootstrap
./bin/dev
```

Do not regenerate `APP_KEY` after the application has started storing encrypted data.

### Environment URL

The Sail application container currently publishes HTTP port `80`, so use:

```dotenv
APP_URL=http://localhost
```

Do not set `APP_URL=http://localhost:8000` unless the Docker Compose application port is also changed to `8000`.

Confirm the published port:

```bash
docker compose ps
docker compose port laravel.test 80
```

Expected mapping:

```text
0.0.0.0:80->80/tcp
```

---

# Daily development workflow

## Start the complete development environment

The repository includes a local development launcher:

```bash
cd ~/projects/ai-operating-system
./bin/dev
```

The launcher starts Docker services and the following application processes:

- Vite development server
- Laravel Reverb
- Laravel Horizon
- Laravel scheduler

Wait until the terminal reports that the environment is ready before opening the browser.

### Local service URLs

| Service | URL |
|---|---|
| Laravel application | `http://localhost` |
| Vite | `http://localhost:5173` |
| Laravel Reverb | `ws://localhost:8080` |
| Mailpit | `http://localhost:8025` |
| MinIO console | `http://localhost:8900` |
| PostgreSQL host port | `localhost:54320` |
| Redis host port | `localhost:63790` |

Open the application at:

```text
http://localhost
```

Port `5173` serves Vite assets and hot-module replacement. It is not the main application URL.

## Stop the development processes

Press:

```text
Ctrl+C
```

The runner should stop Vite, Reverb, Horizon, and the scheduler together.

Stop the Docker containers when development is finished:

```bash
./vendor/bin/sail down
```

---

## Optional shell aliases

Add these aliases to `~/.bashrc`:

```bash
cat >> ~/.bashrc <<'BASH'

# Short Laravel Sail command.
alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'

# Start the complete AI Operating System development environment.
alias aios-dev='cd ~/projects/ai-operating-system && ./bin/dev'

# Stop the AI Operating System Docker environment.
alias aios-down='cd ~/projects/ai-operating-system && ./vendor/bin/sail down'

# Show the current Docker service state.
alias aios-status='cd ~/projects/ai-operating-system && ./vendor/bin/sail ps'
BASH

source ~/.bashrc
```

Daily usage:

```bash
aios-dev
```

Check status:

```bash
aios-status
```

Stop Docker:

```bash
aios-down
```

---

# Manual process commands

The combined development launcher is preferred. For isolated debugging, run the processes manually in separate Ubuntu terminals.

## Vite

```bash
cd ~/projects/ai-operating-system
./vendor/bin/sail pnpm dev --host 0.0.0.0
```

## Laravel Reverb

```bash
cd ~/projects/ai-operating-system
./vendor/bin/sail artisan reverb:start \
    --host=0.0.0.0 \
    --port=8080
```

## Laravel Horizon

```bash
cd ~/projects/ai-operating-system
./vendor/bin/sail artisan horizon
```

## Laravel scheduler

```bash
cd ~/projects/ai-operating-system
./vendor/bin/sail artisan schedule:work
```

Do not run manual copies of these processes while `./bin/dev` is active. Duplicate Horizon workers or scheduler processes may process work more than once, and duplicate Reverb processes will conflict on port `8080`.

---

# Vite and WSL configuration

Vite must listen on all container interfaces while publishing a browser-accessible HMR URL.

The `server` section of `vite.config.ts` should include:

```ts
server: {
    // Allow Docker to publish the Vite server to the Windows host.
    host: '0.0.0.0',

    // Use a deterministic port and fail instead of silently choosing another.
    port: 5173,
    strictPort: true,

    // Generate asset URLs that Windows Chrome can access.
    origin: 'http://localhost:5173',

    // Route HMR traffic through Docker Desktop and WSL localhost forwarding.
    hmr: {
        host: 'localhost',
    },

    // Improve filesystem change detection for Docker and WSL-mounted files.
    watch: {
        usePolling: true,
    },
},
```

When Vite is active, Laravel writes the development asset URL to:

```text
public/hot
```

Expected value:

```text
http://localhost:5173
```

Verify it with:

```bash
cat public/hot
curl -I http://localhost:5173/@vite/client
```

---

# Dependency management

Run Composer and pnpm inside Sail:

```bash
./vendor/bin/sail composer install
./vendor/bin/sail pnpm install
```

Add a frontend package:

```bash
./vendor/bin/sail pnpm add package-name
```

Add a development-only frontend package:

```bash
./vendor/bin/sail pnpm add --save-dev package-name
```

Avoid running pnpm as `root`. Root-owned Corepack caches, pnpm stores, or `node_modules` files can cause read-only database and Vite permission errors.

## Updating pinned container images

All externally pulled development, bootstrap, and CI container images must use
an explicit version tag and an immutable multi-platform SHA-256 index digest:

```text
repository:version@sha256:digest
```

Do not use latest, bare distribution aliases such as alpine, major-only
aliases such as composer:2, or digest-free references.

To update an image:

1. Select an explicit reviewed version.
2. Inspect the official registry manifest:
`docker buildx imagetools inspect image:version`
3. Confirm the top-level digest is a valid sha256: value.
4. Confirm the manifest supports linux/amd64 and linux/arm64.
5. Update every corresponding reference in:
    - `compose.yaml`
    - `.github/workflows`
    - bootstrap or maintenance scripts
    - tracked Dockerfiles

6. Run:

    ```bash
    docker compose config --quiet
    docker compose config --images
    bash bin/check-container-images
    docker compose pull
    docker compose up -d --build
    ./vendor/bin/sail artisan app:check
    ./vendor/bin/sail composer ci:check
    ./vendor/bin/sail pnpm test:e2e
    ```

7. Record the registry inspection date and output in `docs/evidence`.
8. Review the complete repository diff before committing.

A previous verified digest must be used for rollback. Never roll back to a
floating tag.

---

# Common commands

## Laravel

```bash
./vendor/bin/sail artisan about
./vendor/bin/sail artisan route:list
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate:status
./vendor/bin/sail artisan optimize:clear
./vendor/bin/sail artisan queue:failed
```

## Frontend

```bash
./vendor/bin/sail pnpm install
./vendor/bin/sail pnpm dev --host 0.0.0.0
./vendor/bin/sail pnpm build
./vendor/bin/sail pnpm lint
```

## Tests

```bash
./vendor/bin/sail artisan test
./vendor/bin/sail pnpm test
./vendor/bin/sail pnpm test:e2e
```

Use the scripts that exist in `package.json`; exact frontend test script names may evolve with the implementation roadmap.

## Docker

```bash
./vendor/bin/sail up -d
./vendor/bin/sail ps
./vendor/bin/sail logs
./vendor/bin/sail down
```

Follow the Laravel application logs:

```bash
docker compose logs --follow --tail=100 laravel.test
```

---

# Troubleshooting

## Application refuses to connect

Check the published port:

```bash
docker compose ps
docker compose port laravel.test 80
```

For the current configuration, open:

```text
http://localhost
```

Test Laravel directly:

```bash
curl -I http://localhost
```

A `200`, `301`, or `302` response confirms that Laravel is reachable.

---

## Frontend loads without CSS

This usually means Laravel rendered the HTML but Vite was not reachable or `public/hot` contains an incorrect URL.

Run:

```bash
cat public/hot
curl -I http://localhost:5173/@vite/client
```

Expected `public/hot` value:

```text
http://localhost:5173
```

After Vite becomes ready, hard-refresh Chrome:

```text
Ctrl+Shift+R
```

Remove stale development state when necessary:

```bash
rm -f public/hot
rm -rf node_modules/.vite
```

Then restart:

```bash
./bin/dev
```

---

## Vite cannot resolve `@laravel/echo-react`

Install the Laravel Echo client dependencies:

```bash
./vendor/bin/sail pnpm add \
    @laravel/echo-react \
    laravel-echo \
    pusher-js
```

Validate the frontend build:

```bash
./vendor/bin/sail pnpm build
```

---

## Vite executable is missing

Error example:

```text
Cannot find module '/var/www/html/node_modules/vite/bin/vite.js'
```

Reinstall dependencies without deleting the lockfile:

```bash
docker compose exec -u root laravel.test \
    rm -rf /var/www/html/node_modules

./vendor/bin/sail pnpm install --frozen-lockfile
./vendor/bin/sail pnpm exec vite --version
```

If the lockfile and `package.json` are temporarily out of sync:

```bash
./vendor/bin/sail pnpm install
```

Review and commit both `package.json` and `pnpm-lock.yaml`.

---

## Corepack or pnpm reports a read-only SQLite database

Error example:

```text
[ERR_SQLITE_ERROR] attempt to write a readonly database
```

Repair the Sail user cache ownership:

```bash
docker compose exec -u root laravel.test bash -lc '
    mkdir -p \
        /home/sail/.cache/node/corepack \
        /home/sail/.local/share/pnpm \
        /home/sail/.config/pnpm

    chown -R sail:sail \
        /home/sail/.cache \
        /home/sail/.local \
        /home/sail/.config
'
```

Verify:

```bash
docker compose exec -u sail laravel.test bash -lc '
    id
    pnpm --version
'
```

---

## Vite reports permission denied for `.vite-temp`

Error example:

```text
EACCES: permission denied, open '/var/www/html/node_modules/.vite-temp/...'
```

Repair `node_modules` ownership:

```bash
docker compose exec -u root laravel.test bash -lc '
    rm -rf /var/www/html/node_modules/.vite-temp
    chown -R sail:sail /var/www/html/node_modules
    chmod -R u+rwX /var/www/html/node_modules
'
```

Verify that the Sail user can write:

```bash
docker compose exec -u sail laravel.test bash -lc '
    mkdir -p /var/www/html/node_modules/.vite-temp
    touch /var/www/html/node_modules/.vite-temp/permission-test
    rm /var/www/html/node_modules/.vite-temp/permission-test
    echo "Vite directory is writable."
'
```

---

## Reverb reports port `8080` is already in use

Error example:

```text
Failed to listen on "tcp://0.0.0.0:8080": Address already in use
```

A stale Reverb process is still running. Reset the application container:

```bash
docker compose restart laravel.test
```

Verify the port:

```bash
docker compose exec laravel.test bash -lc \
    "ss -ltnp | grep ':8080' || echo 'Port 8080 is free.'"
```

Do not start Reverb manually while `./bin/dev` is running.

---

## Docker points to the wrong context

Reset Docker environment overrides in Ubuntu:

```bash
unset DOCKER_HOST
unset DOCKER_CONTEXT

docker context use default
docker context ls
docker version
docker info
```

Use PowerShell only when WSL itself needs to be restarted:

```powershell
wsl --shutdown
```

Then reopen Ubuntu and rerun:

```bash
docker info
```

---

## `WWWUSER` or `WWWGROUP` warnings

Add the WSL user and group IDs to `.env`:

```bash
grep -q '^WWWUSER=' .env \
    || echo "WWWUSER=$(id -u)" >> .env

grep -q '^WWWGROUP=' .env \
    || echo "WWWGROUP=$(id -g)" >> .env
```

Recreate the containers:

```bash
./vendor/bin/sail down
./vendor/bin/sail up -d
```

---

# Development safety rules

- Use Ubuntu/WSL for repository commands.
- Keep the repository in the WSL Linux filesystem.
- Run PHP, Composer, Node.js, and pnpm commands through Sail.
- Do not use `sudo` for normal Sail commands.
- Do not run pnpm as root.
- Do not run duplicate Vite, Reverb, Horizon, or scheduler processes.
- Keep `pnpm-lock.yaml` committed and synchronized with `package.json`.
- Never merge automated development work directly into `main`.
- Pull requests produced by the orchestration workflow must target `develop`.
- Treat simulated artifacts as simulated evidence only.

---

# Current implementation status

The repository currently has the Laravel 13 application and local infrastructure running through Docker Compose. The next implementation work should follow the canonical Notion delivery tracker and preserve the simulation-first architecture defined by the baseline and full specification.
