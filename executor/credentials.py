"""Decrypt a Windows DPAPI credential without loading the executor runtime."""
import ctypes
from ctypes import wintypes
import os
import xml.etree.ElementTree as ET


def decrypt_credential(path):
    if os.name != 'nt':
        raise RuntimeError('Windows DPAPI is required')
    element = ET.parse(path).find('.//{http://schemas.microsoft.com/powershell/2004/04}SS')
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
