#!/usr/bin/env sh
set -eu

# This wrapper is the explicit one-shot command configured for an SAE release
# task. It is intentionally separate from resident Web/Worker/Scheduler/Reverb
# processes so a platform restart cannot accidentally migrate the database.
exec /usr/local/bin/geoflow-entrypoint-sae --release "$@"
