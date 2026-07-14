#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
manifest_file="$script_dir/manifest.txt"

librenms_root="${LIBRENMS_ROOT:-/opt/librenms}"
backup_root="${WINDOWS_AGENT_OVERLAY_BACKUP_ROOT:-/var/backups/librenms-windows-agent-overlay}"
state_root="${WINDOWS_AGENT_OVERLAY_STATE_ROOT:-/usr/local/lib/librenms-windows-agent-overlay}"
reapply_command="${WINDOWS_AGENT_OVERLAY_REAPPLY_COMMAND:-/usr/local/sbin/librenms-windows-agent-overlay-reapply}"
backup_dir="${WINDOWS_AGENT_OVERLAY_BACKUP_DIR:-}"
dry_run=0
delete_apps=0
remove_state=0

usage() {
  cat <<'EOF'
usage: rollback-overlay.sh [--dry-run] [--librenms-root PATH] [--backup-dir PATH] [--delete-apps] [--remove-state]

Restores files from a previous install backup when available, otherwise removes
only files listed in manifest.txt. Optionally deletes windows-agent app rows.
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
    --backup-dir)
      backup_dir="${2:?missing value for --backup-dir}"
      shift 2
      ;;
    --delete-apps)
      delete_apps=1
      shift
      ;;
    --remove-state)
      remove_state=1
      shift
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

if [[ -z "$backup_dir" && -d "$backup_root" ]]; then
  backup_dir="$(find "$backup_root" -mindepth 1 -maxdepth 1 -type d | sort | tail -n 1 || true)"
fi

run() {
  if [[ "$dry_run" -eq 1 ]]; then
    printf 'DRY-RUN:'
    printf ' %q' "$@"
    printf '\n'
  else
    "$@"
  fi
}

echo "Rolling back overlay from $librenms_root"
if [[ -n "$backup_dir" ]]; then
  echo "Using backup directory: $backup_dir"
else
  echo "No backup directory found; listed files will be removed."
fi

if [[ "$delete_apps" -eq 1 ]]; then
  php_script="$librenms_root/windows-agent-overlay/delete-apps.php"
  if [[ "$dry_run" -eq 1 ]]; then
    echo "DRY-RUN: would delete windows-agent applications and metrics"
  elif [[ -f "$php_script" ]]; then
    sudo -u librenms php "$php_script" "$librenms_root"
  else
    echo "Cannot delete app rows; helper is not installed: $php_script" >&2
    exit 2
  fi
fi

while IFS= read -r rel || [[ -n "$rel" ]]; do
  rel="${rel%$'\r'}"
  [[ -z "$rel" || "$rel" == \#* ]] && continue

  dest="$librenms_root/$rel"
  backup="${backup_dir:+$backup_dir/$rel}"

  if [[ -n "$backup" && -f "$backup" ]]; then
    run sudo install -d -o librenms -g librenms "$(dirname "$dest")"
    run sudo install -o librenms -g librenms -m 0644 "$backup" "$dest"
    echo "Restored: $rel"
  elif [[ -e "$dest" ]]; then
    run sudo rm -f "$dest"
    echo "Removed: $rel"
  else
    echo "Absent: $rel"
  fi
done < "$manifest_file"

if [[ "$dry_run" -eq 1 ]]; then
  run sudo -u librenms php "$librenms_root/validate.php"
elif ! sudo -u librenms php "$librenms_root/validate.php"; then
  echo "WARNING: LibreNMS validate.php reported issues unrelated to overlay rollback." >&2
  echo "WARNING: Review the validate.php output above, but continuing rollback cleanup." >&2
fi

if [[ "$remove_state" -eq 1 ]]; then
  run sudo rm -f "$reapply_command"
  run sudo rm -rf "$state_root"
  echo "Removed overlay state and reapply command."
fi

echo "Overlay rollback complete."
