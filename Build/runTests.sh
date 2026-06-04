#!/usr/bin/env bash
set -euo pipefail

#
# Test runner for the mail_sender extension.
#
# Runs the functional, lint or end-to-end (Playwright) suites, optionally
# pinned to a specific TYPO3 LTS version. Mirrors the setup used in
# hauptsacheNet/typo3-mcp-server, adapted for this extension.
#
# Usage:
#   Build/runTests.sh                              # Functional tests (host PHP, SQLite)
#   Build/runTests.sh -s lint                      # PHP lint
#   Build/runTests.sh -s e2e                       # E2E (Docker if available, else host)
#   Build/runTests.sh -s e2e -n                    # E2E forced to host PHP + SQLite + Playwright
#   Build/runTests.sh -s e2e -t 12.4               # E2E against TYPO3 12.4
#   Build/runTests.sh -s e2e -t 13.4 -- --grep List  # pass extra args to playwright/phpunit
#

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_VERSION="8.3"
DBMS="sqlite"
TEST_SUITE="functional"
TYPO3_VERSION=""
NO_DOCKER=0
EXTRA_ARGS=()

RUN_ID="mail-sender-tests-$$"
CONTAINER_NAME="${RUN_ID}"
NETWORK_NAME="${RUN_ID}-net"

usage() {
    cat <<EOF
Usage: $(basename "$0") [options] [-- extra-args]

Options:
    -s <suite>      Test suite: functional (default), lint, e2e
    -t <version>    Pin TYPO3 version: 12.4, 13.4, 14.3 (default: whatever is installed)
    -p <version>    PHP version for Docker mode: 8.2, 8.3 (default), 8.4, 8.5
    -d <dbms>       Database for Docker functional mode: sqlite (default), mysql, mariadb, postgres
    -n, --no-docker Run e2e on the host (PHP built-in server + SQLite + local Playwright)
    -h, --help      Show this help

Extra args after -- are forwarded to phpunit (functional) or playwright (e2e).

Examples:
    $(basename "$0") -s e2e -t 13.4 -n
    $(basename "$0") -s e2e -t 14.3 -n -- --grep "form editor"
EOF
    exit 0
}

while [ $# -gt 0 ]; do
    case "$1" in
        -s) TEST_SUITE="$2"; shift 2 ;;
        -t) TYPO3_VERSION="$2"; shift 2 ;;
        -p) PHP_VERSION="$2"; shift 2 ;;
        -d) DBMS="$2"; shift 2 ;;
        -n|--no-docker) NO_DOCKER=1; shift ;;
        -h|--help) usage ;;
        --) shift; EXTRA_ARGS+=("$@"); break ;;
        *) EXTRA_ARGS+=("$1"); shift ;;
    esac
done

case "${TEST_SUITE}" in
    functional|lint|e2e) ;;
    *) echo "Error: unsupported suite '${TEST_SUITE}'. Use functional, lint or e2e." >&2; exit 1 ;;
esac

if [ -n "${TYPO3_VERSION}" ]; then
    case "${TYPO3_VERSION}" in
        12.4|13.4|14.3) ;;
        *) echo "Error: unsupported TYPO3 version '${TYPO3_VERSION}'. Use 12.4, 13.4 or 14.3." >&2; exit 1 ;;
    esac
fi

# Retry a command (composer/npm network operations) with exponential backoff.
retry() {
    local max="$1"; shift
    local n=1 delay=2
    until "$@"; do
        if [ "$n" -ge "$max" ]; then
            echo "Command failed after ${max} attempts: $*" >&2
            return 1
        fi
        echo "Attempt ${n} failed, retrying in ${delay}s..." >&2
        sleep "${delay}"
        n=$((n + 1))
        delay=$((delay * 2))
    done
}

