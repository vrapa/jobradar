"""Read-only check of registered scoped MCP pipes, fresh connections and real idle time."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import json
import os
from pathlib import Path
import queue
import subprocess
import threading
import time
import tomllib


READS = {
    'jobradar-executor': ('execute', {'operation': 'status'}),
    'jobradar-actions': ('list_pending_action_items', {}),
    'jobradar-applications': ('list_opportunities', {'tenderStatus': 'all'}),
}


class Connection:
    def __init__(self, name, configuration):
        self.name = name
        self.timeout = configuration.get('startup_timeout_sec', 120)
        self.tool_timeout = configuration.get('tool_timeout_sec', 60)
        self.sequence = 0
        self.output = queue.Queue()
        self.started = time.monotonic()
        self.process = subprocess.Popen([configuration['command'], *configuration.get('args', [])],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True, encoding='utf-8', cwd=configuration.get('cwd'),
            env={**os.environ, **configuration.get('env', {}), 'PYTHONDONTWRITEBYTECODE': '1'})

        def drain_stdout():
            for line in self.process.stdout:
                self.output.put(line)
            self.output.put(None)

        def drain_stderr():
            # Deliberately discard runtime diagnostics: never echo potential private data.
            for _ in self.process.stderr:
                pass

        threading.Thread(target=drain_stdout, daemon=True).start()
        threading.Thread(target=drain_stderr, daemon=True).start()

    def send(self, message):
        if self.process.poll() is not None:
            raise AssertionError(self.name + ': exited with an open input pipe')
        self.process.stdin.write(json.dumps(message) + '\n')
        self.process.stdin.flush()

    def request(self, method, params=None, timeout=None):
        self.sequence += 1
        self.send({'jsonrpc': '2.0', 'id': self.sequence, 'method': method, 'params': params or {}})
        deadline = time.monotonic() + (timeout or self.tool_timeout)
        while True:
            try:
                line = self.output.get(timeout=max(0.001, deadline - time.monotonic()))
            except queue.Empty:
                raise AssertionError(self.name + ': MCP request timed out') from None
            if line is None:
                raise AssertionError(self.name + ': unexpected EOF')
            reply = json.loads(line)
            if 'id' not in reply:
                continue
            if reply.get('id') != self.sequence or 'result' not in reply:
                raise AssertionError(self.name + ': invalid MCP response')
            result = reply['result']
            if result.get('isError'):
                raise AssertionError(self.name + ': read-only API check failed')
            return result

    def initialize(self):
        self.request('initialize', {'protocolVersion': '2024-11-05', 'capabilities': {},
                     'clientInfo': {'name': 'read-only-connection-probe', 'version': '1'}}, timeout=self.timeout)
        self.send({'jsonrpc': '2.0', 'method': 'notifications/initialized'})
        self.check()
        return round(time.monotonic() - self.started, 3)

    def check(self):
        tools = self.request('tools/list')['tools']
        tool, arguments = READS[self.name]
        if tool not in {item['name'] for item in tools}:
            raise AssertionError(self.name + ': expected tool absent')
        self.request('tools/call', {'name': tool, 'arguments': arguments})
        self.request('ping')

    def close(self):
        failed = False
        try:
            self.process.stdin.close()
        except BrokenPipeError:
            failed = True
        try:
            code = self.process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            self.process.terminate()
            self.process.wait(timeout=10)
            raise AssertionError(self.name + ': failed to exit after EOF') from None
        finally:
            self.process.stdout.close()
            self.process.stderr.close()
        if failed or code != 0:
            raise AssertionError(self.name + ': unclean exit')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', type=Path, default=Path(os.environ.get('CODEX_HOME', Path.home() / '.codex')) / 'config.toml')
    parser.add_argument('--rounds', type=int, default=1)
    parser.add_argument('--idle-seconds', type=float, default=0)
    options = parser.parse_args()
    if options.rounds < 1 or options.idle_seconds < 0:
        parser.error('Rounds must be positive and idle time nonnegative.')
    configuration = tomllib.loads(options.config.read_text(encoding='utf-8-sig'))['mcp_servers']
    for iteration in range(options.rounds):
        connections = []
        try:
            for name in READS:
                connections.append(Connection(name, configuration[name]))
            with ThreadPoolExecutor(max_workers=len(connections)) as pool:
                timings = list(pool.map(lambda connection: connection.initialize(), connections))
            print('Fresh connections passed: ' + ', '.join(f'{c.name}={t}s' for c, t in zip(connections, timings)), flush=True)
            started = time.monotonic()
            next_report = 60
            while time.monotonic() - started < options.idle_seconds:
                if any(c.process.poll() is not None for c in connections):
                    raise AssertionError('A registered MCP exited during idle time')
                time.sleep(min(1, max(0, options.idle_seconds - (time.monotonic() - started))))
                elapsed = time.monotonic() - started
                if elapsed >= next_report:
                    print(f'Idle {int(elapsed)}s: all registered pipes remain open; no MCP requests sent.', flush=True)
                    next_report += 60
            if options.idle_seconds:
                for connection in connections:
                    connection.check()
                print(f'All registered read-only API checks passed after {options.idle_seconds:g}s idle.', flush=True)
        finally:
            for connection in connections:
                connection.close()
        print(f'Round {iteration + 1}: clean EOF shutdown passed; no work claimed or written.', flush=True)


if __name__ == '__main__':
    main()
