"""Synthetic MSIX/unpackaged credential paths, without real tokens or DPAPI."""
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from credentials import credential_path, resolve_credential


class CredentialPathTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.home = Path(self.temp.name)
        self.local = self.home / 'AppData' / 'Local'
        self.name = 'application-workflow-token.xml'

    def touch(self, path):
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text('synthetic-ciphertext', encoding='utf-8')
        return path

    def find(self):
        return credential_path(self.name, home=self.home, local=self.local)

    def test_new_install_uses_profile_storage_outside_virtualized_appdata(self):
        self.assertEqual(self.home / '.jobradar' / 'credentials' / self.name, self.find())

    def test_unpackaged_launcher_finds_msix_redirected_credential(self):
        physical = self.touch(self.local / 'Packages' / 'OpenAI.Codex_synthetic' / 'LocalCache' / 'Local' / 'JobRadar' / self.name)
        self.assertEqual(physical, self.find())
        with patch('credentials.Path.home', return_value=self.home), patch.dict('os.environ', {'LOCALAPPDATA': str(self.local)}):
            self.assertEqual(physical, resolve_credential(self.local / 'JobRadar' / self.name))

    def test_stable_storage_wins_without_selecting_an_old_cached_token(self):
        legacy = self.touch(self.local / 'JobRadar' / self.name)
        self.assertEqual(legacy, self.find())
        stable = self.touch(self.home / '.jobradar' / 'credentials' / self.name)
        self.assertEqual(stable, self.find())

    def test_multiple_redirected_stores_fail_closed(self):
        for package in ('OpenAI.Codex_first', 'OpenAI.Codex_second'):
            self.touch(self.local / 'Packages' / package / 'LocalCache' / 'Local' / 'JobRadar' / self.name)
        with self.assertRaisesRegex(ValueError, 'Ambiguous'):
            self.find()

    def test_arbitrary_explicit_missing_path_does_not_use_another_credential(self):
        self.touch(self.local / 'JobRadar' / self.name)
        with patch('credentials.Path.home', return_value=self.home):
            with self.assertRaises(FileNotFoundError):
                resolve_credential(self.home / 'other' / self.name)

    def test_existing_explicit_file_is_not_replaced_even_if_invalid(self):
        explicit = self.touch(self.home / 'explicit.xml')
        self.assertEqual(explicit, resolve_credential(explicit))

    def test_unknown_role_is_rejected(self):
        with self.assertRaises(ValueError):
            credential_path('unrelated.xml', home=self.home, local=self.local)


if __name__ == '__main__':
    unittest.main()