COMPOSER_JSON_BACKUP=""
LOCAL_WEB_PID=""
cleanup() {
    set +e
    if [ -n "${LOCAL_WEB_PID}" ]; then
        kill "${LOCAL_WEB_PID}" >/dev/null 2>&1
    fi
    # Restore composer.json if we pinned a TYPO3 version.
    if [ -n "${COMPOSER_JSON_BACKUP}" ] && [ -f "${COMPOSER_JSON_BACKUP}" ]; then
        mv "${COMPOSER_JSON_BACKUP}" "${ROOT_DIR}/composer.json"
    fi
    if command -v docker >/dev/null 2>&1; then
        docker rm -f "${CONTAINER_NAME}" >/dev/null 2>&1
        docker network rm "${NETWORK_NAME}" >/dev/null 2>&1
    fi
    set -e
}
trap cleanup EXIT

# Pin a specific TYPO3 version by rewriting the root requirements. The original
# composer.json is restored on exit so the working tree stays clean.
pin_typo3_version() {
    [ -z "${TYPO3_VERSION}" ] && return 0
    echo "Pinning TYPO3 to ^${TYPO3_VERSION}..."
    COMPOSER_JSON_BACKUP="${ROOT_DIR}/composer.json.e2e-bak"
    cp "${ROOT_DIR}/composer.json" "${COMPOSER_JSON_BACKUP}"
    ( cd "${ROOT_DIR}" && \
      composer require --no-update --no-interaction --no-progress \
        "typo3/cms-core:^${TYPO3_VERSION}" \
        "typo3/cms-backend:^${TYPO3_VERSION}" \
        "typo3/cms-scheduler:^${TYPO3_VERSION}" && \
      composer require --no-update --no-interaction --no-progress --dev \
        "typo3/cms-form:^${TYPO3_VERSION}" \
        "typo3/cms-install:^${TYPO3_VERSION}" )
}

# ---------------------------------------------------------------------------
# Lint
# ---------------------------------------------------------------------------
if [ "${TEST_SUITE}" = "lint" ]; then
    echo "Linting PHP files..."
    cd "${ROOT_DIR}"
    find Classes Tests -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
    echo "Lint OK."
    exit 0
fi

# ---------------------------------------------------------------------------
# Functional (host PHP + SQLite)
# ---------------------------------------------------------------------------
if [ "${TEST_SUITE}" = "functional" ]; then
    cd "${ROOT_DIR}"
    pin_typo3_version
    echo "Installing composer dependencies..."
    if [ -n "${TYPO3_VERSION}" ]; then
        retry 4 composer update --no-interaction --prefer-dist --no-progress
    else
        retry 4 composer install --no-interaction --prefer-dist --no-progress
    fi
    echo "Running functional test suite..."
    typo3DatabaseDriver=pdo_sqlite typo3DatabaseName=typo3_test \
        vendor/bin/phpunit -c phpunit.xml.dist ${EXTRA_ARGS[@]+"${EXTRA_ARGS[@]}"}
    exit 0
fi

# ---------------------------------------------------------------------------
# E2E
# ---------------------------------------------------------------------------

# Docker is not required; fall back to the host runner when it is unavailable.
if [ "${NO_DOCKER}" -eq 0 ]; then
    if ! command -v docker >/dev/null 2>&1 || ! docker info >/dev/null 2>&1; then
        echo "Note: docker unavailable, running e2e on the host (--no-docker)." >&2
        NO_DOCKER=1
    fi
fi

if [ "${NO_DOCKER}" -ne 1 ]; then
    echo "Error: Docker-based e2e is not configured for this extension; use --no-docker." >&2
    exit 1
fi

echo "Running E2E tests (host PHP + SQLite + Playwright)..."

command -v php >/dev/null 2>&1 || { echo "Error: php is required." >&2; exit 1; }
command -v composer >/dev/null 2>&1 || { echo "Error: composer is required." >&2; exit 1; }
command -v npx >/dev/null 2>&1 || { echo "Error: npx (Node.js) is required." >&2; exit 1; }
php -r 'exit(extension_loaded("pdo_sqlite")?0:1);' \
    || { echo "Error: PHP extension pdo_sqlite is required." >&2; exit 1; }

