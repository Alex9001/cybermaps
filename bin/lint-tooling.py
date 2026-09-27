#!/usr/bin/env python3
"""Parse Python tooling without producing bytecode outside generated output."""
import ast
from pathlib import Path

root = Path(__file__).resolve().parents[1]
for folder in ('bin', 'tests/tooling', 'tests/integration'):
    for path in sorted((root / folder).glob('*.py')):
        ast.parse(path.read_text(), filename=str(path))
print('Python tooling syntax valid.')
