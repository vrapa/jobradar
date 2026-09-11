"""JobRadar execution-only MCP, newline JSON-RPC over stdio. No DB access.

Python 3.11+, standard library. Credentials come from the process environment.
Lease renewal runs independently of Chrome; tokens never reach model/tool output.
"""
import json
import os
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise RuntimeError("API redirect refused")


class Executor:
    def __init__(self, api=None, clock=time.monotonic):
        self.api = api or self.http
        self.clock = clock
        self.lock = threading.RLock()
        self.lease = None
        self.last_activity = 0
        self.claimed_at = 0
        self.failure = None
        self.finished = False

    @staticmethod
    def http(body):
        base = os.environ.get("JOBRADAR_EXECUTOR_API_URL", "http://jobradar.localhost/api/v1").rstrip("/")
        parsed = urllib.parse.urlsplit(base)
        if parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise RuntimeError("Invalid API URL")
        if parsed.scheme != "https" and not (parsed.scheme == "http" and (parsed.hostname in ("localhost", "127.0.0.1", "::1") or (parsed.hostname or "").endswith(".localhost"))):
            raise RuntimeError("API requires HTTPS outside localhost")
        token = os.environ.get("JOBRADAR_EXECUTOR_TOKEN", "")
        if not token:
            raise RuntimeError("Executor credential is not configured")
        headers = {"Content-Type": "application/json", "Authorization": "Bearer " + token}
        # Browsers resolve *.localhost to loopback; Windows getaddrinfo may not.
        # Preserve the virtual-host header, but connect only to loopback for local HTTP.
        if parsed.scheme == "http" and (parsed.hostname or "").endswith(".localhost"):
            headers["Host"] = parsed.netloc
            base = urllib.parse.urlunsplit(("http", "127.0.0.1" + (":" + str(parsed.port) if parsed.port else ""), parsed.path, "", ""))
        request = urllib.request.Request(base + "/runner/execution", data=json.dumps(body).encode(),
            headers=headers, method="POST")
        try:
            with urllib.request.build_opener(NoRedirect).open(request, timeout=30) as response:
                return json.load(response)["data"]
        except urllib.error.HTTPError as error:
            # Do not echo server HTML, request headers, tokens or payloads.
            raise RuntimeError("Execution API rejected request (HTTP %s)" % error.code) from None
        except (urllib.error.URLError, TimeoutError, ValueError, KeyError):
            raise RuntimeError("Execution API unavailable or invalid response") from None

    def renew(self):
        with self.lock:
            self.expire_local_limits()
            if self.lease is None or self.finished:
                return
            try:
                self.api({"operation": "renew", "run_id": self.lease["run_id"], "lease_token": self.lease["lease_token"]})
            except RuntimeError:
                self.failure = "Lease renewal failed; stop external work. Stored checkpoints remain available."
                self.lease = None

    def expire_local_limits(self):
        if self.lease and not self.finished and (self.clock() - self.last_activity > 600 or self.clock() - self.claimed_at > 3600):
            self.failure = "Execution activity limit reached; stop Chrome and recover on the next authorized wake."
            self.lease = None

    def call(self, args):
        with self.lock:
            if not isinstance(args, dict) or set(args) - {"operation", "source_id", "idempotency_key", "payload"}:
                raise RuntimeError("Invalid execution arguments")
            operation = args.get("operation")
            self.expire_local_limits()
            if operation == "status":
                return {**self.api({"operation": "status"}), "active_run_id": self.lease["run_id"] if self.lease and not self.finished else None, "failure": self.failure}
            if operation == "claim":
                if self.lease and not self.finished:
                    raise RuntimeError("This MCP session already owns a run; use task")
                result = self.api({"operation": "claim"})
                self.lease = result.get("lease")
                self.failure = None
                self.finished = False
                self.last_activity = self.claimed_at = self.clock()
                return {"lease": {k: v for k, v in self.lease.items() if k != "lease_token"} if self.lease else None}
            if operation not in ("verify", "task", "prepare_access", "start", "checkpoint", "pause", "import", "assessment", "finish"):
                raise RuntimeError("Unknown operation")
            if not self.lease:
                raise RuntimeError(self.failure or "No run owned by this MCP session")
            self.last_activity = self.clock()
            try:
                result = self.api({**args, "run_id": self.lease["run_id"], "lease_token": self.lease["lease_token"]})
            except RuntimeError:
                if operation == "verify":
                    self.failure = "Lease verification failed; stop external work."
                    self.lease = None
                raise
            if operation == "pause" and result.get("paused"):
                self.finished = True
            if operation in ("finish", "prepare_access") and result.get("request_status") in ("complete", "partial", "cancelled", "waiting_for_login", "error"):
                self.finished = True  # Retain receipt for idempotent terminal retry, but stop renewal.
            return result


SCHEMA = {"type": "object", "additionalProperties": False, "required": ["operation"], "properties": {
    "operation": {"type": "string", "enum": ["status", "claim", "verify", "task", "prepare_access", "start", "checkpoint", "pause", "import", "assessment", "finish"]},
    "source_id": {"type": "integer", "minimum": 1},
    "idempotency_key": {"type": "string", "minLength": 16, "maxLength": 200},
    "payload": {"type": "object"}}}


def dispatch(executor, message):
    method = message.get("method")
    if method == "initialize":
        return {"protocolVersion": "2024-11-05", "capabilities": {"tools": {}},
                "serverInfo": {"name": "jobradar-executor", "version": "1.0.0"},
                "instructions": "Read executor/skills/jobradar-check/SKILL.md. Claim only existing explicitly requested work. Use Chrome. Never apply, send personal data, accept terms or change decisions. Stop external work on a lease failure."}
    if method == "ping":
        return {}
    if method == "tools/list":
        return {"tools": [{"name": "execute", "description": "Execution-only JobRadar API. status checks connectivity; claim takes at most one queued request; task returns scope, profile and payload schemas. Source mutations require a stable unique idempotency key. Lease is maintained internally.",
            "inputSchema": SCHEMA, "annotations": {"readOnlyHint": False, "destructiveHint": False, "idempotentHint": False, "openWorldHint": False}}]}
    if method == "tools/call":
        params = message.get("params", {})
        if params.get("name") != "execute":
            raise RuntimeError("Unknown tool")
        try:
            result = executor.call(params.get("arguments", {}))
            return {"content": [{"type": "text", "text": json.dumps(result, ensure_ascii=False, default=str)}], "isError": False}
        except RuntimeError as error:
            return {"content": [{"type": "text", "text": str(error)}], "isError": True}
    raise RuntimeError("Unknown method")


def main():
    # Windows pipe defaults can be cp1250; MCP is always UTF-8, including Czech task data.
    sys.stdin.reconfigure(encoding='utf-8')
    sys.stdout.reconfigure(encoding='utf-8')
    executor = Executor()
    stop = threading.Event()
    def heartbeat():
        while not stop.wait(60):
            executor.renew()
    threading.Thread(target=heartbeat, daemon=True).start()
    try:
        for line in sys.stdin:
            request = None
            try:
                request = json.loads(line)
                if not isinstance(request, dict) or "id" not in request:
                    continue
                result = dispatch(executor, request)
                response = {"jsonrpc": "2.0", "id": request["id"], "result": result}
            except (ValueError, RuntimeError, TypeError):
                response = {"jsonrpc": "2.0", "id": request.get("id") if isinstance(request, dict) else None,
                            "error": {"code": -32600, "message": "Invalid MCP request"}}
            print(json.dumps(response, ensure_ascii=False), flush=True)
    finally:
        stop.set()


if __name__ == "__main__":
    main()
