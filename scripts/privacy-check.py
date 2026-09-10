#!/usr/bin/env python3
"""Fail closed on private files and recognizable secrets; never print matched values.

This guard supplements, but cannot replace, a semantic review of personal context.
History means all branches and tags (including deleted files), not reflog backups.
"""
import argparse
import pathlib
import re
import subprocess
import sys


RULES = {
    "private-key": rb"-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----",
    "github-token": rb"(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,})",
    "aws-key": rb"\bAKIA[0-9A-Z]{16}\b",
    "jobradar-token": rb"\bjr_[A-Za-z0-9_-]{43}\b",
    "api-key": rb"\bsk-(?:proj-)?[A-Za-z0-9_-]{40,}\b",
    "credential-url": rb"https?://[^\s/:<>]+:[^\s/@<>]+@(?!(?:example\.(?:test|com)|localhost)(?:[/:]|$))",
    "personal-home": rb"(?i)C:[/\\]Users[/\\](?!<|example|Public|Default)[a-z0-9_.-]+[/\\]",
}


def git(*args):
    return subprocess.check_output(["git", *args])


def private_path(path):
    p = pathlib.PurePosixPath(path)
    name = p.name.lower()
    if name in {".env.example", ".env.sample", ".gitignore"}:
        return False
    return (
        name in {".env", ".env.local", "auth.json", "cookies.txt", "cookies.json"}
        or ".private." in name or name.endswith(".local.json")
        or name.endswith((".bundle", ".sql.gz", ".p12", ".pfx", ".pem", ".key"))
        or any(part.lower() in {"private", "backups", "private-remediation"} for part in p.parts)
        or (p.parts and p.parts[0] == "var")
    )


def scan(path, data):
    result = ["private-path"] if private_path(path) else []
    result += [name for name, pattern in RULES.items() if re.search(pattern, data)]
    if data.startswith(b"version https://git-lfs.github.com/spec/v1"):
        result.append("lfs-requires-separate-audit")
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--staged", action="store_true")
    mode.add_argument("--history", action="store_true")
    mode.add_argument("--working", action="store_true")
    args = parser.parse_args()
    failures = set()
    checked = 0
    if args.working:
        for path in git("ls-files", "-z", "--cached", "--others", "--exclude-standard").decode().split("\0"):
            if not path or not pathlib.Path(path).is_file():
                continue
            checked += 1
            failures.update((path, rule) for rule in scan(path, pathlib.Path(path).read_bytes()))
    else:
        if args.history:
            entries = [line.split(" ", 1) for line in git("rev-list", "--objects", "--branches", "--tags", "--remotes").decode().splitlines()]
        else:
            entries = []
            for record in git("ls-files", "--stage", "-z").decode().split("\0"):
                if record:
                    metadata, path = record.split("\t", 1)
                    entries.append([metadata.split()[1], path])
        # Persistent batch process avoids one subprocess per historical object.
        with subprocess.Popen(["git", "cat-file", "--batch"], stdin=subprocess.PIPE, stdout=subprocess.PIPE) as proc:
            for entry in entries:
                oid, path = entry[0], entry[1] if len(entry) > 1 else "commit-metadata"
                proc.stdin.write((oid + "\n").encode())
                proc.stdin.flush()
                header = proc.stdout.readline().split()
                if len(header) != 3:
                    raise RuntimeError("Unable to read Git object")
                data = proc.stdout.read(int(header[2]))
                proc.stdout.read(1)
                if header[1] not in {b"blob", b"commit", b"tag"}:
                    continue
                checked += 1
                failures.update((path, rule) for rule in scan(path, data))
            proc.stdin.close()
            if proc.wait() != 0:
                raise RuntimeError("Git object scan failed")
    for path, rule in sorted(failures):
        print(f"{path}: {rule}")
    print(f"Privacy guard: {checked} objects/files checked; {len(failures)} findings.")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
