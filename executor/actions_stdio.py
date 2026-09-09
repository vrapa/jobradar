"""Run the scoped JobRadar action-item MCP with a DPAPI-protected token."""
import argparse
import os
from pathlib import Path
import subprocess
import sys

from stdio import decrypt_credential


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--credential-file', type=Path, required=True)
    parser.add_argument('--container', default='JobRadar-web')
    args = parser.parse_args()
    try:
        token = decrypt_credential(args.credential_file)
    except Exception:
        print('JobRadar: unable to load the Windows-protected action sync credential.', file=sys.stderr)
        sys.exit(1)

    environment = os.environ.copy()
    environment['JOBRADAR_MCP_TOKEN'] = token
    token = ''
    try:
        sys.exit(subprocess.call([
            'docker', 'exec', '-i',
            '-e', 'JOBRADAR_MCP_API_URL=http://localhost/api/v1',
            '-e', 'JOBRADAR_MCP_TOKEN',
            args.container, 'php', 'bin/jobradar-mcp',
        ], env=environment))
    finally:
        environment.pop('JOBRADAR_MCP_TOKEN', None)