cd "${ROOT_DIR}"
pin_typo3_version

# Clean previous installation state.
rm -rf var/cache var/log config/system/settings.php config/system/additional.php \
    var/sqlite var/sqlite.db public/index.php 2>/dev/null || true

echo "Installing composer dependencies..."
if [ -n "${TYPO3_VERSION}" ]; then
    retry 4 composer update --no-interaction --prefer-dist --no-progress --no-scripts
else
    retry 4 composer install --no-interaction --prefer-dist --no-progress --no-scripts
fi

# Regenerate the autoloader. When switching TYPO3 versions in place, the
# typo3/class-alias-loader plugin can leave a stale class alias map behind,
# which breaks the CLI bootstrap used by `typo3 setup` below.
composer dump-autoload --no-interaction --quiet

echo "Setting up TYPO3 (SQLite)..."
vendor/bin/typo3 setup \
    --driver=sqlite \
    --admin-username=admin \
    --admin-user-password=Admin123! \
    --admin-email=admin@example.com \
    --project-name=mail-sender-e2e \
    --create-site=http://localhost/ \
    --server-type=other \
    --no-interaction \
    --force >/dev/null

# Provide a non-failsafe front controller for the PHP built-in web server.
# (composer normally generates this; --no-scripts above skips that step.)
cat > public/index.php <<'PHP'
<?php
call_user_func(static function () {
    $classLoader = require __DIR__ . '/../vendor/autoload.php';
    \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::run(0, \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_FE);
    \TYPO3\CMS\Core\Core\Bootstrap::init($classLoader)->get(\TYPO3\CMS\Core\Http\Application::class)->run();
});
PHP

# Relax trusted-hosts / devIPmask for the built-in web server.
php -r '$f="config/system/settings.php";$s=include $f;$s["SYS"]["trustedHostsPattern"]=".*";$s["SYS"]["devIPmask"]="*";file_put_contents($f,"<?php\nreturn ".var_export($s,true).";\n");'

# Seed deterministic test data (a pre-validated sender address).
php Build/tests/playwright/fixtures/seed.php "${ROOT_DIR}"

# Provide a form definition for the form-editor test.
mkdir -p public/fileadmin/form_definitions
cp Build/tests/playwright/fixtures/e2e-mailsender.form.yaml public/fileadmin/form_definitions/

rm -rf var/cache
vendor/bin/typo3 cache:warmup >/dev/null 2>&1 || true

LOCAL_WEB_HOST="127.0.0.1"
LOCAL_WEB_PORT="${TYPO3_E2E_PORT:-8080}"
LOCAL_WEB_URL="http://${LOCAL_WEB_HOST}:${LOCAL_WEB_PORT}"

echo "Starting PHP built-in web server at ${LOCAL_WEB_URL}..."
mkdir -p var/log
php -S "${LOCAL_WEB_HOST}:${LOCAL_WEB_PORT}" -t public/ >"${ROOT_DIR}/var/log/typo3-e2e-web.log" 2>&1 &
LOCAL_WEB_PID=$!

echo "Waiting for TYPO3..."
if ! curl -sf --retry 60 --retry-delay 1 --retry-connrefused "${LOCAL_WEB_URL}/typo3/" -o /dev/null; then
    echo "TYPO3 web server did not become ready. Logs:" >&2
    tail -30 "${ROOT_DIR}/var/log/typo3-e2e-web.log" >&2
    exit 1
fi
echo "TYPO3 is ready."

echo "Running Playwright tests..."
cd "${ROOT_DIR}/Build"
if [ ! -d node_modules ]; then
    retry 4 npm ci
fi
npx playwright install chromium >/dev/null
TYPO3_BASE_URL="${LOCAL_WEB_URL}" CI="${CI:-}" \
    npx playwright test ${EXTRA_ARGS[@]+"${EXTRA_ARGS[@]}"}
