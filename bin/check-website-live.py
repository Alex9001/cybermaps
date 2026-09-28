#!/usr/bin/env python3
"""Verify production content after the guarded website deployment."""
import argparse
from pathlib import Path
from website_evidence import verify_live
from workspace import configure, website_destination

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('website', type=Path)
    for name in ('tag', 'commit', 'channel', 'zip-sha256'):
        parser.add_argument('--' + name, required=True)
    args = parser.parse_args()
    configure()
    verify_live(website_destination(args.website), dict(tag=args.tag, commit=args.commit,
                channel=args.channel, zip_sha256=args.zip_sha256))
    print('Production website verified at https://cybermaps.dev for ' + args.tag)
