from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parent
FORBIDDEN_PATHS = (
    b"/mnt/d/HRM",
    b"D:\\HRM",
    b"HRM_TestCases",
)


class SourcePathTest(unittest.TestCase):
    def test_executable_test_tools_do_not_use_machine_specific_roots(self) -> None:
        offenders: list[str] = []

        for path in sorted(ROOT.iterdir()):
            if path == Path(__file__).resolve() or path.suffix.lower() not in {".py", ".ps1"}:
                continue
            data = path.read_bytes()
            matches = [marker.decode() for marker in FORBIDDEN_PATHS if marker in data]
            if matches:
                offenders.append(f"{path.name}: {', '.join(matches)}")

        self.assertEqual([], offenders, "\n".join(offenders))


if __name__ == "__main__":
    unittest.main()
