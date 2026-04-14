#!/bin/bash
# Publish chamade-nctalk from maquisard source.
#
# One command does everything:
#   1. Sync from maquisard → chamade branding + cleanup
#   2. Push to public Codeberg repo
#   3. Build tar.gz → chamade/static/
#   4. Update version in web doc
#   5. Commit + push chamade repo
#
# Usage:
#   ./publish.sh [--dry-run]
#
# Prerequisites:
#   - maquisard-chamade-dev at /srv/apps/maquisard-chamade-dev
#   - chamade at /srv/apps/chamade
#   - SSH key ~/.ssh/chamade_codeberg

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MQSR_SRC="/srv/apps/maquisard-chamade-dev/services/nextcloudtalk"
CHAMADE_DIR="/srv/apps/chamade"
DRY_RUN=false

if [[ "${1:-}" == "--dry-run" ]]; then
    DRY_RUN=true
fi

if [[ ! -d "$MQSR_SRC" ]]; then
    echo "ERROR: Maquisard source not found at $MQSR_SRC"
    exit 1
fi

# ── Step 1: Get version from maquisard source ──
MQSR_VERSION=$(grep -oP '<version>\K[^<]+' "$MQSR_SRC/appinfo/info.xml")
CURRENT_VERSION=$(grep -oP '<version>\K[^<]+' "$SCRIPT_DIR/appinfo/info.xml")
echo "=== chamade-nctalk publish ==="
echo "Source: $MQSR_VERSION  Current: $CURRENT_VERSION"

# ── Step 2: Copy and rebrand files ──
rebrand() {
    sed \
        -e 's/MaquisardTalk/ChamadeTalk/g' \
        -e 's/maquisard_talk/chamade_talk/g' \
        -e 's/maquisard-talk/chamade-talk/g' \
        -e 's/Maquisard/Chamade/g' \
        "$1" > "$2"
    # The sed above renames X-Maquisard-* headers too, but they use string
    # concatenation ('X-Maquis' . 'ard-') which survives the replacement.
}

echo "Syncing from maquisard..."

# Files copied with branding only (no Chamade-specific content changes)
REBRAND_FILES=(
    "lib/AppInfo/Application.php"
    "lib/Controller/BotUserController.php"
    "lib/Controller/ConfigController.php"
    "lib/Listener/AttendeesListener.php"
    "lib/Listener/CallStateListener.php"
    "lib/Service/BackendWebhookClient.php"
    "lib/Service/BotService.php"
    "lib/Service/TalkApiService.php"
    "lib/Traits/HmacVerification.php"
    "lib/Migration/InstallStep.php"
    "lib/Migration/UninstallStep.php"
)
# NOT copied from maquisard (maintained locally in this repo):
#   templates/authorize.php  — l10n keys differ from maquisard version
#   templates/settings.php   — no pairing UI, has callback_url
#   js/settings.js           — no pairing JS
#   l10n/*.json              — Chamade-specific strings
#   appinfo/info.xml         — Chamade description/URLs (marketing copy, MCP
#                              pitch, platforms list, early-access mention).
#                              Only <version> is synced from maquisard below.
#                              When the addon identity evolves (name, summary,
#                              categories, major feature add), align BY HAND
#                              between this repo and maquisard's info.xml.
#   appinfo/routes.php       — no pairing/bridge routes
#   lib/Settings/AdminSettings.php — has callback_url
#   lib/Controller/SettingsController.php — saves callback_url
#   CHANGELOG.md

for f in "${REBRAND_FILES[@]}"; do
    if [[ -f "$MQSR_SRC/$f" ]]; then
        mkdir -p "$SCRIPT_DIR/$(dirname "$f")"
        rebrand "$MQSR_SRC/$f" "$SCRIPT_DIR/$f"
    else
        echo "  WARNING: $f not found in source"
    fi
done

# Binary files
cp "$MQSR_SRC/img/app.png" "$SCRIPT_DIR/img/app.png" 2>/dev/null || true

# Application.php: strip the comment line that references the private
# maquisard source tree ("... paired with `/srv/apps/maquisard-...").
# The information is useful for maquisard devs but leaks a private path
# in the public repo and makes the branding validator fail.
sed -i '/paired with the .hamade-side/,/inline constants in nctalk_probe.py/c\     * Used by ChatListener to detect an incoming event-dispatch probe\n     * (posted by the backend into a dedicated solo-bot room). The bracketed\n     * brand placeholder is filled in at match time. Any change to the nonce\n     * character set, length bounds, or surrounding literal must be mirrored\n     * on the Python side in the backend or probe round-trips break silently.' \
    "$SCRIPT_DIR/lib/AppInfo/Application.php"

# ── Step 3: Patch files that need Chamade-specific modifications ──
# AuthorizeController: rebrand + callback_url from query param
TMP=$(mktemp)
rebrand "$MQSR_SRC/lib/Controller/AuthorizeController.php" "$TMP"
if ! grep -q 'fromParam' "$TMP"; then
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

# ChatListener: rebrand + remove BridgeService
TMP=$(mktemp)
rebrand "$MQSR_SRC/lib/Listener/ChatListener.php" "$TMP"
sed -i '/use OCA\\ChamadeTalk\\Service\\BridgeService;/d' "$TMP"
sed -i '/private BridgeService \$bridgeService,/d' "$TMP"
sed -i '/Check for \/bridge commands/,/^        }/d' "$TMP"
sed -i 's/Handles \/bridge commands locally (via BridgeService)/Handles \/activate and \/deactivate commands (room authorization)/' "$TMP"
sed -i 's/2\. Handles \/activate/1. Handles \/activate/' "$TMP"
sed -i 's/3\. Forwards/2. Forwards/' "$TMP"
cp "$TMP" "$SCRIPT_DIR/lib/Listener/ChatListener.php"
rm "$TMP"

