# Repository Guidelines
Coolify is an open-source, self-hostable PaaS (Heroku/Netlify/Vercel alternative) that manages servers, applications, databases, and services over SSH. It is a Laravel 12 app using the Laravel 10 directory structure with a Livewire-driven UI.
- **UI layer**: Livewire components in `app/Livewire/` with views in `resources/views/livewire/`.
- **Business logic**: Action classes in `app/Actions/` (uses `lorisleiva/laravel-actions`).
- **Async work**: Queue jobs in `app/Jobs/` with Horizon for monitoring.
- **Data**: Eloquent models in `app/Models/` and DTOs in `app/Data/` (Spatie Laravel Data).
- **Remote execution**: SSH/Docker orchestration via `app/Actions/CoolifyTask/` and helpers in `app/Helpers/`.
Typical flow: Livewire interaction → Form Request validation → Action class → Job (if async) → remote execution (SSH/Docker) → model updates → Livewire re-render.
## Key Directories
- `app/Actions/` — domain logic (Applications, Servers, Databases, Proxy, etc.).
- `app/Livewire/` — primary UI layer (pages and components).
- `app/Jobs/` — background tasks for deployments, backups, cleanup.
- `app/Models/` — Eloquent models and relationships.
- `app/Data/` — data transfer objects.
- `app/Helpers/` — global helpers (autoloaded via `bootstrap/includeHelpers.php`).
- `routes/` — HTTP routes, mostly Livewire in `routes/web.php`.
- `resources/` — Blade views, CSS/JS (Vite entrypoints).
- `templates/compose/` — service templates (Docker Compose definitions).
- `docker/` — Dockerfiles and dev tooling.
From `CLAUDE.md` and `scripts/run`:
# Dev environment
spin up                             # or: docker compose -f docker-compose.dev.yml up -d
spin down
npm run dev
npm run build
php artisan test --compact
php artisan test --compact --filter=testName
php artisan test --compact tests/Feature/SomeTest.php
./scripts/run test
vendor/bin/pint --dirty --format agent
```

Additional shortcuts (see `scripts/run`): `run logs`, `run db:reset`, `run tinker`.
## Code Conventions & Common Patterns
- Prefer **Livewire components** over controllers for UI flows.
- Use **Form Request** classes for validation (`app/Http/Requests/`).
- Use **Eloquent relationships**; avoid `DB::` when possible.
- Use **constructor property promotion** and **explicit return types** in PHP.
- Use **TitleCase enum keys**.
- Actions encapsulate domain logic; Jobs handle long-running tasks.
- Helpers are autoloaded from `bootstrap/includeHelpers.php`.
This repo now supports **optional** server sharing between teams. It is additive and off by default to keep upstream merges clean.
- `config('constants.coolify.shared_servers_enabled')` (env: `SHARED_SERVERS_ENABLED=false`)
- Examples in `.env.development.example` and `.env.windows-docker-desktop.example`
- Owner team remains `servers.team_id` (unchanged).
- New pivot table `server_team` for shared access.
- Relationships:
  - `Server::sharedTeams()`
  - `Team::sharedServers()`
- New scope: `Server::accessibleByTeam($teamId)`
  - When flag **off**: behaves like `whereTeamId($teamId)`.
  - When flag **on**: includes servers owned by team **or** in `server_team`.
- `Server::ownedByCurrentTeam()` now uses `accessibleByTeam` to include shared servers when enabled.
- `Team::accessibleServers()`/`accessibleServerCount()` are used for per-team limits (shared servers count against each team).
- `ServerPolicy::view` honors shared access only when the flag is enabled.
- API server lookups use `accessibleByTeam` instead of `whereTeamId`:
  - `app/Http/Controllers/Api/ApplicationsController.php`
  - `app/Http/Controllers/Api/DatabasesController.php`
  - `app/Http/Controllers/Api/DeployController.php`
  - `app/Http/Controllers/Api/ServersController.php`
  - `app/Http/Controllers/Api/ServicesController.php`
- UI lists updated where direct `currentTeam()->servers()` existed:
  - `app/Livewire/Project/CloneMe.php`
  - `app/Livewire/Project/Shared/ResourceOperations.php`
- When rebasing on upstream, re-apply any new `Server::whereTeamId(...)` callsites to `accessibleByTeam` if they are team-scoped reads.
- Keep `servers.team_id` semantics intact; the pivot is strictly additive.
- Limit enforcement is per-team and counts shared servers via `Team::accessibleServerCount()`.
- `routes/web.php` — route map (mostly Livewire components).
- `bootstrap/includeHelpers.php` — helper loader.
- `app/Http/Kernel.php`, `app/Console/Kernel.php`, `app/Exceptions/Handler.php` — Laravel 10 structure entry points.
- `composer.json` — PHP deps and scripts.
- `package.json` — frontend deps and scripts.
- `vite.config.js` — Vite + Vue + Laravel config.
- `docker-compose.dev.yml` — dev environment services.
- `tests/Pest.php` — Pest configuration/hooks.
- `phpunit.xml` — test suites and environment.
- **PHP**: ^8.4 (`composer.json`).
- **Frontend tooling**: Vite + Tailwind CSS + Vue (`package.json`, `vite.config.js`).
- **Package managers**: Composer for PHP, npm for frontend.
- **Dev environment**: Docker Compose via Spin (`docker-compose.dev.yml`).
- **Frameworks**: Pest 4 (`tests/`), Laravel Dusk for browser tests (`tests/DuskTestCase.php`).
- **Run tests**: `php artisan test --compact` (or use `./scripts/run test` for containerized runs).
- **Formatting**: `vendor/bin/pint --dirty --format agent`.
- **Coverage expectations**: not specified; follow existing test patterns and add tests for changes.
- `sudo docker exec -t coolify php artisan test --compact tests/Feature/SharedServerAccessTest.php` — ✅ passed (3 tests, 5 assertions).
- `vendor/bin/pint --dirty --format agent` — ✅ completed locally.
Setup used for tests:
- `cp .env.development.example .env` and set `APP_KEY`.
- `sudo docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d`.