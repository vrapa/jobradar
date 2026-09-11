"""Exercise installed stdio transport without claiming work or visiting portals."""
import json
from pathlib import Path
import subprocess
import os
import queue
import threading
import tomllib

config_path = Path(os.environ.get('CODEX_HOME', str(Path.home() / '.codex'))) / 'config.toml'
with config_path.open('rb') as config_file:
    config = tomllib.load(config_file)['mcp_servers']['jobradar-executor']
command = [config['command'], *config.get('args', [])]
messages = [
    {'jsonrpc': '2.0', 'id': 1, 'method': 'initialize', 'params': {'protocolVersion': '2024-11-05', 'capabilities': {}, 'clientInfo': {'name': 'synthetic-probe', 'version': '1'}}},
    {'jsonrpc': '2.0', 'method': 'notifications/initialized'},
    {'jsonrpc': '2.0', 'id': 2, 'method': 'tools/list'},
    {'jsonrpc': '2.0', 'id': 3, 'method': 'tools/call', 'params': {'name': 'execute', 'arguments': {'operation': 'status'}}},
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
                replies.append(json.loads(lines.get(timeout=15)))
            except queue.Empty:
                raise AssertionError('Configured MCP did not respond while stdin remained open') from None
finally:
    process.stdin.close()
    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        process.terminate()
        process.wait(timeout=5)
    process.stdout.close()
    process.stderr.close()
assert process.returncode == 0, 'Configured MCP process failed'
assert [reply['id'] for reply in replies] == [1, 2, 3], 'Incomplete stdio exchange'
assert replies[0]['result']['serverInfo']['name'] == 'jobradar-executor'
assert replies[1]['result']['tools'][0]['name'] == 'execute'
assert 'prepare_access' in replies[1]['result']['tools'][0]['inputSchema']['properties']['operation']['enum'], 'Executor does not support access preparation'
assert {'pause', 'verify'} <= set(replies[1]['result']['tools'][0]['inputSchema']['properties']['operation']['enum']), 'Executor does not support safe batch recovery'
assert not replies[2]['result'].get('isError'), 'Execution API status failed'
print('Configured MCP command: sequential initialize, tools/list and execute(status) passed with stdin open; no search was claimed.')
