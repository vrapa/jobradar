"""Exercise the installed action sync MCP without creating Todoist tasks."""
import json
import os
from pathlib import Path
import queue
import subprocess
import threading
import tomllib
import argparse

parser = argparse.ArgumentParser()
parser.add_argument('--applications', action='store_true')
options = parser.parse_args()
server_name = 'jobradar-applications' if options.applications else 'jobradar-actions'

config_path = Path(os.environ.get('CODEX_HOME', str(Path.home() / '.codex'))) / 'config.toml'
with config_path.open('rb') as config_file:
    config = tomllib.load(config_file)['mcp_servers'][server_name]
command = [config['command'], *config.get('args', [])]
messages = [
    {'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {'protocolVersion': '2024-11-05', 'capabilities': {}, 'clientInfo': {'name': 'synthetic-action-probe', 'version': '1'}}},
    {'jsonrpc': '2.0', 'method': 'notifications/initialized'},
    {'jsonrpc': '2.0', 'id': 2, 'method': 'tools/list'},
    {'jsonrpc': '2.0', 'id': 3, 'method': 'tools/call', 'params': {'name': 'list_pending_action_items', 'arguments': {}}},
]
process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
    stderr=subprocess.PIPE, encoding='utf-8', cwd=config.get('cwd'),
    env={**os.environ, **config.get('env', {})})
lines = queue.Queue()
threading.Thread(target=lambda: [lines.put(line) for line in process.stdout], daemon=True).start()
replies = []
try:
    for message in messages:
        process.stdin.write(json.dumps(message) + '\n')
        process.stdin.flush()
        if 'id' in message:
            try:
                replies.append(json.loads(lines.get(timeout=20)))
            except queue.Empty:
                raise AssertionError('Configured action MCP did not respond while stdin remained open') from None
finally:
    process.stdin.close()
    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        process.terminate()
        process.wait(timeout=5)
    process.stdout.close()
    process.stderr.close()
assert process.returncode == 0, 'Configured action MCP process failed'
assert [reply['id'] for reply in replies] == [1, 2, 3], 'Incomplete stdio exchange'
tool_names = {tool['name'] for tool in replies[1]['result']['tools']}
assert {'list_pending_action_items', 'link_todoist_task', 'complete_action_item'} <= tool_names
assert {'record_application_event', 'list_linked_action_items', 'acknowledge_todoist_status'} <= tool_names
assert not replies[2]['result'].get('isError'), 'Action item API read failed'
assert replies[2]['result']['structuredContent']['meta']['count'] >= 0
print('Configured action MCP: initialize, tools/list and read-only pending-action probe passed; no Todoist task was created.')
