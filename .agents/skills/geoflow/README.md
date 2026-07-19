# geoflow

`geoflow` is the single GEOFlow skill for product development, running-system operations, the default public frontend, Laravel Blade themes, visitor and lead flows, GeoFlow Agent channel sites, and legacy template migration.

## Modes

- `development`: Laravel backend, admin, API, CLI, migrations, queues, and tests
- `operations`: CLI, API v1, and authenticated admin workflows
- `public_frontend`: default site, themes, homepage modules, lead forms, article/category/archive pages, and preview edits
- `channel_frontend`: target packages, capability checks, channel experience settings, and sync previews
- `legacy_migration`: old root PHP template contracts and historical package migration

The package supersedes `yao-geoflow-cli`, `yao-geoflow-design`, and `yao-geoflow-template`. Detailed contracts stay in `references/`, and deterministic discovery, validation, preview, and preflight logic stays in `scripts/`.

## Installation

Agent Skills compatible tools can discover this package directly from `.agents/skills/geoflow` in a GEOFlow checkout.

For a clean global Codex install or upgrade, run this command from the GEOFlow repository root:

```bash
bash .agents/skills/geoflow/scripts/install_codex_skill.sh
```

The installer copies only files declared in `evals/expected_artifacts.json`, validates the staged package, moves the current `geoflow` and retired `yao-geoflow-*` directories into a unique `~/.codex/skill-backups/geoflow-<timestamp>.<suffix>/` directory, and switches the staged package into place on the same filesystem. Restart Codex after it finishes.

To roll back, use the backup path printed by the installer:

```bash
mv ~/.codex/skills/geoflow ~/.codex/skills/geoflow.failed
mv ~/.codex/skill-backups/geoflow-<timestamp>.<suffix>/geoflow ~/.codex/skills/geoflow
```

If the backup contains retired Skill directories that must be restored, move those directories from the same timestamped backup into `~/.codex/skills/`, then restart Codex.

## Runtime requirements

| Mode or helper | Required tools | Supported host |
|---|---|---|
| Package install | Bash, Python 3.10+ | macOS, Linux, or Windows through WSL |
| Source discovery, public frontend, legacy migration | Python 3.10+ | macOS, Linux, or WSL |
| Runtime preflight and API fallback | Bash, Python 3.10+, `curl` | macOS, Linux, or WSL |
| Live channel capability reports | Python 3.10+, PHP CLI, project `artisan` and installed Composer dependencies | A working GEOFlow application checkout |
| Product development | The dependencies declared by the target GEOFlow checkout, commonly PHP/Composer, Node.js, and optional Docker | Follow the repository's own support matrix |

Native Windows PowerShell is not a supported execution shell for the bundled Bash helpers. Use WSL or load the references manually. When a required tool is unavailable, stay in read-only discovery, report the missing verification layer, and do not claim preflight, live capability, preview, sync, activation, or publication success.
