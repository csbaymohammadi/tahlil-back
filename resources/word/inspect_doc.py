import json
import sys


def main():
    if len(sys.argv) != 2:
        print(json.dumps({"ok": False, "reason": "invalid_input"}))
        return 2
    try:
        from oletools.oleid import OleID
    except ImportError:
        print(json.dumps({"ok": False, "reason": "oletools_unavailable"}))
        return 3
    try:
        indicators = OleID(sys.argv[1]).check()
        blocked = {"encrypted", "vba", "xlm", "ObjectPool", "Flash"}
        for indicator in indicators:
            if indicator.id in blocked and str(indicator.value).lower() not in {"false", "no", "none", "0"}:
                print(json.dumps({"ok": False, "reason": "active_or_encrypted_document"}))
                return 0
        print(json.dumps({"ok": True}))
        return 0
    except Exception:
        print(json.dumps({"ok": False, "reason": "document_inspection_failed"}))
        return 0


if __name__ == "__main__":
    raise SystemExit(main())