# ── Step 4: Remove Escouade-only files ──
for f in lib/Controller/PairController.php lib/Controller/BridgeController.php \
         lib/Service/BridgeService.php lib/Service/ProvisionService.php \
         lib/Listener/UserCreatedListener.php; do
    [[ -f "$SCRIPT_DIR/$f" ]] && rm "$SCRIPT_DIR/$f" && echo "  Removed $f"
done

# ── Step 5: Sync version ──
if [[ "$CURRENT_VERSION" != "$MQSR_VERSION" ]]; then
    echo "Version bump: $CURRENT_VERSION → $MQSR_VERSION"
    sed -i "s|<version>$CURRENT_VERSION</version>|<version>$MQSR_VERSION</version>|" "$SCRIPT_DIR/appinfo/info.xml"
fi

# ── Step 6: Validate ──
ERRORS=0
LEAKS=$(grep -rn "maquisard\|Maquisard" "$SCRIPT_DIR" \
    --include="*.php" --include="*.xml" --include="*.js" --include="*.json" --include="*.md" \
    | grep -v "X-Maquis" | grep -v "publish.sh" | grep -v ".git/" || true)
[[ -n "$LEAKS" ]] && echo "ERROR: Maquisard branding: $LEAKS" && ERRORS=$((ERRORS + 1))

DEAD=$(grep -rn "PairController\|BridgeController\|BridgeService\|ProvisionService\|UserCreatedListener" \
    "$SCRIPT_DIR" --include="*.php" --include="*.xml" | grep -v "publish.sh" | grep -v ".git/" || true)
[[ -n "$DEAD" ]] && echo "ERROR: Dead code: $DEAD" && ERRORS=$((ERRORS + 1))

PRIVATE=$(grep -rn "codeberg.org/skilpa/maquisard" "$SCRIPT_DIR" \
    --include="*.php" --include="*.xml" --include="*.json" | grep -v "publish.sh" | grep -v ".git/" || true)
[[ -n "$PRIVATE" ]] && echo "ERROR: Private URLs: $PRIVATE" && ERRORS=$((ERRORS + 1))

if [[ $ERRORS -gt 0 ]]; then
    echo "FAILED: $ERRORS validation error(s)."
    exit 1
fi
echo "Validation OK ✓"

# ── Step 7: Check for changes ──
cd "$SCRIPT_DIR"
if git diff --quiet HEAD 2>/dev/null; then
    echo "No changes in addon source."
    # Still rebuild tar.gz in case it's missing
fi

if $DRY_RUN; then
    git diff --stat 2>/dev/null || true
    echo "(dry-run — stopping here)"
    exit 0
fi

# ── Step 8: Commit + push public repo ──
if ! git diff --quiet HEAD 2>/dev/null; then
    git add -A
    git -c user.name="Skilpa" -c user.email="contact@skilpa.be" \
        commit -m "$(cat <<EOF
Update chamade_talk to v${MQSR_VERSION}

Synced from maquisard source.

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
    )"
    GIT_SSH_COMMAND="ssh -i ~/.ssh/chamade_codeberg -o IdentitiesOnly=yes" git push
    echo "Pushed to Codeberg ✓"
fi

# ── Step 9: Build tar.gz for web download ──
# The NC App Store validator requires exactly one top-level directory
# inside the archive — the app id. Tarring `.` with a --transform left
# a stray `./` entry at the root alongside `chamade_talk/`, which the
# validator rejects as "not a valid tar.gz archive". Stage the source
# into a temp dir named chamade_talk/ and tar from the parent so the
# app id is the single top-level entry with no siblings.
TARBALL="chamade_talk-${MQSR_VERSION}.tar.gz"
STAGING=$(mktemp -d)
trap 'rm -rf "$STAGING"' EXIT
cp -a "$SCRIPT_DIR" "$STAGING/chamade_talk"
rm -rf "$STAGING/chamade_talk/.git" \
       "$STAGING/chamade_talk/publish.sh"
find "$STAGING/chamade_talk" -maxdepth 1 -name '*.tar.gz' -delete
tar czf "$CHAMADE_DIR/static/$TARBALL" -C "$STAGING" chamade_talk
echo "Built $TARBALL ✓"

# ── Step 10: Update doc version + commit chamade ──
DOC_FILE="$CHAMADE_DIR/chamade/templates/docs/nctalk.html"
if [[ -f "$DOC_FILE" ]]; then
    # Replace any chamade_talk-X.Y.Z.tar.gz reference
    sed -i "s/chamade_talk-[0-9.]*\.tar\.gz/$TARBALL/g" "$DOC_FILE"
fi

cd "$CHAMADE_DIR"
if ! git diff --quiet -- static/"$TARBALL" chamade/templates/docs/nctalk.html 2>/dev/null; then
    git add "static/$TARBALL" "chamade/templates/docs/nctalk.html"
    git -c user.name="Skilpa" -c user.email="contact@skilpa.be" \
        commit -m "$(cat <<EOF
Update NC Talk addon to v${MQSR_VERSION}

Co-Authored-By: Claude Opus 4.6 (1M context) <noreply@anthropic.com>
EOF
    )"
    GIT_SSH_COMMAND="ssh -i ~/.ssh/chamade_codeberg -o IdentitiesOnly=yes" git push
    echo "Chamade repo updated ✓"
fi

echo ""
echo "=== Done: chamade_talk v${MQSR_VERSION} ==="
echo "  Codeberg: https://codeberg.org/skilpa/chamade-nctalk"
echo "  Download: /static/$TARBALL"
echo ""
echo "To deploy prod:"
echo "  ssh citadelle 'git -C /srv/apps/chamade pull && sudo systemctl restart chamade'"
