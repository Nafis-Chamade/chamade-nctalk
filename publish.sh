#!/bin/bash
# Publish chamade-nctalk from maquisard source.
#
# Takes the NC Talk addon from maquisard-chamade-dev, applies Chamade branding,
# removes Escouade-specific code, and commits/pushes to this repo.
#
# Usage:
#   ./publish.sh [--dry-run]    # --dry-run shows diff without committing
#
# Prerequisites:
#   - maquisard-chamade-dev at /srv/apps/maquisard-chamade-dev
#   - SSH key for Codeberg (chamade_codeberg)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MQSR_SRC="/srv/apps/maquisard-chamade-dev/services/nextcloudtalk"
DRY_RUN=false

if [[ "${1:-}" == "--dry-run" ]]; then
    DRY_RUN=true
fi

if [[ ! -d "$MQSR_SRC" ]]; then
    echo "ERROR: Maquisard source not found at $MQSR_SRC"
    exit 1
fi

echo "=== Publishing chamade-nctalk from maquisard source ==="

# ── Step 1: Get version from maquisard source ──
MQSR_VERSION=$(grep -oP '<version>\K[^<]+' "$MQSR_SRC/appinfo/info.xml")
echo "Source version: $MQSR_VERSION"

# ── Step 2: Copy and rebrand files that stay as-is ──
rebrand() {
    sed \
        -e 's/MaquisardTalk/ChamadeTalk/g' \
        -e 's/maquisard_talk/chamade_talk/g' \
        -e "s/brand_name', 'Maquisard'/brand_name', 'Chamade'/g" \
        -e 's/maquisard-talk/chamade-talk/g' \
        "$1" > "$2"
}

echo "Copying and rebranding files..."

# Files copied with branding only (no content changes)
REBRAND_FILES=(
    "lib/Controller/BotUserController.php"
    "lib/Controller/ConfigController.php"
    "lib/Service/BotService.php"
    "lib/Service/TalkApiService.php"
    "lib/Traits/HmacVerification.php"
    "lib/Migration/InstallStep.php"
    "lib/Migration/UninstallStep.php"
    "templates/authorize.php"
)

for f in "${REBRAND_FILES[@]}"; do
    if [[ -f "$MQSR_SRC/$f" ]]; then
        mkdir -p "$SCRIPT_DIR/$(dirname "$f")"
        rebrand "$MQSR_SRC/$f" "$SCRIPT_DIR/$f"
    else
        echo "WARNING: $f not found in source"
    fi
done

# Binary files (no sed)
cp "$MQSR_SRC/img/app.png" "$SCRIPT_DIR/img/app.png" 2>/dev/null || true

# ── Step 3: Files that need Chamade-specific modifications ──
# These are maintained directly in this repo (not copied from maquisard):
#   - appinfo/info.xml          (Chamade description, URLs, no Codeberg refs)
#   - appinfo/routes.php        (no pairing/bridge routes)
#   - lib/AppInfo/Application.php (no UserCreatedListener)
#   - lib/Controller/AuthorizeController.php (getCallbackUrl from query param)
#   - lib/Controller/SettingsController.php  (saves callback_url)
#   - lib/Listener/ChatListener.php          (no BridgeService)
#   - lib/Settings/AdminSettings.php         (no pairing, has callback_url)
#   - templates/settings.php    (no pairing UI, has callback_url)
#   - js/settings.js            (no pairing JS)
#   - l10n/en.json              (Chamade strings only)
#   - l10n/fr.json              (Chamade strings only)
#   - CHANGELOG.md              (Chamade-specific history)

# For these files, we need to detect if the maquisard source has changed
# in ways that affect our Chamade version. We do this by checking if the
# rebranded version differs from what we have (for the rebrandable parts).

# AuthorizeController: rebrand, then apply Chamade patches
TMP=$(mktemp)
rebrand "$MQSR_SRC/lib/Controller/AuthorizeController.php" "$TMP"

# Patch: getCallbackUrl reads from query param first
if ! grep -q 'fromParam' "$TMP"; then
    # Apply the callback_url patch
    sed -i '/private function getCallbackUrl/,/^    }/{
        /return.*getAppValue.*callback_url/i\
        $fromParam = $this->request->getParam('\''callback_url'\'', '\'''\'');\
        if (!empty($fromParam)) {\
            return $fromParam;\
        }
    }' "$TMP"
fi
cp "$TMP" "$SCRIPT_DIR/lib/Controller/AuthorizeController.php"
rm "$TMP"

