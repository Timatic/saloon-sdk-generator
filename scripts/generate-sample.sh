#!/usr/bin/env bash
set -euo pipefail

# Bootstraps a sample SDK output directory (composer.json + config stub) so
# generate:sdk can resolve the namespace and connector name, then runs the generator.
#
# Usage: scripts/generate-sample.sh <spec> <output> <namespace> <connector-name> [extra flags...]

SPEC="$1"
OUTPUT="$2"
NAMESPACE="$3"
CONNECTOR="$4"
shift 4

KEY=$(echo "${CONNECTOR%Connector}" | tr '[:upper:]' '[:lower:]')

mkdir -p "$OUTPUT/config"

if [ ! -f "$OUTPUT/composer.json" ]; then
    printf '{\n    "autoload": {\n        "psr-4": {\n            "%s\\\\": "src/"\n        }\n    }\n}\n' \
        "${NAMESPACE//\\/\\\\}" > "$OUTPUT/composer.json"
fi

if [ ! -f "$OUTPUT/config/$KEY.php" ]; then
    printf '<?php\n\nreturn [];\n' > "$OUTPUT/config/$KEY.php"
fi

./codegen generate:sdk "$SPEC" --output="$OUTPUT" --connector-name="$CONNECTOR" --force "$@"
