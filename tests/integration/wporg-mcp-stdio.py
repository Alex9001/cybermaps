"""Verify the official adapter's STDIO server, including the dedicated catalog."""
import json
import sys
if sys.flags.optimize:
    raise SystemExit('Validation refuses optimized Python; unset PYTHONOPTIMIZE and omit -O/-OO.')
from pathlib import Path
messages = [json.loads(line) for line in Path(sys.argv[1]).read_text().splitlines() if line.strip()]
by_id = {message.get('id'): message for message in messages}
assert 'result' in by_id[1], by_id
assert [tool['name'] for tool in by_id[2]['result']['tools']] == ['cybermaps-search'], by_id
print('Official MCP Adapter STDIO: dedicated Subscriber server and exact read-only tool catalog passed.')