# ChatListener: rebrand, then remove BridgeService
TMP=$(mktemp)
rebrand "$MQSR_SRC/lib/Listener/ChatListener.php" "$TMP"
# Remove BridgeService import
sed -i '/use OCA\\ChamadeTalk\\Service\\BridgeService;/d' "$TMP"
# Remove BridgeService from constructor
sed -i '/private BridgeService \$bridgeService,/d' "$TMP"
# Remove /bridge command block
sed -i '/Check for \/bridge commands/,/^        }/d' "$TMP"
# Fix doc comment
sed -i 's/Handles \/bridge commands locally (via BridgeService)/Handles \/activate and \/deactivate commands (room authorization)/' "$TMP"
sed -i '/Handles \/activate/s/1\./1./' "$TMP"
sed -i 's/2\. Handles \/activate/1. Handles \/activate/' "$TMP"
sed -i 's/3\. Forwards/2. Forwards/' "$TMP"
cp "$TMP" "$SCRIPT_DIR/lib/Listener/ChatListener.php"
rm "$TMP"

# ── Step 4: Remove Escouade-only files (safety check) ──
ESCOUADE_FILES=(
    "lib/Controller/PairController.php"
    "lib/Controller/BridgeController.php"
    "lib/Service/BridgeService.php"
    "lib/Service/ProvisionService.php"
    "lib/Listener/UserCreatedListener.php"
)
for f in "${ESCOUADE_FILES[@]}"; do
    if [[ -f "$SCRIPT_DIR/$f" ]]; then
        echo "WARNING: Removing Escouade-only file: $f"
        rm "$SCRIPT_DIR/$f"
    fi
done

# ── Step 5: Update version in info.xml ──
CURRENT_VERSION=$(grep -oP '<version>\K[^<]+' "$SCRIPT_DIR/appinfo/info.xml")
if [[ "$CURRENT_VERSION" != "$MQSR_VERSION" ]]; then
    echo "Updating version: $CURRENT_VERSION → $MQSR_VERSION"
    sed -i "s|<version>$CURRENT_VERSION</version>|<version>$MQSR_VERSION</version>|" "$SCRIPT_DIR/appinfo/info.xml"
fi

# ── Step 6: Final validation ──
echo ""
echo "=== Validation ==="
ERRORS=0

# Check no maquisard branding leaked (excluding protocol headers which use concatenation)
LEAKS=$(grep -rn "maquisard\|Maquisard" "$SCRIPT_DIR" \
    --include="*.php" --include="*.xml" --include="*.js" --include="*.json" --include="*.md" \
    | grep -v "X-Maquis" | grep -v "publish.sh" | grep -v ".git/" || true)
if [[ -n "$LEAKS" ]]; then
    echo "ERROR: Maquisard branding found:"
    echo "$LEAKS"
    ERRORS=$((ERRORS + 1))
fi

# Check no dead code
DEAD=$(grep -rn "PairController\|BridgeController\|BridgeService\|ProvisionService\|UserCreatedListener" \
    "$SCRIPT_DIR" --include="*.php" --include="*.xml" | grep -v "publish.sh" | grep -v ".git/" || true)
if [[ -n "$DEAD" ]]; then
    echo "ERROR: Dead code references:"
    echo "$DEAD"
    ERRORS=$((ERRORS + 1))
fi

# Check no private URLs
PRIVATE=$(grep -rn "codeberg.org/skilpa/maquisard" "$SCRIPT_DIR" \
    --include="*.php" --include="*.xml" --include="*.json" | grep -v "publish.sh" | grep -v ".git/" || true)
if [[ -n "$PRIVATE" ]]; then
    echo "ERROR: Private URLs found:"
    echo "$PRIVATE"
    ERRORS=$((ERRORS + 1))
fi

if [[ $ERRORS -gt 0 ]]; then
    echo ""
    echo "FAILED: $ERRORS validation error(s). Fix before committing."
    exit 1
fi
echo "All checks passed ✓"

# ── Step 7: Show diff and commit ──
cd "$SCRIPT_DIR"

if ! git diff --quiet HEAD 2>/dev/null; then
    echo ""
    echo "=== Changes ==="
    git diff --stat

    if $DRY_RUN; then
        echo ""
        echo "(dry-run — not committing)"
        exit 0
    fi

    git add -A
    git -c user.name="Skilpa" -c user.email="contact@skilpa.be" \
        commit -m "$(cat <<EOF
Update chamade_talk to v${MQSR_VERSION}

Synced from maquisard source, Chamade branding applied.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
    )"

    GIT_SSH_COMMAND="ssh -i ~/.ssh/chamade_codeberg -o IdentitiesOnly=yes" \
        git push

    echo ""
    echo "=== Published chamade_talk v${MQSR_VERSION} ==="
else
    echo ""
    echo "No changes to publish."
fi
