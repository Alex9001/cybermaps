#!/usr/bin/env python3
"""Install pinned browser tooling only under the workspace's disposable directory."""
import os
from pathlib import Path
import shutil
import subprocess
from workspace import ROOT, configure

folder = configure() / 'browser-tools'
folder.mkdir(exist_ok=True)
for name in ('package.json', 'package-lock.json'):
    shutil.copyfile(ROOT / 'tests/browser' / name, folder / name)
os.environ['PLAYWRIGHT_BROWSERS_PATH'] = str(configure() / 'playwright-browsers')
subprocess.run(['npm', 'ci', '--ignore-scripts', '--prefix', str(folder)], check=True)
subprocess.run(['node', str(folder / 'node_modules/playwright/cli.js'), 'install', 'chromium'], check=True)
