"""Read-only WeCom customer/remark-mobile feasibility probe.

Credentials and target phone are accepted as one JSON line on stdin, never argv.
Only official read endpoints are allowed. No raw contact data is persisted.
Optional --ssh-host runs the same code in memory on the existing server.
"""
import argparse
import base64
import json
import os
from pathlib import Path
import re
import sys
import urllib.error
import urllib.parse
import urllib.request


READ_PATHS = {
    "gettoken", "externalcontact/get_follow_user_list",
    "externalcontact/batch/get_by_user",
}


class ProbeError(Exception):
    def __init__(self, stage, code):
        self.stage, self.code = stage, code


def normalize_mobile(value):
    value = re.sub(r"[\s()\-]", "", str(value))
    if value.startswith("+86"):
        value = value[3:]
    elif value.startswith("0086"):
        value = value[4:]
    return value if re.fullmatch(r"1\d{10}", value) else None


def request(path, query, payload=None):
    if path not in READ_PATHS:
        raise ProbeError("endpoint", "NOT_READ_ONLY")
    url = "https://qyapi.weixin.qq.com/cgi-bin/" + path + "?" + urllib.parse.urlencode(query)
    body = None if payload is None else json.dumps(payload).encode()
    req = urllib.request.Request(url, data=body, headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=20) as response:
            result = json.load(response)
    except (OSError, ValueError, urllib.error.URLError):
        # Do not expose request URLs: they contain access tokens / the secret.
        raise ProbeError(path, "NETWORK_OR_RESPONSE_ERROR") from None
    if not isinstance(result, dict):
        raise ProbeError(path, "INVALID_RESPONSE")
    if result.get("errcode", 0) != 0:
        # Upstream errmsg can contain identifiers or diagnostic URLs; only output code.
        raise ProbeError(path, result["errcode"])
    return result


def probe(config, api=request):
    target = normalize_mobile(config.get("phone", ""))
    if not target or not config.get("corpid") or not config.get("secret"):
        raise ProbeError("input", "MISSING_OR_INVALID_INPUT")
    token = api("gettoken", {"corpid": config["corpid"], "corpsecret": config["secret"]}).get("access_token")
    if not token:
        raise ProbeError("gettoken", "TOKEN_MISSING")
    auth = {"access_token": token}
    users = api("externalcontact/get_follow_user_list", auth).get("follow_user")
    if not isinstance(users, list):
        raise ProbeError("get_follow_user_list", "INVALID_RESPONSE")
    users = sorted(set(users))
    scope = config.get("sales_userids")
    if scope is not None:
        if not isinstance(scope, list) or not scope or not set(scope).issubset(users):
            raise ProbeError("scope", "SALESPERSON_NOT_AUTHORIZED")
        users = sorted(set(scope))
    total, with_mobile, pages = 0, 0, 0
    matches = set()
    complete = True
    for offset in range(0, len(users), 100):
        batch, cursor, seen = users[offset:offset + 100], "", set()
        while True:
            if pages >= 100:
                complete = False
                break
            data = api("externalcontact/batch/get_by_user", auth,
                       {"userid_list": batch, "cursor": cursor, "limit": 100})
            rows = data.get("external_contact_list")
            if not isinstance(rows, list):
                raise ProbeError("batch/get_by_user", "INVALID_RESPONSE")
            pages += 1
            for row in rows:
                total += 1
                follow = row.get("follow_info", {})
                mobiles = follow.get("remark_mobiles") or []
                if not isinstance(mobiles, list):
                    raise ProbeError("batch/get_by_user", "INVALID_MOBILES")
                normalized = {normalize_mobile(m) for m in mobiles} - {None}
                with_mobile += bool(normalized)
                if target in normalized:
                    uid = follow.get("userid")
                    external_id = row.get("external_contact", {}).get("external_userid")
                    if uid not in batch or not external_id:
                        raise ProbeError("batch/get_by_user", "INVALID_MATCH")
                    matches.add((external_id, uid))
            next_cursor = data.get("next_cursor") or ""
            if not next_cursor:
                break
            if next_cursor in seen:
                raise ProbeError("pagination", "REPEATED_CURSOR")
            seen.add(next_cursor)
            cursor = next_cursor
        if not complete:
            break
    return {
        "status": "MATCHED_REMARK_MOBILE" if matches else "NO_REMARK_MOBILE_MATCH",
        "phone_masked": target[:3] + "****" + target[-4:],
        "visible_sales_count": len(users), "relationships_checked": total,
        "relationships_with_mobile": with_mobile, "pages": pages,
        "complete_in_visible_scope": complete,
        "matched_customer_count": len({e for e, u in matches}),
        "matched_sales_userids": sorted({u for e, u in matches}),
        "limitation": "Matches sales-entered remark_mobiles only; no match does not prove not a contact. Multiple customer matches require manual verification.",
    }


def remote_probe(args, config):
    import paramiko
    client = paramiko.SSHClient()
    client.load_system_host_keys()
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    kwargs = dict(hostname=args.ssh_host, username="root", timeout=20,
                  look_for_keys=False, allow_agent=False)
    if os.environ.get("DESHOP_SSH_PASSWORD"):
        kwargs["password"] = os.environ["DESHOP_SSH_PASSWORD"]
    else:
        kwargs["key_filename"] = str(Path.home() / ".ssh" / "id_ed25519")
    try:
        client.connect(**kwargs)
        source = base64.b64encode(Path(__file__).read_bytes()).decode()
        command = 'python3 -c "import base64;exec(base64.b64decode(\'' + source + '\'))"'
        stdin, stdout, stderr = client.exec_command(command, timeout=600)
        stdin.write(json.dumps(config) + "\n")
        stdin.flush()
        stdin.channel.shutdown_write()
        output = stdout.read().decode()
        # Do not print SSH stderr or config on failure.
        if stdout.channel.recv_exit_status() not in (0, 2):
            raise ProbeError("ssh_execution", "FAILED")
        return json.loads(output)
    except ProbeError:
        raise
    except Exception:
        raise ProbeError("ssh", "CONNECTION_OR_EXECUTION_FAILED") from None
    finally:
        client.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--ssh-host")
    args = parser.parse_args()
    try:
        config = json.loads(sys.stdin.readline())
        result = remote_probe(args, config) if args.ssh_host else probe(config)
    except ProbeError as error:
        result = {"status": "BLOCKED", "stage": error.stage, "code": error.code}
    except Exception:
        result = {"status": "BLOCKED", "stage": "input_or_runtime", "code": "INVALID_INPUT_OR_RESPONSE"}
    print(json.dumps(result, ensure_ascii=True), flush=True)
    return 2 if result["status"] == "BLOCKED" else 0


if __name__ == "__main__":
    sys.exit(main())
