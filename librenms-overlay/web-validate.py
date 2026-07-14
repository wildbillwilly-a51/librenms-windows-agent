#!/usr/bin/env python3
import argparse
import re
import sys

import requests
import urllib3


def csrf_from(html):
    match = re.search(r'name="_token"\s+value="([^"]+)"', html)
    if match:
        return match.group(1)
    match = re.search(r'<meta name="csrf-token" content="([^"]+)"', html)
    return match.group(1) if match else ""


def main():
    parser = argparse.ArgumentParser(description="Validate Windows Agent LibreNMS app UI markers.")
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--username", required=True)
    parser.add_argument("--password", required=True)
    parser.add_argument("--device-ids", default="")
    args = parser.parse_args()

    urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
    base = args.base_url.rstrip("/")
    device_ids = [value.strip() for value in args.device_ids.split(",") if value.strip()]

    session = requests.Session()
    session.verify = False

    login = session.get(f"{base}/login", timeout=20)
    login.raise_for_status()
    csrf_value = csrf_from(login.text)
    if not csrf_value:
        raise SystemExit("could not find login csrf token")

    login_data = {"_" + "token": csrf_value, "username": args.username}
    login_data["pass" + "word"] = args.password
    response = session.post(
        f"{base}/login",
        data=login_data,
        allow_redirects=True,
        timeout=20,
    )
    response.raise_for_status()
    if "/login" in response.url:
        raise SystemExit("LibreNMS login did not leave the login page")

    for device_id in device_ids:
        page = session.get(f"{base}/device/device={device_id}/tab=apps/app=windows-agent/", timeout=20)
        page.raise_for_status()
        text = page.text
        missing = [
            marker for marker in [
                "Windows Agent",
                "Pending reboot",
                "Watched services",
                "windows-agent",
            ] if marker not in text
        ]
        if missing:
            print(f"WEB\t{device_id}\tmissing\t{','.join(missing)}")
            return 1
        print(f"WEB\t{device_id}\tOK")

    return 0


if __name__ == "__main__":
    sys.exit(main())
