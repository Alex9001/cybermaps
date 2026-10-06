#!/usr/bin/env python3
"""Install pinned browser and WP-CLI tooling under the disposable directory."""
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

cli_folder = configure() / 'wp-cli-modern'
cli_folder.mkdir(exist_ok=True)
for name in ('composer.json', 'composer.lock'):
    shutil.copyfile(ROOT / 'tests/cli' / name, cli_folder / name)
subprocess.run(['composer', 'install', '--working-dir=' + str(cli_folder), '--no-dev', '--prefer-dist', '--no-interaction'], check=True)
