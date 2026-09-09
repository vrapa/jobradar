"""No-network regression for UTF-8 MCP data on Windows pipes."""
import json
import os
from pathlib import Path
import subprocess
import sys
import unittest


class StdioEncodingTest(unittest.TestCase):
    def test_czech_and_non_codepage_characters_round_trip(self):
        value = 'Příliš žluťoučký kůň – 日本語 🧪'
        message = {'jsonrpc': '2.0', 'id': 1, 'method': 'ping', 'probe': value}
        result = subprocess.run(
            [sys.executable, '-c', "import server; server.dispatch = lambda executor, message: {'probe': message['probe']}; server.main()"],
            cwd=Path(__file__).parent,
            input=(json.dumps(message, ensure_ascii=False) + '\n').encode('utf-8'),
            capture_output=True, timeout=10,
            env={**os.environ, 'PYTHONIOENCODING': 'cp1250'},
        )
        self.assertEqual(0, result.returncode)
        self.assertEqual(value, json.loads(result.stdout.decode('utf-8'))['result']['probe'])
