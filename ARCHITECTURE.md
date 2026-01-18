# ARCHITECTURE.md — Current Structure (Kanboard Upstream)

## Overview
This repository currently tracks the upstream Kanboard PHP application. The standalone desktop app work has not started yet; for now, the architecture reflects the existing Kanboard codebase and layout.

## High-level layout

```
.
├── app/                # Core application code (controllers, models, helpers, templates)
├── assets/             # Static assets (CSS, JS, images)
├── cli/                # CLI commands
├── data/               # Runtime data (e.g., db.sqlite, cache, files)
├── docker/             # Docker-related configs
├── libs/               # Vendor libraries and third-party code
├── plugins/            # Kanboard plugins
├── scripts/            # Dev/maintenance scripts
├── tests/              # Unit/integration tests
├── vendor/             # Composer dependencies
├── index.php           # Front controller
├── jsonrpc.php         # JSON-RPC endpoint
└── Makefile            # Build/test helpers
```

## Responsibilities (current)
- `app/`: Application logic and database interaction.
- `assets/`: Frontend static resources.
- `cli/`: Command-line utilities for admin tasks.
- `data/`: SQLite database and runtime artifacts (not intended for source control).
- `docker/`: Container build/runtime helpers.
- `libs/` + `vendor/`: Third-party code.
- `tests/`: PHPUnit suites.

## Architecture notes
- No standalone desktop packaging exists yet.
- ADRs should be placed in `docs/adr/` once decisions are made.
