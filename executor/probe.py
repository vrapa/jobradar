"""Read-only connectivity probe. Never claims or creates a search request."""
import json
from server import Executor, dispatch

result = dispatch(Executor(), {'method': 'tools/call', 'params': {'name': 'execute', 'arguments': {'operation': 'status'}}})
print(json.dumps(result, ensure_ascii=False))
raise SystemExit(1 if result.get('isError') else 0)
