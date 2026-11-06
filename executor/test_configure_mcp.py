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
        self.assertEqual(['--profile', 'jobradar-applications'], data['jobradar-applications']['args'][-2:])
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

    def test_profiles_remove_unusable_tools_and_preserve_stricter_restrictions(self):
        source = '''[mcp_servers.jobradar]
enabled_tools = ["list_sources", "get_search_status"]
[mcp_servers.jobradar-actions]
disabled_tools = ["complete_action_item"]
[mcp_servers.jobradar-applications]
enabled_tools = ["get_opportunity"]
'''
        data = tomllib.loads(configure_text(source, root=Path('/synthetic')))['mcp_servers']
        self.assertEqual(['list_sources'], data['jobradar']['enabled_tools'])
        self.assertNotIn('list_sources', data['jobradar-actions']['enabled_tools'])
        self.assertNotIn('record_application_event', data['jobradar-actions']['enabled_tools'])
        self.assertIn('list_pending_action_items', data['jobradar-actions']['enabled_tools'])
        self.assertEqual(['complete_action_item'], data['jobradar-actions']['disabled_tools'])
        self.assertEqual(['get_opportunity'], data['jobradar-applications']['enabled_tools'])


if __name__ == '__main__':
    unittest.main()
