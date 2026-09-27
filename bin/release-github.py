#!/usr/bin/env python3
"""Local, resumable GitHub publisher. All remote writes follow local validation."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import io
import tarfile
import sys

sys.dont_write_bytecode = True
from workspace import (ROOT, clean_website, configure, load_config, package_files,
                       lock, release_dir, run, website_destination)

REPO = "Alex9001/cybermaps"
REMOTE = "https://github.com/" + REPO + ".git"


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def source_commit():
    require(not run("git", "status", "--porcelain", "--untracked-files=all"),
            "Uncommitted work: review, commit, and push it before releasing.")
    require(run("git", "branch", "--show-current") == "main", "Checkout main before releasing.")
    commit = run("git", "rev-parse", "HEAD")
    remote = run("git", "ls-remote", REMOTE, "refs/heads/main").split()
    require(remote and remote[0] == commit,
            "HEAD does not match pushed main in " + REPO + "; push or update your checkout.")
    return commit


def verify_tag(tag, commit):
    local = run("git", "tag", "--list", tag)
    if local:
        require(run("git", "rev-parse", tag + "^{commit}") == commit,
                "Local tag conflicts with verified commit: " + tag)
    refs = dict(line.split()[::-1] for line in
                run("git", "ls-remote", REMOTE, "refs/tags/" + tag,
                    "refs/tags/" + tag + "^{}").splitlines())
    remote_commit = refs.get("refs/tags/" + tag + "^{}", refs.get("refs/tags/" + tag))
    require(remote_commit is None or remote_commit == commit, "Remote tag conflict: " + tag)
    return bool(local), remote_commit is not None


def release_state(tag):
    # Listing through the authenticated API distinguishes absence from API failures.
    pages = json.loads(run("gh", "api", "--hostname", "github.com", "--paginate", "--slurp",
                           "repos/" + REPO + "/releases?per_page=100"))
    matches = [r for page in pages for r in page if r["tag_name"] == tag]
    require(len(matches) <= 1, "Multiple releases found for " + tag)
    return matches[0] if matches else None


def matching_draft(state, tag, commit, beta, notes, title):
    require(state is not None and state["draft"], "Published releases are never overwritten: " + tag)
    require(state["target_commitish"] == commit and state["prerelease"] == beta
            and state["body"] == notes and state["name"] == title,
            "Draft metadata differs from this release; inspect the draft before retrying.")


def asset_records(state):
    # Downloads change counters, not asset identity or bytes. Keep every other field.
    return [{key: value for key, value in asset.items() if key != 'download_count'}
            for asset in state['assets']]


def release_notes(version, beta, commit):
    history = Path("changelog.txt").read_text()
    match = re.search(r"^" + re.escape(version) + r"\n-+\n(.*?)(?=^\d+\.\d+\.\d+\n-+\n|\Z)",
                      history, re.M | re.S)
    require(match and match[1].strip(), "Missing matching changelog section.")
    readme = Path("readme.txt").read_text()
    requirements = []
    for field, label in [("Requires at least", "WordPress"), ("Requires PHP", "PHP")]:
        value = re.search(r"^" + field + r":\s*([^\n]+)", readme, re.M)
        require(value is not None, "Missing requirement: " + field)
        requirements.append(label + " " + value[1].strip() + "+")
    return (f"{'Open beta prerelease' if beta else 'Stable release'} — Cybermaps {version}\n\n"
            + ("This open beta is ready for use on live WordPress sites. Feedback and bug reports are welcome.\n\n" if beta else "")
            + match[1].strip() + "\n\nRequirements: " + "; ".join(requirements)
            + f".\n\nDownload `cybermaps_{version}.zip` below (not GitHub's source archives). "
            "In WordPress, open Plugins → Add New Plugin → Upload Plugin, select the ZIP, "
            "install, and activate Cybermaps. The accompanying `.sha256` file verifies the ZIP.\n\n"
            "Report issues: https://github.com/Alex9001/cybermaps/issues/new?template=bug_report.yml\n"
            "Include versions, reproduction steps, and the diagnostic support bundle from "
            "Cybermaps → Debugging → Copy GitHub support bundle (or Download support bundle). "
            "Review the JSON before pasting it into the issue.\n\n"
            f"Source commit: `{commit}`")


def publish(website, stable=False, on_validated=None, on_website_validated=None):
    """Validate exactly once, optionally install, then publish a matching draft."""
    os.chdir(ROOT)
    configure()
    website = website_destination(website)
    clean_website(website)
    os.environ["GH_HOST"] = "github.com"
    os.environ["GH_PROMPT_DISABLED"] = "1"
    run("gh", "auth", "status", "--hostname", "github.com", capture=False)
    commit = source_commit()
    version_match = re.search(r"^[ \t*]*Version:[ \t]*(\d+\.\d+\.\d+)[ \t]*$",
                              Path("cybermaps.php").read_text(), re.M)
    require(version_match is not None, "Cannot read plugin version.")
    version = version_match[1]
    tag, beta = "v" + version, not stable
    title = "Cybermaps " + version + (" — Open beta" if beta else "")
    notes = release_notes(version, beta, commit)
    verify_tag(tag, commit)
    state = release_state(tag)
    if state:
        matching_draft(state, tag, commit, beta, notes, title)
    run("python3", "bin/check-website.py", str(website), "--commit", commit,
        "--channel", "beta" if beta else "stable", capture=False)
    if on_website_validated:
        on_website_validated()
    for command in [("composer", "test"), ("composer", "run", "lint:complexity"),
                    ("composer", "run", "release:check"), ("composer", "run", "release:build")]:
        run(*command, capture=False)
    # release:build already validates directory/ZIP parity and runs Plugin Check.
    archive = release_dir(version) / ("cybermaps_" + version + ".zip")
    checksum = Path(str(archive) + ".sha256")
    expected = hashlib.sha256(archive.read_bytes()).hexdigest() + "  " + archive.name + "\n"
    require(checksum.read_text() == expected, "Local checksum does not match the ZIP.")
    artifacts = {p.name: p.read_bytes() for p in (archive, checksum)}
    require(source_commit() == commit, "Source changed during validation.")
    run("python3", "bin/check-website.py", str(website), "--commit", commit,
        "--channel", "beta" if beta else "stable", "--source-only", capture=False)
    clean_website(website)
    if on_validated:
        on_validated(archive, hashlib.sha256(artifacts[archive.name]).hexdigest())
    require(source_commit() == commit, "Source changed during installation.")
    local, remote = verify_tag(tag, commit)
    if not local:
        run("git", "tag", tag, commit)
    if not remote:
        run("git", "push", REMOTE, "refs/tags/" + tag, capture=False)
    with tempfile.TemporaryDirectory(prefix="cybermaps-release-") as temporary:
        temporary = Path(temporary)
        notes_file = temporary / "notes.md"
        notes_file.write_text(notes)
        if state is None:
            run("gh", "release", "create", tag, "--repo", REPO, "--verify-tag", "--target", commit,
                "--draft", "--prerelease=" + str(beta).lower(), "--title", title,
                "--notes-file", str(notes_file), capture=False)
        state = release_state(tag)
        matching_draft(state, tag, commit, beta, notes, title)
        names = [a["name"] for a in state["assets"]]
        require(len(names) == len(set(names)) and set(names) <= artifacts.keys(),
                "Draft has unexpected or duplicate assets.")
        for name, content in artifacts.items():
            if name not in names:
                # Upload the frozen bytes validated above, never replace an existing asset.
                upload = temporary / name
                upload.write_bytes(content)
                run("gh", "release", "upload", tag, str(upload), "--repo", REPO, capture=False)
        verified = release_state(tag)
        matching_draft(verified, tag, commit, beta, notes, title)
        for name, content in artifacts.items():
            download_dir = temporary / (name + ".download")
            download_dir.mkdir()
            run("gh", "release", "download", tag, "--repo", REPO, "--pattern", name,
                "--dir", str(download_dir), capture=False)
            require((download_dir / name).read_bytes() == content, "Downloaded asset mismatch: " + name)
        final = release_state(tag)
        matching_draft(final, tag, commit, beta, notes, title)
        require(final["id"] == state["id"] and asset_records(final) == asset_records(verified)
                and sorted(a["name"] for a in final["assets"]) == sorted(artifacts),
                "Draft assets changed during verification.")
        # Existing asset IDs must survive verification, including on resumed drafts.
        require(all(a in asset_records(final) for a in asset_records(state)), "Draft assets were replaced.")
        require(source_commit() == commit, "Source changed before publication.")
        require(verify_tag(tag, commit)[1], "Remote tag disappeared before publication.")
        run("python3", "bin/check-website.py", str(website), "--commit", commit,
            "--channel", "beta" if beta else "stable", "--source-only", capture=False)
        clean_website(website)
        run("gh", "release", "edit", tag, "--repo", REPO, "--draft=false",
            "--prerelease=" + str(beta).lower(), "--latest=" + str(not beta).lower(), capture=False)
    print("Published https://github.com/" + REPO + "/releases/tag/" + tag)
    return tag


def verify_published(tag):
    """Read-only remote verification for downstream retries; never upload/edit assets."""
    require(re.fullmatch(r'v\d+\.\d+\.\d+', tag), 'Invalid published release tag.')
    state = release_state(tag)
    require(state and not state['draft'] and state.get('published_at'), 'Release is not published: ' + tag)
    commit = json.loads(run('gh', 'api', '--hostname', 'github.com',
                            'repos/' + REPO + '/commits/' + tag))['sha']
    require(re.fullmatch(r'[0-9a-f]{40}', commit), 'Invalid published commit.')
    local, remote = verify_tag(tag, commit)
    require(remote, 'Published tag is missing from origin.')
    if not local:
        run('git', 'fetch', '--no-tags', REMOTE, 'refs/tags/' + tag + ':refs/tags/' + tag)
    require(run('git', 'rev-parse', tag + '^{commit}') == commit, 'Published tag mismatch.')
    name = 'cybermaps_' + tag[1:] + '.zip'
    require(sorted(a['name'] for a in state['assets']) == sorted([name, name + '.sha256']),
            'Published release must contain exactly the ZIP and checksum.')
    with tempfile.TemporaryDirectory(prefix='published-verification-') as temporary:
        for asset in (name, name + '.sha256'):
            run('gh', 'release', 'download', tag, '--repo', REPO, '--pattern', asset,
                '--dir', temporary)
        contents, digest = package_files(Path(temporary) / name)
        # Compare the downloaded package to its immutable Git source, not today's HEAD.
        source = subprocess.check_output(['git', 'archive', '--format=tar', commit], cwd=ROOT)
        expected = {}
        with tarfile.open(fileobj=io.BytesIO(source)) as tree:
            for entry in tree.getmembers():
                path = Path(entry.name)
                if not entry.isfile() or any(part.startswith('.') for part in path.parts):
                    continue
                if (entry.name in {'cybermaps.php', 'uninstall.php', 'readme.txt', 'changelog.txt', 'LICENSE'}
                        or (path.parts[0] in {'src', 'assets', 'languages'} and path.suffix != '.map')):
                    expected[entry.name] = tree.extractfile(entry).read()
        require(contents == expected, 'Published ZIP does not match the tagged source.')
        match = re.search(rb'Version:[ \t]*(\d+\.\d+\.\d+)', contents['cybermaps.php'])
        require(match and match[1].decode() == tag[1:], 'Published package version differs from tag.')
    final = release_state(tag)
    require(final and not final['draft'] and final['id'] == state['id']
            and asset_records(final) == asset_records(state) and final['prerelease'] == state['prerelease'],
            'Published release changed during verification.')
    require(verify_tag(tag, commit)[1], 'Published tag disappeared during verification.')
    return dict(tag=tag, commit=commit, channel='beta' if state['prerelease'] else 'stable',
                zip_sha256=digest, release_id=state['id'])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--stable', action='store_true')
    parser.add_argument('--website', default=os.environ.get('CYBERMAPS_WEBSITE_DIR'),
                        help='Must match the configured canonical website')
    args = parser.parse_args()
    website, _ = load_config()
    require(not args.website or Path(args.website) == website, '--website must match .cybermaps-workspace.json.')
    with lock():
        publish(website, args.stable)


if __name__ == "__main__":
    try:
        main()
    except (RuntimeError, subprocess.CalledProcessError, OSError, ValueError, KeyError) as error:
        raise SystemExit("Release failed: " + str(error)
                         + "\nNothing is overwritten. Any created tag/draft is retained; rerun to resume a matching draft.")
