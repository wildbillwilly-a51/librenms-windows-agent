#!/usr/bin/env bash
set -euo pipefail

VERSION="${VERSION:-0.6.4}"
LIBRENMS_ROOT="${LIBRENMS_ROOT:-/opt/librenms}"
REPO_OWNER="${REPO_OWNER:-wildbillwilly-a51}"
REPO_NAME="${REPO_NAME:-librenms-windows-agent-installer}"
REPO_BRANCH="${REPO_BRANCH:-main}"
BASE_URL="${BASE_URL:-https://raw.githubusercontent.com/${REPO_OWNER}/${REPO_NAME}/${REPO_BRANCH}}"
PACKAGE_NAME="librenms-windows-agent-overlay-${VERSION}.tar.gz"
PACKAGE_URL="${PACKAGE_URL:-${BASE_URL}/artifacts/${PACKAGE_NAME}}"
CHECKSUM_URL="${CHECKSUM_URL:-${BASE_URL}/SHA256SUMS}"
DRY_RUN=0

usage() {
  cat <<'EOF'
usage: install.sh [--dry-run] [--librenms-root PATH] [--version VERSION]

Installs the generic Windows Agent LibreNMS overlay on a LibreNMS server or
distributed poller node.

Environment overrides:
  LIBRENMS_ROOT   LibreNMS root path. Default: /opt/librenms
  VERSION         Overlay package version. Default: 0.6.3
  BASE_URL        Raw GitHub base URL for install assets
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run)
      DRY_RUN=1
      shift
      ;;
    --librenms-root)
      LIBRENMS_ROOT="${2:?missing value for --librenms-root}"
      shift 2
      ;;
    --version)
      VERSION="${2:?missing value for --version}"
      PACKAGE_NAME="librenms-windows-agent-overlay-${VERSION}.tar.gz"
      PACKAGE_URL="${BASE_URL}/artifacts/${PACKAGE_NAME}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root, for example: curl -fsSL ${BASE_URL}/install.sh | sudo bash" >&2
  exit 2
fi

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1" >&2
    exit 2
  fi
}

for command_name in bash curl tar sha256sum php sudo systemctl install; do
  require_command "$command_name"
done

if [[ ! -f "$LIBRENMS_ROOT/validate.php" ]]; then
  echo "LibreNMS root does not look valid: $LIBRENMS_ROOT" >&2
  echo "Set LIBRENMS_ROOT=/path/to/librenms if your install uses another path." >&2
  exit 2
fi

if [[ "$DRY_RUN" -eq 1 ]]; then
  cat <<EOF
Dry run:
  LibreNMS root: $LIBRENMS_ROOT
  Package URL:   $PACKAGE_URL
  Checksum URL:  $CHECKSUM_URL
  Version:       $VERSION
EOF
  exit 0
fi

work_dir="$(mktemp -d)"
cleanup() {
  rm -rf "$work_dir"
}
trap cleanup EXIT

cd "$work_dir"

echo "Downloading Windows Agent LibreNMS overlay ${VERSION}"
curl -fsSL "$PACKAGE_URL" -o "$PACKAGE_NAME"
curl -fsSL "$CHECKSUM_URL" -o SHA256SUMS

grep "  artifacts/${PACKAGE_NAME}$" SHA256SUMS > SHA256SUMS.current
sed "s#  artifacts/${PACKAGE_NAME}#  ${PACKAGE_NAME}#" SHA256SUMS.current > SHA256SUMS.local
sha256sum -c SHA256SUMS.local

mkdir package
tar -xzf "$PACKAGE_NAME" -C package --strip-components=1
cd package

echo "Installing overlay into $LIBRENMS_ROOT"
bash ./install-overlay.sh --librenms-root "$LIBRENMS_ROOT"
bash ./validate-overlay.sh --librenms-root "$LIBRENMS_ROOT"

install -m 0644 systemd/librenms-windows-agent-overlay-reapply.service /etc/systemd/system/
install -m 0644 systemd/librenms-windows-agent-overlay-reapply.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now librenms-windows-agent-overlay-reapply.timer

cat <<EOF
Windows Agent LibreNMS overlay installed and validated.

Next steps:
  1. Enable the unix-agent module on the Windows device in LibreNMS.
  2. Confirm pollers can reach the Windows agent on TCP 6556.
  3. Force a first poll when ready:
     cd "$LIBRENMS_ROOT"
     sudo -u librenms php "$LIBRENMS_ROOT/lnms" device:poll "<DEVICE_ID>" --modules="unix-agent,applications"
EOF
