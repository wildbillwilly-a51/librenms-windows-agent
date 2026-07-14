#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
payload_dir="$script_dir/payload"
manifest_file="$script_dir/manifest.txt"

librenms_root="${LIBRENMS_ROOT:-/opt/librenms}"
backup_root="${WINDOWS_AGENT_OVERLAY_BACKUP_ROOT:-/var/backups/librenms-windows-agent-overlay}"
state_root="${WINDOWS_AGENT_OVERLAY_STATE_ROOT:-/usr/local/lib/librenms-windows-agent-overlay}"
reapply_command="${WINDOWS_AGENT_OVERLAY_REAPPLY_COMMAND:-/usr/local/sbin/librenms-windows-agent-overlay-reapply}"
dry_run=0

usage() {
  cat <<'EOF'
usage: install-overlay.sh [--dry-run] [--librenms-root PATH] [--backup-root PATH] [--state-root PATH]

Installs the Windows Agent LibreNMS overlay from this package.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run)
      dry_run=1
      shift
      ;;
    --librenms-root)
      librenms_root="${2:?missing value for --librenms-root}"
      shift 2
      ;;
    --backup-root)
      backup_root="${2:?missing value for --backup-root}"
      shift 2
      ;;
    --state-root)
      state_root="${2:?missing value for --state-root}"
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

if [[ ! -d "$payload_dir" || ! -r "$manifest_file" ]]; then
  echo "Package is incomplete: expected payload/ and manifest.txt next to install-overlay.sh" >&2
  exit 2
fi

if [[ ! -f "$librenms_root/validate.php" ]]; then
  echo "LibreNMS root does not look valid: $librenms_root" >&2
  exit 2
fi

timestamp="$(date -u +%Y%m%d%H%M%S)"
backup_dir="$backup_root/$timestamp"
backups_made=0

run() {
  if [[ "$dry_run" -eq 1 ]]; then
    printf 'DRY-RUN:'
    printf ' %q' "$@"
    printf '\n'
  else
    "$@"
  fi
}

echo "Linting package PHP files"
while IFS= read -r -d '' php_file; do
  php -l "$php_file"
done < <(find "$payload_dir" -type f -name '*.php' -print0)

echo "Installing overlay to $librenms_root"

ensure_backup_dir() {
  if [[ "$dry_run" -eq 0 && "$backups_made" -eq 0 ]]; then
    sudo install -d -o librenms -g librenms "$backup_dir"
  fi
}

while IFS= read -r rel || [[ -n "$rel" ]]; do
  rel="${rel%$'\r'}"
  [[ -z "$rel" || "$rel" == \#* ]] && continue

  src="$payload_dir/$rel"
  dest="$librenms_root/$rel"
  backup="$backup_dir/$rel"

  if [[ ! -f "$src" ]]; then
    echo "Manifest entry is missing from payload: $rel" >&2
    exit 2
  fi

  run sudo install -d -o librenms -g librenms "$(dirname "$dest")"

  if [[ -e "$dest" ]] && cmp -s "$src" "$dest"; then
    echo "Unchanged: $rel"
    continue
  fi

  if [[ -e "$dest" ]]; then
    ensure_backup_dir
    run sudo install -d -o librenms -g librenms "$(dirname "$backup")"
    run sudo install -o librenms -g librenms -m 0644 "$dest" "$backup"
    backups_made=1
    echo "Backed up existing file: $rel"
  fi

  run sudo install -o librenms -g librenms -m 0644 "$src" "$dest"
  echo "Installed: $rel"
done < "$manifest_file"

if [[ "$dry_run" -eq 0 ]]; then
  if [[ "$backups_made" -eq 1 ]]; then
    sudo install -o librenms -g librenms -m 0644 "$manifest_file" "$backup_dir/manifest.txt"
  fi

  package_archive="$(mktemp)"
  tar -C "$script_dir" -czf "$package_archive" .
  sudo rm -rf "$state_root/current"
  sudo install -d -o root -g root -m 0755 "$state_root/current"
  sudo tar -xzf "$package_archive" -C "$state_root/current"
  rm -f "$package_archive"

  reapply_tmp="$(mktemp)"
  cat > "$reapply_tmp" <<EOF
#!/usr/bin/env bash
set -euo pipefail
export LIBRENMS_ROOT="\${LIBRENMS_ROOT:-$librenms_root}"
export WINDOWS_AGENT_OVERLAY_STATE_ROOT="\${WINDOWS_AGENT_OVERLAY_STATE_ROOT:-$state_root}"
exec bash "$state_root/current/install-overlay.sh" --librenms-root "\$LIBRENMS_ROOT" --state-root "\$WINDOWS_AGENT_OVERLAY_STATE_ROOT" "\$@"
EOF
  sudo install -o root -g root -m 0755 "$reapply_tmp" "$reapply_command"
  rm -f "$reapply_tmp"
  echo "Installed reapply command: $reapply_command"
fi

if [[ "$dry_run" -eq 1 ]]; then
  run sudo -u librenms php "$librenms_root/validate.php"
elif ! sudo -u librenms php "$librenms_root/validate.php"; then
  echo "WARNING: LibreNMS validate.php reported issues unrelated to overlay file installation." >&2
  echo "WARNING: Review the validate.php output above, but continuing overlay installation." >&2
fi

if [[ "$backups_made" -eq 1 || "$dry_run" -eq 1 ]]; then
  echo "Overlay install complete. Backup directory: $backup_dir"
else
  echo "Overlay install complete. No files changed; no backup directory created."
fi
