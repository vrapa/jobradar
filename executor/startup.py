"""Bounded, read-only startup checks; stdout belongs exclusively to MCP."""
import subprocess
import sys
import time


class StartupFailure(RuntimeError):
    pass


class StartupLog:
    def __init__(self, component, stream=None, clock=time.monotonic):
        self.component = component
        self.stream = stream if stream is not None else sys.stderr
        self.clock = clock
        self.started = clock()

    def emit(self, phase):
        # phase is a fixed program constant, never exception text or command output.
        elapsed = int((self.clock() - self.started) * 1000)
        print(f'JobRadar startup component={self.component} phase={phase} elapsed_ms={elapsed}',
              file=self.stream, flush=True)


def wait_for_runtime(container, log, *, budget=90.0, attempt_timeout=5.0,
                     run=subprocess.run, clock=time.monotonic, sleep=time.sleep):
    """Wait for Docker exec + PHP, without starting/restarting containers or reading stdin."""
    deadline = clock() + budget
    log.emit('runtime_wait')
    while clock() < deadline:
        try:
            result = run(['docker', 'exec', container, 'php', '-r', 'exit(0);'],
                         stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                         stderr=subprocess.DEVNULL,
                         timeout=min(attempt_timeout, max(0.001, deadline - clock())))
        except FileNotFoundError:
            raise StartupFailure('Docker executable is unavailable.') from None
        except subprocess.TimeoutExpired:
            result = None
        except OSError:
            raise StartupFailure('Docker runtime probe could not be started.') from None
        if result is not None and result.returncode == 0:
            log.emit('runtime_ready')
            return
        remaining = deadline - clock()
        if remaining > 0:
            sleep(min(2.0, remaining))
    raise StartupFailure('Docker/PHP runtime was not ready within the startup budget.')


def run_stdio_child(command, environment, log, *, popen=subprocess.Popen):
    """Inherit the real pipes exactly once; never replay an MCP request."""
    child = None
    try:
        child = popen(command, env=environment)
        log.emit('php_spawned')
        return child.wait()
    finally:
        if child is not None and child.poll() is None:
            child.terminate()
            try:
                child.wait(timeout=5)
            except subprocess.TimeoutExpired:
                child.kill()
                child.wait()
