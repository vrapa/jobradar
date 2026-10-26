"""Exercise credential and STDIO boundaries without Docker, DPAPI or network calls."""
from contextlib import redirect_stderr, redirect_stdout
import io
import os
from pathlib import Path
import runpy
import sys
import unittest
from unittest.mock import patch

from startup import StartupFailure


class EntrypointTests(unittest.TestCase):
    def run_entrypoint(self, filename):
        stdout, stderr = io.StringIO(), io.StringIO()
        arguments = [filename, '--credential-file', 'synthetic-protected.xml']
        with patch.object(sys, 'argv', arguments), redirect_stdout(stdout), redirect_stderr(stderr):
            try:
                runpy.run_path(str(Path(__file__).parent / filename), run_name='__main__')
                code = 0
            except SystemExit as exit:
                code = exit.code
        return code, stdout.getvalue(), stderr.getvalue()

    def test_scoped_pipe_receives_credential_only_in_environment_and_is_never_replayed(self):
        seen = {}

        def child(command, environment, log):
            seen['environment'] = environment
            seen['credential_present'] = environment['JOBRADAR_MCP_TOKEN'] == 'synthetic-memory-only'
            seen['argument_contains_credential'] = 'synthetic-memory-only' in command
            return 7

        with patch('credentials.decrypt_credential', return_value='synthetic-memory-only'), \
             patch('startup.wait_for_runtime'), patch('startup.run_stdio_child', side_effect=child) as start:
            code, stdout, stderr = self.run_entrypoint('actions_stdio.py')
        self.assertEqual(7, code)
        self.assertEqual('', stdout)
        self.assertNotIn('synthetic-memory-only', stderr)
        self.assertTrue(seen['credential_present'])
        self.assertFalse(seen['argument_contains_credential'])
        self.assertNotIn('JOBRADAR_MCP_TOKEN', seen['environment'])
        self.assertEqual(1, start.call_count)

    def test_bad_credential_stops_before_docker_without_echoing_exception(self):
        with patch('credentials.decrypt_credential', side_effect=ValueError('synthetic-private-detail')), \
             patch('startup.wait_for_runtime') as wait, patch('startup.run_stdio_child') as start:
            code, stdout, stderr = self.run_entrypoint('actions_stdio.py')
        self.assertEqual(1, code)
        self.assertEqual('', stdout)
        self.assertNotIn('synthetic-private-detail', stderr)
        wait.assert_not_called()
        start.assert_not_called()

    def test_unavailable_runtime_does_not_launch_protocol_child(self):
        with patch('credentials.decrypt_credential', return_value='synthetic-memory-only'), \
             patch('startup.wait_for_runtime', side_effect=StartupFailure('Runtime is unavailable.')), \
             patch('startup.run_stdio_child') as start:
            code, stdout, stderr = self.run_entrypoint('actions_stdio.py')
        self.assertEqual(1, code)
        self.assertEqual('', stdout)
        self.assertIn('Runtime is unavailable.', stderr)
        self.assertNotIn('synthetic-memory-only', stderr)
        start.assert_not_called()

    def test_executor_passes_credential_in_memory_and_clears_it_after_exit(self):
        seen = []
        with patch.dict(os.environ, {}, clear=True), \
             patch('credentials.decrypt_credential', return_value='synthetic-memory-only'), \
             patch('server.main', side_effect=lambda: seen.append(os.environ['JOBRADAR_EXECUTOR_TOKEN'])):
            code, stdout, stderr = self.run_entrypoint('stdio.py')
            self.assertNotIn('JOBRADAR_EXECUTOR_TOKEN', os.environ)
        self.assertEqual(['synthetic-memory-only'], seen)
        self.assertEqual(0, code)
        self.assertEqual('', stdout)
        self.assertNotIn('synthetic-memory-only', stderr)


if __name__ == '__main__':
    unittest.main()
