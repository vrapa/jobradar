"""Check real MCP pipes across idle time and EOF, without credentials or API calls."""
import argparse
import json
import os
from pathlib import Path
import queue
import subprocess
import sys
import threading
import time


class Probe:
    def __init__(self, name, command, environment, cwd):
        self.name = name
        self.process = subprocess.Popen(command, stdin=subprocess.PIPE,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
            encoding='utf-8', env=environment, cwd=cwd)
        self.lines = queue.Queue()

        def read_output():
            for line in self.process.stdout:
                self.lines.put(line)
            self.lines.put(None)

        threading.Thread(target=read_output, daemon=True).start()

    def send(self, message):
        self.assert_alive()
        self.process.stdin.write(json.dumps(message) + '\n')
        self.process.stdin.flush()

    def request(self, request_id, method, params=None):
        message = {'jsonrpc': '2.0', 'id': request_id, 'method': method}
        if params is not None:
            message['params'] = params
        self.send(message)
        try:
            line = self.lines.get(timeout=15)
        except queue.Empty:
            raise AssertionError(self.name + ': no MCP reply within 15 seconds') from None
        assert line is not None, self.name + ': EOF before MCP reply'
        reply = json.loads(line)
        assert reply.get('id') == request_id and 'result' in reply, self.name + ': invalid MCP reply'
        return reply['result']

    def assert_alive(self):
        assert self.process.poll() is None, self.name + ': MCP exited while stdin was open'

    def close(self):
        if not self.process.stdin.closed:
            try:
                self.process.stdin.close()
            except BrokenPipeError:
                pass
        try:
            return self.process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            self.process.terminate()
            self.process.wait(timeout=10)
            raise AssertionError(self.name + ': MCP did not stop after stdin EOF') from None
        finally:
            self.process.stdout.close()
            self.process.stderr.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--container', help='Run PHP in this existing container; otherwise use local php.')
    parser.add_argument('--idle-seconds', type=float, default=65)
    args = parser.parse_args()
    if args.idle_seconds <= 0:
        parser.error('--idle-seconds must be positive')
    root = Path(__file__).resolve().parents[1]
    environment = {**os.environ, 'JOBRADAR_MCP_TOKEN': 'synthetic-lifecycle-probe',
        'JOBRADAR_EXECUTOR_TOKEN': 'synthetic-lifecycle-probe',
        'JOBRADAR_MCP_IDLE_TIMEOUT_SECONDS': '60', 'PYTHONDONTWRITEBYTECODE': '1'}
    php = ['php', 'bin/jobradar-mcp']
    if args.container:
        php = ['docker', 'exec', '-i', '-e', 'JOBRADAR_MCP_TOKEN=synthetic-lifecycle-probe',
            '-e', 'JOBRADAR_MCP_IDLE_TIMEOUT_SECONDS=60', args.container, *php]
    probes = []
    try:
        for name, command in [('PHP', php), ('Python', [sys.executable, str(root / 'executor/server.py')])]:
            probe = Probe(name, command, environment, str(root))
            probes.append(probe)
            probe.request(1, 'initialize', {'protocolVersion': '2024-11-05',
                'capabilities': {}, 'clientInfo': {'name': 'synthetic-lifecycle-probe', 'version': '1'}})
            probe.send({'jsonrpc': '2.0', 'method': 'notifications/initialized'})
            probe.request(2, 'ping')
        print('PHP and Python initialized; stdin remains open.', flush=True)
        start = time.monotonic()
        next_progress = 60
        while time.monotonic() - start < args.idle_seconds:
            for probe in probes:
                probe.assert_alive()
            time.sleep(min(1, max(0, args.idle_seconds - (time.monotonic() - start))))
            elapsed = time.monotonic() - start
            if elapsed >= next_progress:
                print(f'Idle {int(elapsed)} seconds: both processes alive.', flush=True)
                next_progress += 60
        for probe in probes:
            tools = probe.request(3, 'tools/list')
            assert tools.get('tools'), probe.name + ': no tools after idle'
            probe.request(4, 'ping')
            print(f'{probe.name}: tools/list and ping passed after {args.idle_seconds:g} seconds idle.', flush=True)
        for probe in probes:
            assert probe.close() == 0, probe.name + ': nonzero exit after EOF'
            print(probe.name + ': clean exit after stdin EOF.', flush=True)
        probes.clear()
    finally:
        for probe in probes:
            probe.close()


if __name__ == '__main__':
    main()
