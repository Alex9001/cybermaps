#!/usr/bin/env python3
"""Require explicit asset provenance and service inventory for distribution review."""
import json
from pathlib import Path
import re
from workspace import require

ROOT = Path(__file__).resolve().parents[1]
inventory = json.loads((ROOT / 'docs/dev/distribution-inventory.json').read_text())
assets = {str(path.relative_to(ROOT)) for path in (ROOT / 'assets').rglob('*') if path.is_file()}
require(inventory['schema_version'] == 1 and set(inventory['assets']) == assets, 'Asset inventory changed; review provenance')
for path, entry in inventory['assets'].items():
    require(entry['license'] == 'GPL-2.0-or-later' and entry['source'] == path and entry['build'], 'Asset provenance incomplete')
    require((ROOT / path).stat().st_size > 0, 'Empty asset')
callers = {str(path.relative_to(ROOT)) for path in (ROOT / 'src').rglob('*.php')
           if re.search(r'\bwp_(?:safe_)?remote_(?:get|post|head|request)\s*\(', path.read_text())}
require(set(inventory['network_callers']) == callers, 'Network caller inventory changed; review data and consent')
for entry in inventory['network_callers'].values():
    require(entry['service'] and entry['purpose_and_trigger'] and (ROOT / entry['disclosure']).is_file(), 'Service disclosure incomplete')
readme = (ROOT / 'readme.txt').read_text()
require('== Source Code ==' in readme and '== External Services ==' in readme, 'Readme source or services disclosure incomplete')
require('setup-wizard.js' in readme and 'No compilation' in readme, 'Readme source or services disclosure incomplete')
print(f'Distribution inventory: {len(assets)} editable assets; {len(callers)} network callers for policy review.')
