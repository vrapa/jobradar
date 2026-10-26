"""Run the scoped JobRadar action-item MCP with a DPAPI-protected token."""
import argparse
import os
from pathlib import Path
import sys

from credentials import decrypt_credential
from startup import StartupFailure, StartupLog, run_stdio_child, wait_for_runtime


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--credential-file', type=Path, required=True)
    parser.add_argument('--container', default='JobRadar-web')
    args = parser.parse_args()
    log = StartupLog('scoped-mcp')
    log.emit('launcher_started')
    try:
        token = decrypt_credential(args.credential_file)
    except Exception:
        print('JobRadar: unable to load the Windows-protected action sync credential.', file=sys.stderr)
        sys.exit(1)

    environment = os.environ.copy()
    environment['JOBRADAR_MCP_TOKEN'] = token
    token = ''
    log.emit('credential_ready')
    try:
        wait_for_runtime(args.container, log)
        sys.exit(run_stdio_child([
            'docker', 'exec', '-i',
            '-e', 'JOBRADAR_MCP_API_URL=http://localhost/api/v1',
            '-e', 'JOBRADAR_MCP_TOKEN',
            args.container, 'php', 'bin/jobradar-mcp',
        ], environment, log))
    except StartupFailure as error:
        print('JobRadar: ' + str(error), file=sys.stderr)
        sys.exit(1)
    except OSError:
        print('JobRadar: unable to start the MCP runtime.', file=sys.stderr)
        sys.exit(1)
    finally:
        environment.pop('JOBRADAR_MCP_TOKEN', None)
