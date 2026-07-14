#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
manifest_file="$script_dir/manifest.txt"
payload_dir="$script_dir/payload"

librenms_root="${LIBRENMS_ROOT:-/opt/librenms}"
expected_app_count="${WINDOWS_AGENT_EXPECTED_APP_COUNT:-}"
expected_metric_count="${WINDOWS_AGENT_EXPECTED_METRIC_COUNT:-}"
expected_device_ids="${WINDOWS_AGENT_EXPECTED_DEVICE_IDS:-}"
web_base_url="${WINDOWS_AGENT_LIBRENMS_URL:-}"
web_username="${WINDOWS_AGENT_LIBRENMS_USERNAME:-}"
web_password="${WINDOWS_AGENT_LIBRENMS_PASSWORD:-}"

usage() {
  cat <<'EOF'
usage: validate-overlay.sh [--librenms-root PATH] [--expected-app-count N]
                           [--expected-metric-count N] [--expected-device-ids CSV]

Validates package PHP syntax, installed files, general LibreNMS health,
optional DB application metrics, and optional Apps page visibility when web
credentials are provided through WINDOWS_AGENT_LIBRENMS_URL, WINDOWS_AGENT_LIBRENMS_USERNAME, and
WINDOWS_AGENT_LIBRENMS_PASSWORD. General LibreNMS health warnings do not fail overlay
validation because they may be unrelated to this package.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --librenms-root)
      librenms_root="${2:?missing value for --librenms-root}"
      shift 2
      ;;
    --expected-app-count)
      expected_app_count="${2:?missing value for --expected-app-count}"
      shift 2
      ;;
    --expected-metric-count)
      expected_metric_count="${2:?missing value for --expected-metric-count}"
      shift 2
      ;;
    --expected-device-ids)
      expected_device_ids="${2:?missing value for --expected-device-ids}"
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

if [[ ! -r "$manifest_file" ]]; then
  echo "Missing manifest: $manifest_file" >&2
  exit 2
fi

if [[ ! -f "$librenms_root/validate.php" ]]; then
  echo "LibreNMS root does not look valid: $librenms_root" >&2
  exit 2
fi

echo "Linting package PHP files"
if [[ -d "$payload_dir" ]]; then
  while IFS= read -r -d '' php_file; do
    php -l "$php_file"
  done < <(find "$payload_dir" -type f -name '*.php' -print0)
fi

echo "Checking installed files"
while IFS= read -r rel || [[ -n "$rel" ]]; do
  rel="${rel%$'\r'}"
  [[ -z "$rel" || "$rel" == \#* ]] && continue
  test -f "$librenms_root/$rel"
  echo "Present: $rel"
done < "$manifest_file"

echo "Running LibreNMS validate.php"
if ! sudo -u librenms php "$librenms_root/validate.php"; then
  echo "WARNING: LibreNMS validate.php reported issues unrelated to overlay file installation." >&2
  echo "WARNING: Review the validate.php output above, but continuing overlay-specific validation." >&2
fi

app_validator="$librenms_root/windows-agent-overlay/validate-app.php"
if [[ -n "$expected_app_count$expected_metric_count$expected_device_ids" ]]; then
  if [[ ! -f "$app_validator" ]]; then
    echo "App validator is not installed: $app_validator" >&2
    exit 2
  fi

  args=("--librenms-root" "$librenms_root")
  [[ -n "$expected_app_count" ]] && args+=("--expected-app-count" "$expected_app_count")
  [[ -n "$expected_metric_count" ]] && args+=("--expected-metric-count" "$expected_metric_count")
  [[ -n "$expected_device_ids" ]] && args+=("--expected-device-ids" "$expected_device_ids")
  sudo -u librenms php "$app_validator" "${args[@]}"
fi

if [[ -n "$web_base_url$web_username$web_password" ]]; then
  if [[ -z "$web_base_url" || -z "$web_username" || -z "$web_password" ]]; then
    echo "Set all web validation variables or none: WINDOWS_AGENT_LIBRENMS_URL, WINDOWS_AGENT_LIBRENMS_USERNAME, WINDOWS_AGENT_LIBRENMS_PASSWORD" >&2
    exit 2
  fi

  python3 "$script_dir/web-validate.py" \
    --base-url "$web_base_url" \
    --username "$web_username" \
    --password "$web_password" \
    --device-ids "${expected_device_ids:-}"
fi

echo "Overlay validation complete."
