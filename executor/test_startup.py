import io
import subprocess
from types import SimpleNamespace
import unittest
from unittest.mock import Mock

from startup import StartupFailure, StartupLog, run_stdio_child, wait_for_runtime


class StartupTests(unittest.TestCase):
    def setUp(self):
        self.now = 0
        self.output = io.StringIO()
        self.log = StartupLog('synthetic', self.output, lambda: self.now)

    def sleep(self, seconds):
        self.now += seconds

    def test_slow_runtime_retries_before_protocol_and_bounds_each_probe(self):
        run = Mock(side_effect=[subprocess.TimeoutExpired('synthetic', 5),
                                SimpleNamespace(returncode=1), SimpleNamespace(returncode=0)])
        wait_for_runtime('synthetic', self.log, run=run, clock=lambda: self.now, sleep=self.sleep)
        self.assertEqual(3, run.call_count)
        self.assertTrue(all(call.kwargs['timeout'] <= 5 for call in run.call_args_list))
        self.assertTrue(all(call.kwargs['stdin'] == subprocess.DEVNULL for call in run.call_args_list))
        self.assertIn('phase=runtime_ready', self.output.getvalue())

    def test_failed_runtime_exhausts_budget_without_dumping_output(self):
        run = Mock(return_value=SimpleNamespace(returncode=1, stderr='synthetic sensitive data'))
        with self.assertRaisesRegex(StartupFailure, 'startup budget'):
            wait_for_runtime('synthetic', self.log, budget=3, run=run,
                             clock=lambda: self.now, sleep=self.sleep)
        self.assertEqual(3, self.now)
        self.assertNotIn('sensitive', self.output.getvalue())

    def test_missing_docker_does_not_retry(self):
        run = Mock(side_effect=FileNotFoundError('synthetic private path'))
        with self.assertRaisesRegex(StartupFailure, 'executable is unavailable'):
            wait_for_runtime('synthetic', self.log, run=run)
        self.assertEqual(1, run.call_count)

    def test_probe_os_error_is_sanitized(self):
        run = Mock(side_effect=PermissionError('synthetic sensitive path'))
        with self.assertRaisesRegex(StartupFailure, 'probe could not be started') as result:
            wait_for_runtime('synthetic', self.log, run=run)
        self.assertNotIn('sensitive', str(result.exception))

    def test_stdio_child_is_started_once_even_on_nonzero_exit(self):
        child = Mock()
        child.wait.return_value = 7
        child.poll.return_value = 7
        popen = Mock(return_value=child)
        self.assertEqual(7, run_stdio_child(['synthetic'], {}, self.log, popen=popen))
        popen.assert_called_once_with(['synthetic'], env={})
        child.terminate.assert_not_called()

    def test_interrupted_wait_cleans_up_child_without_replaying_requests(self):
        child = Mock()
        child.wait.side_effect = [KeyboardInterrupt(), 0]
        child.poll.return_value = None
        popen = Mock(return_value=child)
        with self.assertRaises(KeyboardInterrupt):
            run_stdio_child(['synthetic'], {}, self.log, popen=popen)
        child.terminate.assert_called_once()
        self.assertEqual(1, popen.call_count)


if __name__ == '__main__':
    unittest.main()
