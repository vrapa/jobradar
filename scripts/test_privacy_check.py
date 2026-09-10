"""Regression checks using disposable, independently synthetic repositories."""
import pathlib
import subprocess
import sys
import tempfile
import unittest

SCANNER = pathlib.Path(__file__).with_name("privacy-check.py").resolve()


class PrivacyGuardTest(unittest.TestCase):
    def test_staged_ignored_file_and_deleted_history_are_detected(self):
        with tempfile.TemporaryDirectory() as folder:
            root = pathlib.Path(folder)
            def git(*args):
                return subprocess.run(["git", *args], cwd=root, check=True, capture_output=True)
            def scan(mode):
                return subprocess.run([sys.executable, str(SCANNER), mode], cwd=root, capture_output=True, text=True)
            git("init")
            git("config", "user.name", "Synthetic test")
            git("config", "user.email", "test@example.test")
            (root / ".gitignore").write_text(".env\n")
            (root / ".env").write_text("SYNTHETIC_FIXTURE=true\n")
            git("add", ".gitignore")
            self.assertEqual(0, scan("--staged").returncode)
            git("add", "-f", ".env")
            self.assertEqual(1, scan("--staged").returncode)
            git("commit", "-m", "Synthetic fixture")
            git("rm", ".env")
            git("commit", "-m", "Remove synthetic fixture")
            self.assertEqual(0, scan("--staged").returncode)
            self.assertEqual(1, scan("--history").returncode)

    def test_secret_value_is_never_printed(self):
        with tempfile.TemporaryDirectory() as folder:
            root = pathlib.Path(folder)
            subprocess.run(["git", "init"], cwd=root, check=True, capture_output=True)
            fake = "jr_" + "a" * 43
            (root / "fixture.txt").write_text(fake)
            subprocess.run(["git", "add", "."], cwd=root, check=True, capture_output=True)
            result = subprocess.run([sys.executable, str(SCANNER), "--staged"], cwd=root, capture_output=True, text=True)
            self.assertEqual(1, result.returncode)
            self.assertIn("jobradar-token", result.stdout)
            self.assertNotIn(fake, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
