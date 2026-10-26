from pathlib import Path
import tempfile
import tomllib
import unittest

from configure_mcp import configure_text, repair


class ConfigurationTests(unittest.TestCase):
    def test_preserves_unrelated_servers_credentials_and_nested_tables(self):
        source = '''model = "synthetic"
[mcp_servers.jobradar-applications]
command = "old-python"
args = ["old-script"]
[mcp_servers.jobradar-applications.env]
SYNTHETIC = "keep"
[mcp_servers.other]
required = true
startup_timeout_sec = 7
'''
        updated = configure_text(source, root=Path('/synthetic'))
        data = tomllib.loads(updated)
        self.assertEqual({'required': True, 'startup_timeout_sec': 7}, data['mcp_servers']['other'])
        self.assertEqual({'SYNTHETIC': 'keep'}, data['mcp_servers']['jobradar-applications']['env'])
        self.assertEqual(['old-script'], data['mcp_servers']['jobradar-applications']['args'])
        self.assertEqual(120, data['mcp_servers']['jobradar-applications']['startup_timeout_sec'])
        self.assertEqual(0, data['mcp_optional_startup_grace_ms'])
        self.assertEqual(updated, configure_text(updated, root=Path('/synthetic')))

    def test_registers_scoped_command_without_modifying_other_owner_registration(self):
        source = '[mcp_servers.jobradar]\ncommand = "docker"\nargs = ["keep"]\n'
        updated = configure_text(source, root=Path('/synthetic'), server='jobradar-applications',
                                 command=Path('/python'), credential=Path('/protected.xml'), container='synthetic')
        data = tomllib.loads(updated)['mcp_servers']
        self.assertEqual(['keep'], data['jobradar']['args'])
        self.assertEqual(str(Path('/python')), data['jobradar-applications']['command'])
        self.assertIn(str(Path('/protected.xml')), data['jobradar-applications']['args'])
        self.assertFalse(data['jobradar-applications']['required'])

    def test_backup_and_idempotent_repair(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'config.toml'
            original = '[mcp_servers.jobradar]\ncommand="docker"\n'
            path.write_text(original, encoding='utf-8')
            self.assertTrue(repair(path, root=Path('/synthetic')))
            self.assertFalse(repair(path, root=Path('/synthetic')))
            backups = list(Path(directory).glob('*.before-jobradar-*'))
            self.assertEqual(1, len(backups))
            self.assertEqual(original, backups[0].read_text(encoding='utf-8'))


if __name__ == '__main__':
    unittest.main()
