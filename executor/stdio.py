"""Native STDIO entry point; credentials stay in memory."""
import argparse
import os
from pathlib import Path
import sys

from credentials import decrypt_credential
from startup import StartupLog


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--credential-file', type=Path, required=True)
    args = parser.parse_args()
    log = StartupLog('executor')
    log.emit('launcher_started')
    try:
        os.environ['JOBRADAR_EXECUTOR_TOKEN'] = decrypt_credential(args.credential_file)
    except Exception:
        # Never emit XML, encrypted/plain token, or exception context.
        print('JobRadar: unable to load the Windows-protected executor credential.', file=sys.stderr)
        sys.exit(1)
    log.emit('credential_ready')
    try:
        from server import main
        log.emit('python_ready')
        main()
    finally:
        os.environ.pop('JOBRADAR_EXECUTOR_TOKEN', None)
