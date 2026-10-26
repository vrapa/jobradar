"""Repair Codex MCP startup settings without issuing or rotating credentials."""
import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import shutil
import sys
import tempfile
import tomllib


SERVERS = ('jobradar', 'jobradar-executor', 'jobradar-actions', 'jobradar-applications')


def set_fields(section, fields):
    for name, value in fields.items():
        line = name + ' = ' + json.dumps(value, ensure_ascii=False)
        pattern = r'(?m)^' + re.escape(name) + r'\s*=.*$'
        if re.search(pattern, section):
            section = re.sub(pattern, lambda match: line, section)
        else:
            section = section.rstrip() + '\n' + line + '\n\n'
    return section


def configure_text(source, *, root, server=None, command=None, credential=None, container='JobRadar-web'):
    before = tomllib.loads(source)
    fields = {name: {'startup_timeout_sec': 120, 'tool_timeout_sec': 60,
                     'required': False, 'cwd': str(root)}
              for name in SERVERS if name in before.get('mcp_servers', {})}
    if server:
        if server not in SERVERS or server == 'jobradar':
            raise ValueError('Only scoped JobRadar registrations can be repaired.')
        entry = 'stdio.py' if server == 'jobradar-executor' else 'actions_stdio.py'
        arguments = [str(root / 'executor' / entry), '--credential-file', str(credential)]
        if server != 'jobradar-executor':
            arguments += ['--container', container]
        fields[server] = {'command': str(command), 'args': arguments,
                          'startup_timeout_sec': 120, 'tool_timeout_sec': 60,
                          'required': False, 'cwd': str(root)}
    # Only the root table and exact server tables are changed; nested env/tool tables survive.
    sections = re.split(r'(?m)(?=^\[)', source)
    sections[0] = set_fields(sections[0], {'mcp_optional_startup_grace_ms': 0})
    found = set()
    for index, section in enumerate(sections[1:], 1):
        for name, values in fields.items():
            if section.startswith('[mcp_servers.' + name + ']'):
                sections[index] = set_fields(section, values)
                found.add(name)
    for name in fields.keys() - found:
        sections.append(set_fields('\n[mcp_servers.' + name + ']\n', fields[name]))
    result = ''.join(sections)
    after = tomllib.loads(result)
    # Fail closed if editing has changed anything outside the intended fields.
    expected = dict(before)
    expected['mcp_optional_startup_grace_ms'] = 0
    expected['mcp_servers'] = {key: dict(value) for key, value in before.get('mcp_servers', {}).items()}
    for name, values in fields.items():
        expected['mcp_servers'].setdefault(name, {}).update(values)
    if after != expected:
        raise ValueError('Unexpected change outside JobRadar startup settings.')
    return result


def repair(config_path, **options):
    original = config_path.read_bytes()
    updated = configure_text(original.decode('utf-8-sig'), **options).encode('utf-8')
    if updated == original:
        return False
    backup = config_path.with_name(config_path.name + '.before-jobradar-' +
                                   datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ'))
    shutil.copy2(config_path, backup)
    temp_path = None
    try:
        with tempfile.NamedTemporaryFile(dir=config_path.parent, delete=False) as temp:
            temp_path = Path(temp.name)
            temp.write(updated)
            temp.flush()
            os.fsync(temp.fileno())
        if config_path.read_bytes() != original:
            raise ValueError('Codex configuration changed concurrently; retry the repair.')
        os.replace(temp_path, config_path)
        return True
    finally:
        if temp_path is not None and temp_path.exists():
            temp_path.unlink()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', type=Path, default=Path(os.environ.get('CODEX_HOME', Path.home() / '.codex')) / 'config.toml')
    parser.add_argument('--server', choices=SERVERS[1:])
    parser.add_argument('--credential-file', type=Path)
    parser.add_argument('--container', default='JobRadar-web')
    options = parser.parse_args()
    if options.server and (options.credential_file is None or not options.credential_file.is_file()):
        parser.error('Repair requires an existing protected credential file.')
    try:
        changed = repair(options.config, root=Path(__file__).resolve().parents[1],
                         server=options.server, command=Path(sys.executable),
                         credential=options.credential_file, container=options.container)
    except (OSError, ValueError):
        print('JobRadar MCP configuration repair failed; configuration and credentials were not printed.', file=sys.stderr)
        return 1
    print('JobRadar MCP startup configuration ' + ('updated; reconnect MCP sessions.' if changed else 'already up to date.'))
    return 0


if __name__ == '__main__':
    sys.exit(main())
