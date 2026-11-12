"""Decrypt a Windows DPAPI credential without loading the executor runtime."""
import ctypes
from ctypes import wintypes
import os
from pathlib import Path
import argparse
import sys
import xml.etree.ElementTree as ET


NAMES = ('executor-token.xml', 'action-sync-token.xml', 'application-workflow-token.xml')


def credential_path(name, *, home=None, local=None):
    """Resolve only this user's known JobRadar stores; never guess another token."""
    if name not in NAMES:
        raise ValueError('Unknown credential role')
    home = Path(home) if home is not None else Path.home()
    stable = home / '.jobradar' / 'credentials' / name
    if stable.is_file():
        return stable
    local = Path(local) if local is not None else Path(os.environ.get('LOCALAPPDATA', home / 'AppData' / 'Local'))
    legacy = local / 'JobRadar' / name
    if legacy.is_file():
        return legacy
    # MSIX redirects AppData writes, but unpackaged MCP processes see the real path.
    packages = home / 'AppData' / 'Local' / 'Packages'
    matches = sorted(packages.glob('OpenAI.Codex_*/LocalCache/Local/JobRadar/' + name))
    if len(matches) > 1:
        raise ValueError('Ambiguous protected credential stores')
    return matches[0] if matches else stable


def resolve_credential(path):
    path = Path(path)
    if path.is_file():
        return path
    # Compatibility is deliberately limited to the documented legacy/default path.
    home = Path.home()
    legacy = home / 'AppData' / 'Local' / 'JobRadar'
    stable = home / '.jobradar' / 'credentials'
    if path.name in NAMES and path.parent in (legacy, stable):
        candidate = credential_path(path.name)
        if candidate.is_file():
            return candidate
    raise FileNotFoundError('Protected credential file is unavailable')


def decrypt_credential(path):
    if os.name != 'nt':
        raise RuntimeError('Windows DPAPI is required')
    element = ET.parse(resolve_credential(path)).find('.//{http://schemas.microsoft.com/powershell/2004/04}SS')
    if element is None or not element.text:
        raise ValueError('Missing protected credential')
    encrypted = bytes.fromhex(element.text)

    class Blob(ctypes.Structure):
        _fields_ = [('size', wintypes.DWORD), ('data', ctypes.POINTER(ctypes.c_ubyte))]

    buffer = (ctypes.c_ubyte * len(encrypted)).from_buffer_copy(encrypted)
    source = Blob(len(encrypted), buffer)
    result = Blob()
    crypt = ctypes.WinDLL('crypt32', use_last_error=True)
    kernel = ctypes.WinDLL('kernel32', use_last_error=True)
    crypt.CryptUnprotectData.argtypes = [ctypes.POINTER(Blob), ctypes.c_void_p,
        ctypes.c_void_p, ctypes.c_void_p, ctypes.c_void_p, wintypes.DWORD, ctypes.POINTER(Blob)]
    crypt.CryptUnprotectData.restype = wintypes.BOOL
    kernel.LocalFree.argtypes = [ctypes.c_void_p]
    kernel.LocalFree.restype = ctypes.c_void_p
    if not crypt.CryptUnprotectData(ctypes.byref(source), None, None, None, None, 1, ctypes.byref(result)):
        raise RuntimeError('DPAPI decryption failed')
    try:
        return ctypes.string_at(result.data, result.size).decode('utf-16-le')
    finally:
        ctypes.memset(result.data, 0, result.size)
        kernel.LocalFree(result.data)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Locate a protected credential; never print its contents.')
    parser.add_argument('--storage-path', choices=NAMES, required=True)
    args = parser.parse_args()
    try:
        print(credential_path(args.storage_path))
    except (OSError, ValueError):
        print('JobRadar: protected credential location is ambiguous or unavailable.', file=sys.stderr)
        sys.exit(1)
