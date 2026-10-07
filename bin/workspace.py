#!/usr/bin/env python3
"""Shared local workspace, destination and package-installation safeguards."""
import argparse
from contextlib import contextmanager
import fcntl
import hashlib
import io
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import uuid
import zipfile

if sys.flags.optimize:
    raise SystemExit('Validation refuses optimized Python; unset PYTHONOPTIMIZE and omit -O/-OO.')

ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / '.cybermaps-workspace.json'


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def safe_path(value):
    """Reject aliases before resolving: no symlink component may be traversed."""
    path = Path(value)
    require(path.is_absolute() and '..' not in path.parts, 'Use an absolute, bounded path: ' + str(path))
    for item in [path, *path.parents]:
        require(not item.is_symlink(), 'Symlink destination refused: ' + str(item))
    require(path == path.resolve(), 'Noncanonical destination refused: ' + str(path))
    return path


def generated(relative=''):
    path = safe_path(ROOT / 'docs/generated' / relative)
    require(path.is_relative_to(ROOT / 'docs/generated'), 'Output must stay in docs/generated/.')
    return path


def configure():
    temporary = generated('tmp')
    temporary.mkdir(parents=True, exist_ok=True)
    for name in ('TMPDIR', 'TMP', 'TEMP'):
        os.environ[name] = str(temporary)
    os.environ['PYTHONDONTWRITEBYTECODE'] = '1'
    os.environ['GIT_OPTIONAL_LOCKS'] = '0'
    tempfile.tempdir = str(temporary)
    return temporary


def version():
    match = re.search(r'^[ \t*]*Version:[ \t]*(\d+\.\d+\.\d+)[ \t]*$',
                      (ROOT / 'cybermaps.php').read_text(), re.M)
    require(match, 'Cannot read plugin version.')
    return match[1]


def release_dir(number=None):
    number = number or version()
    require(re.fullmatch(r'\d+\.\d+\.\d+', number), 'Invalid release version.')
    return generated('releases/' + number)


def git(path, *args):
    return subprocess.check_output(['git', '-C', str(path), *args], text=True,
                                   env=dict(os.environ, GIT_OPTIONAL_LOCKS='0')).strip()


def run(*args, cwd=None, capture=True):
    """Save verbose gate output locally; keep the console to one line per gate."""
    log = os.environ.get('CYBERMAPS_WORKFLOW_LOG')
    if log:
        path = safe_path(log)
        require(path.is_relative_to(generated()), 'Workflow logs must stay inside docs/generated/.')
        with path.open('a') as stream:
            stream.write('\n$ ' + ' '.join(map(str, args)) + '\n')
            stream.flush()
            if not capture:
                print('Running: ' + ' '.join(map(str, args)), flush=True)
                result = subprocess.run(args, cwd=cwd, text=True, stdout=stream, stderr=subprocess.STDOUT)
                if result.returncode:
                    raise RuntimeError(f'Command failed ({result.returncode}); see {path}')
                return None
    result = subprocess.run(args, cwd=cwd, check=False, text=True,
                            stdout=subprocess.PIPE if capture else None,
                            stderr=subprocess.STDOUT if capture else None)
    if log and capture:
        with Path(log).open('a') as stream:
            stream.write(result.stdout)
    if result.returncode:
        raise subprocess.CalledProcessError(result.returncode, args, output=result.stdout)
    return result.stdout.strip() if capture else None


def clean_website(path):
    dirty = git(path, 'status', '--porcelain=v1', '--untracked-files=all')
    lines = dirty.splitlines()
    summary = '\n'.join(lines[:12]) + (f'\n... {len(lines)} changed paths in total.' if len(lines) > 12 else '')
    require(not dirty, 'Website has staged, unstaged or untracked work; no website commands were run. '
            'Review and commit it yourself before retrying.\n' + summary)


def no_source_tree(path):
    """Never remove a checkout, development tree, link or special file."""
    if not path.exists():
        return
    require(path.is_dir(), 'Expected a directory: ' + str(path))
    for parent, dirs, files in os.walk(path, followlinks=False):
        require(not ({'.git', '.hg', '.svn', 'composer.json', 'AGENTS.md'} & set(dirs + files)),
                'Refusing to overwrite a source repository: ' + str(parent))
        for name in dirs + files:
            entry = Path(parent) / name
            require(stat.S_ISDIR(entry.lstat().st_mode) or stat.S_ISREG(entry.lstat().st_mode),
                    'Symlink or special file refused: ' + str(entry))


def install_destination(path, website=None):
    path = safe_path(path)
    require(path.parts[-3:] == ('wp-content', 'plugins', 'cybermaps'),
            'Install destination must end in wp-content/plugins/cybermaps.')
    require(not (path.is_relative_to(ROOT) or ROOT.is_relative_to(path)),
            'Install destination overlaps the source repository.')
    if website:
        require(not (path.is_relative_to(website) or website.is_relative_to(path)),
                'Install destination overlaps the website.')
    require(path.parent.is_dir() and (path.parents[2] / 'wp-settings.php').is_file(),
            'Install destination is not an existing WordPress installation.')
    for parent in path.parents:
        if (parent / '.git').exists():
            # Ignored disposable WordPress fixtures may live under docs/generated/tmp.
            # A tracked install or any checkout inside the destination is still forbidden.
            ignored = subprocess.run(['git', '-C', str(parent), 'check-ignore', '--quiet', str(path)],
                                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
            require(ignored and path.is_relative_to(parent / 'docs/generated/tmp'),
                    'Install destination is inside a source repository.')
    no_source_tree(path)
    return path


def website_destination(path):
    path = safe_path(path)
    require(not (path.is_relative_to(ROOT) or ROOT.is_relative_to(path)),
            'Website must be separate from the plugin source repository.')
    require((path / '.git').is_dir() and git(path, 'rev-parse', '--show-toplevel') == str(path),
            'Website must be the canonical source checkout, not a worktree or nested directory.')
    require((path / 'package.json').is_file(), 'Website package.json is missing.')
    # These are the destinations written by docs:sync, including its temporary siblings.
    for name in ('product', 'public/product', 'public/specs', 'public/changelog.txt',
                 'src/content/pages/changelog.md'):
        target = safe_path(path / name)
        if target.is_dir():
            for parent, dirs, files in os.walk(target, followlinks=False):
                for entry in dirs + files:
                    safe_path(Path(parent) / entry)
    return path


def load_config(website_required=True):
    safe_path(CONFIG)
    require(CONFIG.is_file(), 'Copy .cybermaps-workspace.example.json to .cybermaps-workspace.json first.')
    data = json.loads(CONFIG.read_text())
    require(isinstance(data, dict) and set(data) == {'website', 'install'}
            and all(isinstance(v, str) and v for v in data.values()),
            'Workspace config must contain exactly the absolute website and install paths.')
    website = website_destination(data['website']) if website_required else safe_path(data['website'])
    return website, install_destination(data['install'], website)


@contextmanager
def lock():
    configure()
    with generated('tmp/workflow.lock').open('a') as stream:
        try:
            fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise RuntimeError('Another local release/install workflow is running.') from error
        yield


def save_json(path, data):
    path = safe_path(path)
    require(path.is_relative_to(generated()), 'Release state must stay in docs/generated/.')
    path.parent.mkdir(parents=True, exist_ok=True)
    pending = safe_path(path.with_name(path.name + '.pending'))
    pending.write_text(json.dumps(data, indent=2) + '\n')
    pending.replace(path)


def package_files(archive):
    """Read a bounded, portable ZIP before extracting anything."""
    archive = safe_path(archive)
    checksum = safe_path(Path(str(archive) + '.sha256'))
    payload = archive.read_bytes()
    digest = hashlib.sha256(payload).hexdigest()
    require(checksum.read_text() == digest + '  ' + archive.name + '\n', 'Package checksum mismatch.')
    contents, names = {}, set()
    allowed = {'cybermaps.php', 'uninstall.php', 'readme.txt', 'changelog.txt', 'LICENSE', 'assets', 'languages', 'src'}
    with zipfile.ZipFile(io.BytesIO(payload)) as source:
        require(len(source.infolist()) <= 10000 and sum(i.file_size for i in source.infolist()) <= 128 * 1024 * 1024,
                'Package exceeds file count or size limit.')
        for item in source.infolist():
            name = item.filename.rstrip('/')
            parts = name.split('/')
            require(parts[0] == 'cybermaps' and all(p and p not in ('.', '..') and not p.startswith('.') for p in parts)
                    and not re.search(r'[\\\x00-\x1f\x7f<>:"|?*]', name)
                    and name.casefold() not in names, 'Unsafe or duplicate ZIP entry: ' + name)
            names.add(name.casefold())
            mode = item.external_attr >> 16
            require((item.is_dir() and stat.S_ISDIR(mode)) or (not item.is_dir() and stat.S_ISREG(mode)),
                    'ZIP links and special files are forbidden: ' + name)
            require(len(parts) > 1 or item.is_dir(), 'Invalid plugin root.')
            if len(parts) > 1:
                require(parts[1] in allowed, 'Development files in package: ' + name)
            if not item.is_dir():
                contents['/'.join(parts[1:])] = source.read(item)
    require({'cybermaps.php', 'uninstall.php', 'readme.txt', 'changelog.txt', 'LICENSE'} <= contents.keys(),
            'Package is missing required plugin files.')
    return contents, digest


def verify_files(path, contents):
    no_source_tree(path)
    actual = {p.relative_to(path).as_posix(): p.read_bytes() for p in path.rglob('*') if p.is_file()}
    require(actual == contents, 'Installed files do not exactly match the validated package.')


def install(archive, destination, expected_sha256=None):
    """Swap a verified package; preserve the previous install without WP lifecycle calls."""
    destination = install_destination(destination)
    contents, digest = package_files(archive)
    require(expected_sha256 is None or digest == expected_sha256, 'Package changed after validation.')
    configure()
    backup = generated('backups/install-' + uuid.uuid4().hex)
    backup.mkdir(parents=True)
    previous = backup / 'cybermaps'
    # Preserve this location in advance, even if the process is interrupted during the swap.
    record = dict(destination=str(destination), archive=str(archive), sha256=digest,
                  backup=str(previous), phase='staging')
    save_json(backup / 'install.json', record)
    with tempfile.TemporaryDirectory(prefix='install-') as temporary:
        stage = Path(temporary) / 'cybermaps'
        stage.mkdir()
        for name, content in contents.items():
            target = stage / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content)
            target.chmod(0o644)
        for entry in [stage, *stage.rglob('*')]:
            if entry.is_dir():
                entry.chmod(0o755)
        verify_files(stage, contents)
        require(stage.stat().st_dev == destination.parent.stat().st_dev,
                'Atomic installation requires the workspace and Local Sites on the same filesystem.')
        install_destination(destination)
        existed = destination.exists()
        if existed:
            destination.rename(previous)
        try:
            stage.rename(destination)
            verify_files(destination, contents)
            record['phase'] = 'installed'
            record['files'] = len(contents)
            save_json(backup / 'install.json', record)
        except BaseException:
            if destination.exists():
                destination.rename(backup / 'failed-install')
            if existed:
                previous.rename(destination)
            record['phase'] = 'rolled-back'
            save_json(backup / 'install.json', record)
            raise
    print(f'Installed {len(contents)} verified files. Rollback: {backup}')
    return record


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['tmp', 'release-dir', 'check-generated', 'run'])
    parser.add_argument('arguments', nargs=argparse.REMAINDER)
    args = parser.parse_args()
    if args.action == 'check-generated':
        for value in args.arguments:
            path = safe_path(value)
            require(path.is_relative_to(generated()), 'Generated destination is outside docs/generated/.')
            if path.is_dir():
                no_source_tree(path)
    elif args.action == 'release-dir':
        print(release_dir())
    elif args.action == 'tmp':
        print(configure())
    else:
        configure()
        raise SystemExit(subprocess.call(args.arguments, cwd=ROOT))


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, ValueError) as error:
        raise SystemExit(str(error)) from error
