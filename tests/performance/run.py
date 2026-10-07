#!/usr/bin/env python3
"""Resource-bounded exact-package performance evidence; no production access."""
from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import platform
import shutil
import subprocess
import time
import urllib.error
import urllib.request
import uuid
import zipfile
import threading
import xml.etree.ElementTree as ET
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[2]
TEMP = ROOT / 'docs/generated/tmp'
REPORTS = ROOT / 'docs/generated/releases/8.0.2/audit/performance'
IMAGE = 'docker.io/library/wordpress:cli-php8.2'
DB_IMAGE = 'docker.io/library/mariadb:10.11'
MYSQL_IMAGE = 'docker.io/library/mysql:8.0'
REDIS_IMAGE = 'docker.io/library/redis:7-alpine'
BASELINE_SHA = '5eb0b49877b3b4ab1d92642133f96427d3e4def607c89ca25592f1732483ba8e'


def write_json(path, value):
    path = Path(path)
    temporary = path.with_suffix(path.suffix + '.tmp')
    temporary.write_text(json.dumps(value, indent=2) + '\n')
    temporary.replace(path)


def validate_http(operation, status, body):
    if status != 200 or not body:
        return 'unexpected_status_or_empty_body'
    try:
        if operation.startswith('sitemap'):
            root = ET.fromstring(body)
            expected = 'sitemapindex' if operation == 'sitemap_index' else 'urlset'
            if root.tag.split('}')[-1] != expected or not list(root):
                return 'invalid_or_empty_sitemap'
        elif operation.startswith('search'):
            data = json.loads(body)
            results = data['results']
            if not isinstance(results, list) or data['total'] != len(results):
                return 'invalid_search_schema'
            if bool(results) != (operation == 'search_hit'):
                return 'unexpected_search_results'
        elif operation == 'rag':
            data = json.loads(body)
            if not isinstance(data, dict) or not isinstance(data.get('post_id'), int) or not data.get('chunks'):
                return 'invalid_rag_schema'
        elif b'<!doctype html' in body.lower() or not body.lstrip().startswith(b'#'):
            return 'unexpected_discovery_body'
    except (ValueError, KeyError, TypeError, ET.ParseError):
        return 'invalid_response_body'
    return None


def finite_metric(value, integer=False, positive=False):
    return (not isinstance(value, bool) and isinstance(value, int if integer else (int, float))
            and math.isfinite(value) and (value > 0 if positive else value >= 0))


def valid_sample(sample):
    if not isinstance(sample, dict) or sample.get('error') or sample.get('fatal') or sample.get('skipped'):
        return False
    for key in ('queries', 'peak_memory_bytes'):
        if not finite_metric(sample.get(key), integer=True, positive=key == 'peak_memory_bytes'):
            return False
    for key in ('query_seconds', 'php_seconds'):
        if not finite_metric(sample.get(key)):
            return False
    if any(not isinstance(sample.get(key), str) or not sample[key] for key in ('plugin', 'wordpress', 'php')):
        return False
    if type(sample.get('exit_code', 0)) is not int or sample.get('exit_code', 0) != 0:
        return False
    if 'state' in sample:
        return (type(sample.get('status')) is int and sample['status'] == 200
                and finite_metric(sample.get('bytes'), integer=True, positive=True)
                and finite_metric(sample.get('seconds'), positive=True) and sample.get('body_valid') is True)
    result = sample.get('result')
    if not isinstance(result, dict) or not result or not finite_metric(sample.get('operation_seconds')):
        return False
    operation = sample.get('operation')
    if operation not in ('audit', 'links', 'audit_batch', 'queue', 'static_sync', 'ownership'):
        return False
    if operation in ('audit', 'links'):
        analysis = result.get('analysis')
        if not isinstance(analysis, dict) or analysis.get('complete') is not True:
            return False
    if operation == 'audit' and result.get('status') != 'complete':
        return False
    if operation == 'audit_batch' and not finite_metric(result.get('count'), integer=True, positive=True):
        return False
    if operation == 'ownership' and not finite_metric(result.get('written'), integer=True, positive=True):
        return False
    if operation == 'queue':
        enqueue = result.get('enqueue')
        if (not isinstance(enqueue, dict) or type(enqueue.get('accepted')) is not int or enqueue['accepted'] != 1000
                or type(result.get('claimed')) is not int or result['claimed'] != 1000
                or type(result.get('acknowledged')) is not int or result['acknowledged'] != 1000):
            return False
    if operation == 'static_sync':
        report = result.get('report')
        if not isinstance(report, dict) or report.get('status') != 'complete' or report.get('success') is not True:
            return False
        # StaticBridge deletes its continuation checkpoint only at terminal
        # completion. A successful slice may still report status=complete.
        if 'state' not in result or result['state'] not in ([], {}):
            return False
    return True


def resident_bytes(status):
    for line in status.splitlines():
        if line.startswith('VmRSS:'):
            parts = line.split()
            if len(parts) != 3 or not parts[1].isdigit() or parts[2] != 'kB':
                raise ValueError('Invalid process RSS metric')
            return int(parts[1]) * 1024
    if any(line.startswith('State:') and line.split()[1] in ('Z', 'X') for line in status.splitlines()):
        return 0  # Exited process awaiting reaping; no resident address space remains.
    raise ValueError('Live process RSS metric unavailable')


def skipped_sample(identifier, state, warmup):
    return {'id': identifier, 'state': state, 'warmup': warmup, 'skipped': True,
            'error': 'skipped_after_three_invalid_responses', 'valid': False}


def discover_routes(inventory):
    entries = [e for e in inventory['entries'] if e['provider_name'] == 'post' and e['provider_kind'] == 'posts']
    # Some releases name the kind post_type; identify the advertised provider itself.
    if not entries:
        entries = [e for e in inventory['entries'] if e['provider_name'] == 'post']
    if not entries:
        entries = [e for e in inventory['entries'] if e['provider_name'] == 'page']
    if not entries:
        raise ValueError('No advertised post or page sitemap entries')
    entries.sort(key=lambda e: e['page'])
    routes = {'sitemap_index': '/sitemap.xml', 'sitemap_early': urlsplit(entries[0]['loc']).path,
              'sitemap_deep': urlsplit(entries[-1]['loc']).path, 'rag': f"/discovery/chunks/{inventory['rag_id']}.json"}
    endpoints = inventory['endpoints']
    for name in ('llms', 'llms_full', 'llms_tldr'):
        routes[name] = endpoints[name]['path']
    search = endpoints['rest_search']
    path = '/wp-json/' + search['namespace'] + search['route']
    routes.update(search_hit=path + '?q=perfmarker&limit=20', search_miss=path + '?q=nomatchingfixturetoken&limit=20')
    return routes


class Runtime:
    def __init__(self, args):
        self.args = args
        self.identifier = 'cybermaps-perf-' + uuid.uuid4().hex[:10]
        self.work = TEMP / self.identifier
        self.web = self.work / 'wordpress'
        self.output = REPORTS / (args.label + '-' + time.strftime('%Y%m%dT%H%M%SZ', time.gmtime()))
        self.output.mkdir(parents=True)
        self.web.mkdir(parents=True)
        (self.work / 'db').mkdir()
        (self.work / 'tmp').mkdir()
        shutil.copytree(ROOT / 'tests/performance', self.work / 'performance', ignore=shutil.ignore_patterns('__pycache__'))
        self.env = dict(os.environ, TMPDIR=str(self.work / 'tmp'), TMP=str(self.work / 'tmp'), TEMP=str(self.work / 'tmp'))
        self.log = (self.output / 'commands.log').open('a')
        self.cleaning_up = False
        self.watch_error = None
        self.watch_thread = None
        self.started_containers = set()
        self.cpus = ','.join(map(str, sorted(os.sched_getaffinity(0))[:6]))
        self.command('cc', '-static', '-O2', '-Wall', '-Wextra', '-Werror', str(self.work / 'performance/limits.c'), '-o', str(self.work / 'performance/limits'))
        self.db = self.identifier + '-db'
        self.http = self.identifier + '-http'
        self.watch_stop = threading.Event()
        self.storage_exceeded = False
        self.resource_stop = None
        self.peak_container_rss = 0
        self.resource_samples = 0
        self.rss_probe_retries = []
        self.base = ''
        self.redis = self.identifier + '-redis'
        self.db_image = MYSQL_IMAGE if args.database == 'mysql' else DB_IMAGE
        self.db_client = 'mysql' if args.database == 'mysql' else 'mariadb'

    def command(self, *args, timeout=300, input=None, check=True):
        self.check_watchdog()
        if args[:2] == ('podman', 'restart'):
            self.started_containers.discard(args[-1])
        self.log.write(json.dumps(list(args)) + '\n')
        self.log.flush()
        result = subprocess.run(args, cwd=ROOT, env=self.env, input=input, text=True, capture_output=True, timeout=timeout)
        if result.returncode == 0 and args[:2] == ('podman', 'run') and '--name' in args:
            self.started_containers.add(args[args.index('--name') + 1])
        if result.returncode == 0 and args[:2] == ('podman', 'restart'):
            self.started_containers.add(args[-1])
        self.check_watchdog()
        if result.stderr:
            self.log.write(result.stderr + '\n')
            self.log.flush()
        if check and result.returncode:
            raise RuntimeError(f'Command failed ({result.returncode}): {args[0:4]}\n{result.stderr}\n{result.stdout[:2000]}')
        return result

    def bounded(self, memory):
        return ['/performance/limits', self.cpus, str(memory)]

    def freeze_package(self, expected_digest):
        self.archive = self.work / 'package' / self.args.zip.name
        self.archive.parent.mkdir()
        shutil.copyfile(self.args.zip, self.archive)
        self.zip_sha256 = expected_digest
        self.verify_package()
        self.archive.chmod(0o444)

    def verify_package(self):
        if hashlib.sha256(self.archive.read_bytes()).hexdigest() != self.zip_sha256:
            raise RuntimeError('Frozen performance package changed; exact artifact evidence invalid')

    def wp(self, *args, timeout=300, check=True):
        return self.command('podman', 'exec', '-i', '-w', '/var/www/html', self.http,
                            *self.bounded(1024), 'php', '-d', 'memory_limit=1024M', '/cli-tools/vendor/wp-cli/wp-cli/php/boot-fs.php',
                            *args, '--allow-root', timeout=timeout, check=check)

    def operation(self, name, *args, timeout=300, check=True):
        return self.command('podman', 'exec', '-i', '-w', '/var/www/html', self.http,
                            *self.bounded(1024), 'timeout', str(timeout), 'php', '-d', 'memory_limit=1024M',
                            '/performance/runner.php', name, *args, timeout=timeout + 10, check=check)

    def sql(self, sql, timeout=600):
        return self.command('podman', 'exec', '-i', self.db, *self.bounded(256), self.db_client, '-uroot', '-pperf', '--batch', '--raw', '--skip-column-names', 'wordpress', input=sql, timeout=timeout).stdout

    def setup(self):
        self.pin_images()
        self.watch_thread = threading.Thread(target=self.watch_storage, daemon=True)
        self.watch_thread.start()
        self.command('podman', 'network', 'create', self.identifier)
        self.command('podman', 'run', '-d', '--name', self.db, '--network', self.identifier,
                     '--cpus=3', '--memory=7g', '--memory-swap=7g', '--pids-limit=256', '--entrypoint', '/performance/limits',
                     '-v', f'{self.work}/performance:/performance:ro',
                     '-e', 'MYSQL_ROOT_PASSWORD=perf', '-e', 'MYSQL_DATABASE=wordpress',
                     '-e', 'MARIADB_ROOT_PASSWORD=perf', '-e', 'MARIADB_DATABASE=wordpress',
                     '-v', f'{self.work}/db:/var/lib/mysql', self.db_image, self.cpus, '6144', 'docker-entrypoint.sh',
                     'mysqld' if self.args.database == 'mysql' else 'mariadbd', '--innodb-buffer-pool-size=2G', '--max-connections=40', '--performance-schema=ON')
        for _ in range(90):
            ready = self.command('podman', 'exec', self.db, *self.bounded(256), 'mysqladmin' if self.args.database == 'mysql' else 'mariadb-admin', '--protocol=tcp', '-h127.0.0.1', 'ping', '-uroot', '-pperf', '--silent', check=False)
            if ready.returncode == 0:
                break
            time.sleep(1)
        else:
            raise RuntimeError('Database readiness timed out')
        self.command('podman', 'run', '-d', '--name', self.http, '--network', self.identifier, '--user', '0:0',
                     '--cpus=2.5', '--memory=4g', '--memory-swap=4g', '--pids-limit=256',
                     '--entrypoint', '/performance/limits',
                     '-p', '127.0.0.1::8080', '-v', f'{self.web}:/var/www/html',
                     '-v', f'{ROOT}/docs/generated/tmp/wp-cli-modern:/cli-tools:ro',
                     '-v', f'{self.work}/performance:/performance:ro', '-v', f'{self.output}:/evidence',
                     '-v', f'{self.archive.parent}:/artifacts:ro', '-v', f'{self.work}/tmp:/task-tmp',
                     '-e', 'TMPDIR=/task-tmp', '-e', 'TMP=/task-tmp', '-e', 'TEMP=/task-tmp',
                     '-w', '/var/www/html', self.php_image, self.cpus, '1024', 'php', '-d', 'memory_limit=1024M', '-d', 'max_execution_time=120',
                     '-S', '0.0.0.0:8080', '/performance/router.php')
        self.base = 'http://' + self.command('podman', 'port', self.http, '8080/tcp').stdout.strip()
        archive = self.work / 'wordpress-7.1.zip'
        urllib.request.urlretrieve('https://wordpress.org/wordpress-7.1.zip', archive)
        with zipfile.ZipFile(archive) as source:
            for member in source.infolist():
                relative = Path(member.filename).relative_to('wordpress')
                if '..' in relative.parts:
                    raise ValueError('Unsafe WordPress archive path')
                target = self.web / relative
                if member.is_dir():
                    target.mkdir(parents=True, exist_ok=True)
                else:
                    target.parent.mkdir(parents=True, exist_ok=True)
                    with source.open(member) as input_file, target.open('wb') as output_file:
                        shutil.copyfileobj(input_file, output_file)
        self.wp('config', 'create', '--dbname=wordpress', '--dbuser=root', '--dbpass=perf', f'--dbhost={self.db}', '--skip-check', '--quiet')
        for key, value in [('WP_DEBUG', 'true'), ('WP_DEBUG_DISPLAY', 'false'), ('WP_DEBUG_LOG', 'true'), ('SAVEQUERIES', 'true'), ('DISABLE_WP_CRON', 'true')]:
            self.wp('config', 'set', key, value, '--raw', '--quiet')
        self.wp('core', 'install', f'--url={self.base}', '--title=Cybermaps Performance Fixture', '--admin_user=perf-admin',
                '--admin_password=disposable-performance-only', '--admin_email=perf@example.test', '--skip-email', '--quiet')
        self.command('podman', 'run', '-d', '--name', self.redis, '--network', self.identifier,
                     '--cpus=0.5', '--memory=1g', '--memory-swap=1g', '--pids-limit=64',
                     '--entrypoint', '/performance/limits', '-v', f'{self.work}/performance:/performance:ro',
                     self.redis_image, self.cpus, '1024', 'docker-entrypoint.sh', 'redis-server', '--maxmemory', '768mb', '--maxmemory-policy', 'allkeys-lru', '--save', '', '--appendonly', 'no')
        self.wp('plugin', 'install', 'redis-cache', '--version=2.7.0', '--quiet')
        self.wp('config', 'set', 'WP_REDIS_HOST', self.redis, '--quiet')
        self.wp('config', 'set', 'WP_REDIS_CLIENT', 'predis', '--quiet')
        self.verify_package()
        self.wp('plugin', 'install', '/artifacts/' + self.archive.name, '--activate', '--force', '--quiet')
        self.verify_package()
        mu = self.web / 'wp-content/mu-plugins'
        mu.mkdir(exist_ok=True)
        shutil.copyfile(self.work / 'performance/instrument.php', mu / 'performance.php')
        self.operation('configure', '1')
        self.verify_limits()
        self.wp('core', 'verify-checksums')
        write_json(self.output / 'environment.json', {
            'zip': str(self.args.zip), 'staged_zip': str(self.archive), 'zip_sha256': self.zip_sha256,
            'harness_sha256': {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in (self.work / 'performance').iterdir() if p.is_file()},
            'label': self.args.label, 'args': {key: str(value) if isinstance(value, Path) else value for key, value in vars(self.args).items()},
            'hardware': platform.uname()._asdict(), 'cpu_count_host': os.cpu_count(),
            'limits': {'db_cpus': 3, 'php_cpus': 2.5, 'redis_cpus': 0.5, 'db_memory_gib': 7, 'php_memory_gib': 4, 'redis_memory_gib': 1, 'php_request_memory_mib': 1024, 'storage_stop_gib': 90, 'verified_cpu_affinity': self.cpus, 'db_address_space_gib': 6, 'redis_address_space_gib': 1, 'php_process_address_space_gib': 1, 'max_simultaneous_php_processes': 3, 'sql_helper_address_space_mib': 256, 'accounting': 'Affinity and per-process RLIMIT_AS verified through procfs; cgroup flags are requested but may not be enforced by nested Podman. Named process-limit subtotal: DB6+Redis1+PHP3+SQLhelpers0.5=10.5GiB. Small timeout supervisors additionally inherit CLI caps, so this is not an absolute aggregate-AS bound. Engine/controller overhead outside container budget; sampled combined-RSS watchdog stops above12GiB, not a cgroup guarantee.'},
            'images': self.image_records, 'image_roles': self.image_roles,
            'versions': self.wp('eval', 'echo json_encode(["php"=>PHP_VERSION,"wp"=>get_bloginfo("version"),"plugin"=>CYBERMAPS_VERSION]);').stdout,
            'cold_definition': 'Application caches cleared; MariaDB buffer pool and OS caches not flushed.',
            'cache_definition': 'Plugin enable_caching on/off crossed independently with Redis Object Cache 2.7.0 drop-in on/off; independent discovery caches retain native behavior.',
            'rate_limit_fixture': 'Each measured HTTP request receives a distinct synthetic documentation-range client address in the disposable MU plugin to measure work rather than throttle responses.',
        })

    def verify_limits(self):
        evidence = {}
        for container, memory in ((self.db, 6144), (self.http, 1024), (self.redis, 1024)):
            status = self.command('podman', 'exec', container, *self.bounded(256), 'cat', '/proc/1/status').stdout
            limits = self.command('podman', 'exec', container, *self.bounded(256), 'cat', '/proc/1/limits').stdout
            allowed = next(line.split(':', 1)[1].strip() for line in status.splitlines() if line.startswith('Cpus_allowed_list:'))
            actual = set()
            for part in allowed.split(','):
                if '-' in part:
                    low, high = map(int, part.split('-'))
                    actual.update(range(low, high + 1))
                else:
                    actual.add(int(part))
            memory_line = next(line for line in limits.splitlines() if line.startswith('Max address space'))
            memory_values = memory_line.split()[3:5]
            if actual != set(map(int, self.cpus.split(','))) or memory_values != [str(memory * 1024**2)] * 2:
                raise RuntimeError('Runtime limits did not match actual process state: ' + container)
            evidence[container] = {'status': status, 'limits': limits, 'address_space_mib': memory}
        # CLI exec processes are separately launched; prove their own inherited limits.
        probe = self.operation('limits_probe')
        evidence['cli'] = json.loads(probe.stdout)
        cli_status = evidence['cli']['status']
        cli_allowed = next(line.split(':', 1)[1].strip() for line in cli_status.splitlines() if line.startswith('Cpus_allowed_list:'))
        expected_ranges = []
        for part in cli_allowed.split(','):
            if '-' in part:
                first, last = map(int, part.split('-'))
                expected_ranges.extend(range(first, last + 1))
            else:
                expected_ranges.append(int(part))
        if evidence['cli']['address_space_bytes'] != 1024**3 or set(expected_ranges) != set(map(int, self.cpus.split(','))):
            raise RuntimeError('CLI process address-space limit not enforced')
        write_json(self.output / 'verified-process-limits.json', evidence)

    def pin_images(self):
        names = {'php': IMAGE, 'db': self.db_image, 'redis': REDIS_IMAGE}
        manifest_path = getattr(self.args, 'image_manifest', None)
        manifest = json.loads(Path(manifest_path).read_text()) if manifest_path else None
        if manifest and manifest.get('args', {}).get('database', 'mariadb') != self.args.database:
            raise ValueError('Image manifest database mode differs from requested runtime')
        self.image_records = []
        self.image_roles = {}
        for role, tag in names.items():
            reference = tag
            if manifest:
                reference = manifest.get('image_roles', {}).get(role)
                if not reference:
                    matches = [record['Id'] for record in manifest['images'] if tag in record.get('RepoTags', [])]
                    if len(matches) != 1:
                        raise ValueError('Image manifest cannot uniquely resolve ' + tag)
                    reference = matches[0]
            record = json.loads(self.command('podman', 'image', 'inspect', reference).stdout)[0]
            if manifest and record['Id'].removeprefix('sha256:') != reference.removeprefix('sha256:'):
                raise ValueError('Pinned image ID mismatch')
            self.image_records.append(record)
            self.image_roles[role] = record['Id']
        self.php_image, self.db_image, self.redis_image = [self.image_roles[role] for role in ('php', 'db', 'redis')]

    def check_watchdog(self):
        if getattr(self, 'cleaning_up', False):
            return
        if getattr(self, 'watch_error', None):
            raise RuntimeError('Resource watchdog failed: ' + self.watch_error)
        if getattr(self, 'resource_stop', None) or getattr(self, 'storage_exceeded', False):
            raise RuntimeError('Resource stop limit reached')
        thread = getattr(self, 'watch_thread', None)
        if thread is not None and not thread.is_alive() and not self.watch_stop.is_set():
            raise RuntimeError('Resource watchdog exited unexpectedly')

    def stop_owned_containers(self):
        subprocess.run(['podman', 'stop', '-t', '0', self.http, self.db, self.redis],
                       env=self.env, capture_output=True, timeout=30)

    def watch_storage(self):
        try:
            self.watch_storage_loop()
        except Exception as error:
            self.watch_error = type(error).__name__ + ': ' + str(error)
            try:
                self.stop_owned_containers()
            except Exception as stop_error:
                self.watch_error += '; stop failed: ' + str(stop_error)

    def workspace_bytes(self):
        result = subprocess.run(['podman', 'unshare', 'du', '-sb', str(self.work)], env=self.env,
                                capture_output=True, text=True, timeout=30)
        fields = result.stdout.split()
        if result.returncode or not fields or not fields[0].isdigit():
            raise RuntimeError('Workspace size probe failed or returned invalid output')
        return int(fields[0])

    def container_rss(self, container):
        for attempt in range(1, 4):
            if self.watch_stop.is_set() or container not in self.started_containers:
                return 0
            result = subprocess.run(['podman', 'exec', container, *self.bounded(256), '--rss-probe'],
                                    env=self.env, capture_output=True, text=True, timeout=15)
            try:
                data = json.loads(result.stdout)
            except ValueError:
                data = None
            if (result.returncode == 0 and isinstance(data, dict) and type(data.get('rss_bytes')) is int
                    and data['rss_bytes'] > 0 and type(data.get('processes')) is int and data['processes'] > 0):
                return data['rss_bytes']
            failure = {'at_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'container': container,
                       'attempt': attempt, 'exit_code': result.returncode, 'stdout': result.stdout, 'stderr': result.stderr}
            self.rss_probe_retries.append(failure)
            write_json(self.output / 'rss-probe-retries.json', self.rss_probe_retries)
            if attempt < 3:
                time.sleep(.02)
        raise RuntimeError('No complete positive owned PID namespace RSS observation: ' + repr(failure))

    def watch_storage_loop(self):
        while not self.watch_stop.wait(5):
            rss = 0
            for container in tuple(self.started_containers):
                if self.watch_stop.is_set():
                    return
                rss += self.container_rss(container)
            self.peak_container_rss = max(self.peak_container_rss, rss)
            self.resource_samples += 1
            if rss > 12 * 1024**3:
                self.resource_stop = 'combined_container_rss_12gib'
                self.stop_owned_containers()
                return
            if self.workspace_bytes() > 90 * 1024**3:
                self.storage_exceeded = True
                self.stop_owned_containers()
                return

    def storage_check(self):
        self.check_watchdog()
        size = self.workspace_bytes()
        if size > 90 * 1024**3:
            raise RuntimeError('90 GiB disposable storage stop limit reached')
        return size

    def reset_audit_fixture(self, stem):
        # BusyBox timeout briefly outlives its completed PHP child. Wait for the
        # complete bounded exec to exit instead of racing lease reset against it.
        for attempt in range(51):
            processes = self.command('podman', 'top', self.http, 'pid', 'state', 'args').stdout
            try:
                self.verify_worker_exit(processes)
                break
            except RuntimeError:
                if attempt == 50:
                    raise
                time.sleep(.1)
        result = self.operation('reset_audit_lease')
        write_json(self.output / (stem + '-setup.json'), {
            'at_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'container': self.http,
            'reason': 'Independent cache-mode audit after verified prior worker exit; disposable fixture only.',
            'verified_processes': processes, 'reset': json.loads(result.stdout)})

    @staticmethod
    def verify_worker_exit(processes):
        if len(processes.splitlines()) < 2 or '/performance/router.php' not in processes:
            raise RuntimeError('Cannot verify fixture worker exit from process inventory')
        for line in processes.splitlines()[1:]:
            fields = line.split(None, 2)
            if len(fields) != 3 or not fields[0].isdigit():
                raise RuntimeError('Invalid fixture process inventory: ' + repr(processes))
            if fields[1] in ('Z', 'X'):
                continue  # Exited worker awaiting container-init reaping.
            if ('php' in fields[2].lower() and '/performance/router.php' not in fields[2]) or '/performance/runner.php' in fields[2]:
                raise RuntimeError('Refusing fixture lease reset while a prior CLI worker remains: ' + repr(processes))

    def persistent_cache(self, mode):
        dropin = self.web / 'wp-content/object-cache.php'
        if mode == 'on':
            shutil.copyfile(self.web / 'wp-content/plugins/redis-cache/includes/object-cache.php', dropin)
        elif dropin.exists():
            dropin.unlink()
        self.operation('clear')
        self.operation('cache_probe')
        probe = json.loads(self.operation('cache_probe').stdout)
        if probe['external'] != (mode == 'on') or (mode == 'on' and probe['value'] != 'persisted'):
            raise RuntimeError('Persistent cache mode did not match verified cross-process probe: ' + repr(probe))

    def lock_contention(self, queue=False):
        if queue:
            prepared = self.operation('queue_prepare', check=False)
            (self.output / 'queue-prepare.stdout').write_text(prepared.stdout)
        operation = 'queue_worker' if queue else 'lock'
        command = ['podman', 'exec', '-i', '-w', '/var/www/html', self.http, *self.bounded(1024), 'timeout', '30', 'php',
                   '-d', 'memory_limit=1024M', '/performance/runner.php', operation, '3000']
        holder = subprocess.Popen(command, cwd=ROOT, env=self.env, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        try:
            ready = holder.stdout.readline().strip()
            contender = self.operation(operation, '0', timeout=15, check=False)
            output, errors = holder.communicate(timeout=15)
            holder_data = json.loads(output.strip().splitlines()[-1]) if output.strip() else {}
            contender_data = json.loads(contender.stdout.strip().splitlines()[-1])
            successor = self.operation(operation, '0', timeout=15, check=False)
            successor_data = json.loads(successor.stdout.strip().splitlines()[-1])
            passed = (ready == 'LOCK_ACQUIRED' and holder.returncode == 0 and contender.returncode == 0
                      and successor.returncode == 0 and holder_data.get('result', {}).get('maintained') is True
                      and contender_data.get('result', {}).get('acquired') is False
                      and successor_data.get('result', {}).get('acquired') is True)
            if queue:
                passed = (ready == 'LOCK_ACQUIRED' and holder.returncode == 0 and contender.returncode == 0
                          and holder_data.get('result', {}).get('claimed') == 1
                          and holder_data.get('result', {}).get('acknowledged') == 1
                          and contender_data.get('result', {}).get('claimed') == 0)
            evidence = {'passed': passed, 'holder': holder_data, 'contender': contender_data, 'successor': successor_data, 'stderr': errors}
        finally:
            if holder.poll() is None:
                holder.kill()
                holder.communicate()
        write_json(self.output / ('queue-contention.json' if queue else 'lock-contention.json'), evidence)

    def all_valid(self):
        lock = json.loads((self.output / 'lock-contention.json').read_text())
        files = [p for p in self.output.glob('*.json') if p.name[0].isdigit() and not p.name.endswith(('-plans.json', '-setup.json'))]
        queue = json.loads((self.output / 'queue-contention.json').read_text())
        self.check_watchdog()
        return not self.storage_exceeded and self.resource_stop is None and self.watch_error is None and lock['passed'] and queue['passed'] and (bool(files) or self.args.lock_only) and all(
            samples and all(s.get('valid') for s in samples)
            for samples in [json.loads(p.read_text()) for p in files])

    def seed(self, count, page_lists=False):
        # Only the named disposable database is reachable through this method.
        n = int(count)
        if not 1 <= n <= 1000000:
            raise ValueError('Fixture size outside 1..1,000,000')
        t = 'page' if page_lists else "CASE WHEN MOD(seq,10)<7 THEN 'post' WHEN MOD(seq,10)<9 THEN 'page' ELSE 'perf_resource' END"
        type_sql = "'page'" if page_lists else t
        status = "'publish'" if page_lists else "CASE WHEN MOD(seq,20)<14 THEN 'publish' WHEN MOD(seq,20)<17 THEN 'draft' WHEN MOD(seq,20)<19 THEN 'private' ELSE 'future' END"
        content = "'<!-- wp:page-list /--><p>Page list fixture.</p>'" if page_lists else "CONCAT('<!-- wp:paragraph --><p>Deterministic cybermaps perfmarker resource ',seq,'. ',REPEAT('Stored content for publication measurement. ',12),' العربية 日本語 Español.</p><!-- /wp:paragraph --><p><a href=\"/?p=',GREATEST(101,100+seq-1),'\">Previous resource</a></p>',IF(MOD(seq,5)=0,'<!-- wp:image --><figure><img src=\"https://example.test/fixture.jpg\" alt=\"Synthetic image\"></figure><!-- /wp:image -->',''))"
        statements = [
            'SET SESSION sql_mode="";',
            'TRUNCATE wp_posts;', 'TRUNCATE wp_postmeta;', 'TRUNCATE wp_term_relationships;', 'TRUNCATE wp_term_taxonomy;', 'TRUNCATE wp_terms;',
            "DELETE FROM wp_users WHERE ID > 1;", "DELETE FROM wp_usermeta WHERE user_id > 1;",
            "INSERT INTO wp_users (ID,user_login,user_pass,user_nicename,user_email,user_url,user_registered,user_activation_key,display_name) SELECT seq+1,CONCAT('author-',seq),'unusable',CONCAT('author-',seq),CONCAT('author-',seq,'@example.test'),'','2025-01-01 00:00:00','',CONCAT('Author ',seq) FROM seq_1_to_99;",
            "INSERT INTO wp_usermeta (user_id,meta_key,meta_value) SELECT ID,'wp_capabilities','a:1:{s:6:\"author\";b:1;}' FROM wp_users WHERE ID>1;",
            "INSERT INTO wp_terms (term_id,name,slug,term_group) SELECT seq,CONCAT('Term ',seq),CONCAT('term-',seq),0 FROM seq_1_to_1100;",
            "INSERT INTO wp_term_taxonomy (term_taxonomy_id,term_id,taxonomy,description,parent,count) SELECT seq,seq,IF(seq<=100,'category','post_tag'),'',0,0 FROM seq_1_to_1100;",
            f"INSERT INTO wp_posts (ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) SELECT 100+seq,1+MOD(seq,100),DATE_SUB('2026-01-01 00:00:00',INTERVAL MOD(seq,3650) DAY),DATE_SUB('2026-01-01 00:00:00',INTERVAL MOD(seq,3650) DAY),{content},CONCAT('Perfmarker resource ',seq,' topic ',MOD(seq,97)),CONCAT('Literal summary ',seq),{status},'closed','closed',IF(MOD(seq,101)=0,'private-fixture',''),CONCAT('fixture-',seq),'','',DATE_SUB('2026-01-01 00:00:00',INTERVAL MOD(seq,730) DAY),DATE_SUB('2026-01-01 00:00:00',INTERVAL MOD(seq,730) DAY),'',0,CONCAT('http://example.test/?p=',100+seq),0,{type_sql},'',0 FROM seq_1_to_{n};",
            "INSERT INTO wp_postmeta (post_id,meta_key,meta_value) SELECT ID,'_cybermaps_ai_snippet',CONCAT('Synthetic AI excerpt ',ID) FROM wp_posts;",
            "INSERT INTO wp_postmeta (post_id,meta_key,meta_value) SELECT ID,'_yoast_wpseo_meta-robots-noindex','1' FROM wp_posts WHERE MOD(ID,29)=0;",
            "INSERT INTO wp_postmeta (post_id,meta_key,meta_value) SELECT ID,'_cybermaps_exclude_ai','1' FROM wp_posts WHERE MOD(ID,31)=0;",
            "INSERT INTO wp_postmeta (post_id,meta_key,meta_value) SELECT ID,'_cybermaps_exclude_sitemap','1' FROM wp_posts WHERE MOD(ID,37)=0;",
            "INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) SELECT ID,1+MOD(ID,100),0 FROM wp_posts WHERE post_type='post';",
            "INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) SELECT ID,101+MOD(ID,1000),0 FROM wp_posts WHERE post_type='post';",
            "UPDATE wp_term_taxonomy tt SET count=(SELECT COUNT(*) FROM wp_term_relationships tr INNER JOIN wp_posts p ON p.ID=tr.object_id WHERE tr.term_taxonomy_id=tt.term_taxonomy_id AND p.post_status='publish');",
        ]
        if not page_lists:
            for fraction, post_type, status_name, offset in [(10, 'attachment', 'inherit', n + 100), (20, 'revision', 'inherit', n + n // 10 + 100)]:
                amount = max(1, n // fraction)
                statements.append(f"INSERT INTO wp_posts (ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) SELECT {offset}+seq,1,'2025-01-01','2025-01-01','','Auxiliary fixture','','{status_name}','closed','closed','',CONCAT('{post_type}-',seq),'','','2025-01-01','2025-01-01','',100+seq*{fraction},CONCAT('http://example.test/{post_type}/',seq),0,'{post_type}',IF('{post_type}'='attachment','image/jpeg',''),0 FROM seq_1_to_{amount};")
        statements.append('ANALYZE TABLE wp_posts,wp_postmeta,wp_term_relationships,wp_term_taxonomy;')
        sql = '\n'.join(statements)
        (self.output / f'fixture-{n}-{page_lists}.sql').write_text(sql)
        started = time.monotonic()
        self.sql(sql, timeout=1800)
        self.operation('configure', '1')
        self.operation('fixture_reset')
        self.operation('clear')
        fixture = {'primary_count': n, 'page_list_stress': page_lists, 'seed_seconds': time.monotonic() - started, 'sql_sha256': hashlib.sha256(sql.encode()).hexdigest(), 'workspace_bytes': self.storage_check()}
        fixture['inventory'] = json.loads(self.operation('inventory', timeout=180).stdout)
        write_json(self.output / f'fixture-{n}-{page_lists}.json', fixture)
        return fixture

    def http_sample(self, path, identifier, operation):
        started = time.perf_counter()
        req = urllib.request.Request(self.base + path, headers={'X-Cybermaps-Perf-Id': identifier})
        try:
            with urllib.request.urlopen(req, timeout=self.args.timeout) as response:
                body = response.read()
                status = response.status
                headers = dict(response.headers)
        except urllib.error.HTTPError as error:
            body, status, headers = error.read(), error.code, dict(error.headers)
        elapsed = time.perf_counter() - started
        body_error = validate_http(operation, status, body)
        return {'body_valid': body_error is None, 'error': body_error, 'id': identifier, 'seconds': elapsed, 'status': status, 'bytes': len(body), 'sha256': hashlib.sha256(body).hexdigest(), 'headers': headers, 'failure_body_prefix': body[:2048].decode('utf-8', errors='replace') if body_error else None}

    def read_http_metric(self, sample):
        if not hasattr(self, 'metrics_offset'):
            self.metrics_offset, self.metrics_tail, self.http_metrics = 0, '', {}
        deadline = time.monotonic() + 2
        path = self.output / 'http-metrics.jsonl'
        while True:
            if path.exists():
                with path.open() as log:
                    log.seek(self.metrics_offset)
                    appended = log.read()
                    self.metrics_offset = log.tell()
                complete, separator, self.metrics_tail = (self.metrics_tail + appended).rpartition('\n')
                if separator:
                    for line in complete.splitlines():
                        try:
                            metric = json.loads(line)
                        except ValueError:
                            continue
                        if isinstance(metric, dict) and isinstance(metric.get('id'), str):
                            self.http_metrics[metric['id']] = metric
                if sample['id'] in self.http_metrics:
                    return self.http_metrics.pop(sample['id']) | sample
            if time.monotonic() >= deadline:
                return sample | {'error': sample.get('error') or 'missing_request_metrics'}
            time.sleep(.02)

    def analyze_queries(self, samples, target):
        unique = {}
        for sample in samples:
            for query in sample.get('slow_queries', []):
                unique.setdefault(query['sql'], query)
        queries = sorted(unique.values(), key=lambda q: q['seconds'], reverse=True)[:8]
        plans = []
        for query in queries:
            try:
                raw = self.sql('SET SESSION max_statement_time=30; ANALYZE FORMAT=JSON ' + query['sql'] + ';', timeout=40)
                plans.append(query | {'analyze': json.loads(raw)})
            except Exception as error:
                plans.append(query | {'analyze_error': str(error)})
        write_json(target, plans)

    def measure(self, size, fixture):
        inventory = fixture['inventory']
        routes = discover_routes(inventory)
        operations = self.args.operations.split(',')
        for persistent, cache in [(p, c) for p in ['off', 'on'] for c in ['on', 'off']]:
            self.persistent_cache(persistent)
            self.operation('configure', '1' if cache == 'on' else '0')
            for operation in operations:
                self.storage_check()
                samples = []
                stem = f'{size}-redis-{persistent}-plugin-{cache}-{operation}'
                print(stem, flush=True)
                if operation in routes:
                    failures = 0
                    for state, repetitions in [('cold', self.args.cold), ('warm', self.args.warm + 1)]:
                        for index in range(repetitions):
                            identifier = f'{stem}-{state}-{index}'
                            if failures >= 3:
                                samples.append(skipped_sample(identifier, state, state == 'warm' and index == 0))
                                write_json(self.output / (stem + '.json'), samples)
                                continue
                            if state == 'cold':
                                self.operation('clear')
                            identifier = f'{stem}-{state}-{index}'
                            try:
                                sample = self.http_sample(routes[operation], identifier, operation)
                                sample |= {'state': state, 'warmup': state == 'warm' and index == 0}
                            except Exception as error:
                                sample = {'id': identifier, 'state': state, 'warmup': state == 'warm' and index == 0, 'error': str(error)}
                                self.command('podman', 'restart', '-t', '0', self.http)
                            sample = self.read_http_metric(sample)
                            if not valid_sample(sample):
                                failures += 1
                            samples.append(sample)
                            write_json(self.output / (stem + '.json'), samples)
                else:
                    self.operation('clear')
                    if operation == 'audit':
                        self.reset_audit_fixture(stem)
                    started = time.perf_counter()
                    try:
                        result = self.operation(operation, *([str(size)] if operation == 'ownership' else []), timeout=self.args.long_timeout, check=False)
                        (self.output / (stem + '.stdout')).write_text(result.stdout)
                        (self.output / (stem + '.stderr')).write_text(result.stderr)
                        sample = {'operation': operation, 'process_seconds': time.perf_counter() - started, 'exit_code': result.returncode}
                        if result.returncode in (124, 137, 143):
                            sample['error'] = 'operation_terminated_or_timed_out'
                        if result.returncode == 0:
                            sample |= json.loads(result.stdout)
                        samples.append(sample)
                    except subprocess.TimeoutExpired:
                        # timeout kills the host client; explicitly kill that PHP exec too.
                        self.command('podman', 'exec', self.http, 'sh', '-c', "pkill -f '/performance/runner.php' || true", check=False)
                        samples.append({'error': 'operation_timeout', 'timeout_seconds': self.args.long_timeout})
                for sample in samples:
                    sample['valid'] = valid_sample(sample)
                write_json(self.output / (stem + '.json'), samples)
                self.analyze_queries(samples, self.output / (stem + '-plans.json'))
        self.summarize()

    def summarize(self):
        rows = []
        for path in sorted(self.output.glob('*.json')):
            if not path.name[0].isdigit() or path.name.endswith(('-plans.json', '-setup.json')):
                continue
            samples = json.loads(path.read_text())
            if samples and 'state' not in samples[0]:
                rows.append({'case': path.stem, 'complete': all(s.get('valid') for s in samples),
                             'samples': samples})
            for state in ['cold', 'warm']:
                selected = [s for s in samples if s.get('state') == state and not s.get('warmup')]
                times = sorted(s['seconds'] for s in selected if valid_sample(s))
                if not selected:
                    continue
                rows.append({'case': path.stem, 'state': state, 'samples': len(selected), 'valid_samples': len(times), 'complete': len(times) == len(selected), 'p50_seconds': times[math.ceil(len(times)*.5)-1] if len(times) == len(selected) else None,
                             'p95_seconds': times[math.ceil(len(times)*.95)-1] if len(times) == len(selected) else None, 'statuses': sorted(set(s.get('status', 0) for s in selected)),
                             'max_queries': max((s['queries'] for s in selected if valid_sample(s)), default=None), 'peak_memory_bytes': max((s['peak_memory_bytes'] for s in selected if valid_sample(s)), default=None)})
        write_json(self.output / 'summary.json', rows)

    def cleanup(self):
        self.cleaning_up = True
        self.watch_stop.set()
        if self.watch_thread is not None:
            self.watch_thread.join(timeout=35)
            if self.watch_thread.is_alive():
                self.watch_error = self.watch_error or 'Watchdog did not stop during cleanup'
        write_json(self.output / 'resource-observations.json', {'peak_combined_rss_bytes': self.peak_container_rss, 'samples': self.resource_samples, 'stop_reason': self.resource_stop, 'storage_exceeded': self.storage_exceeded, 'watchdog_error': self.watch_error})
        for container in (self.http, self.db, self.redis):
            result = self.command('podman', 'logs', container, check=False)
            (self.output / (container.rsplit('-', 1)[-1] + '-container.log')).write_text(result.stdout + result.stderr)
        self.command('podman', 'rm', '-f', self.http, self.db, self.redis, check=False)
        self.command('podman', 'network', 'rm', self.identifier, check=False)
        if not self.args.keep:
            # Podman's subordinate UID owns some DB files: namespace-aware removal.
            self.command('podman', 'unshare', 'rm', '-rf', str(self.work), check=False)
        self.log.close()
        if self.watch_error:
            completion_path = self.output / 'completion.json'
            completion = json.loads(completion_path.read_text()) if completion_path.exists() else {}
            completion.update(complete=False, watchdog_error=self.watch_error)
            write_json(completion_path, completion)
            raise RuntimeError('Resource watchdog did not complete safely: ' + self.watch_error)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--zip', type=lambda s: Path(s).resolve(), required=True)
    parser.add_argument('--image-manifest', type=lambda value: Path(value).resolve())
    parser.add_argument('--label', required=True)
    parser.add_argument('--sizes', type=int, nargs='+', default=[1000, 10000, 100000, 1000000])
    parser.add_argument('--warm', type=int, default=20)
    parser.add_argument('--cold', type=int, default=3)
    parser.add_argument('--timeout', type=int, default=150)
    parser.add_argument('--long-timeout', type=int, default=600)
    parser.add_argument('--operations', default='sitemap_index,sitemap_early,sitemap_deep,llms,llms_full,llms_tldr,rag,search_hit,search_miss,audit_batch,links,audit,queue,static_sync')
    parser.add_argument('--stress-page-lists', type=int, default=0)
    parser.add_argument('--database', choices=['mariadb', 'mysql'], default='mariadb')
    parser.add_argument('--lock-only', action='store_true')
    parser.add_argument('--keep', action='store_true')
    args = parser.parse_args()
    if not args.label.replace('-', '').replace('_', '').isalnum():
        parser.error('Label must contain only letters, numbers, underscore and hyphen')
    if args.warm < 1 or args.cold < 1 or args.timeout < 1 or args.long_timeout < 1:
        parser.error('Sample counts and timeouts must be positive')
    if args.database == 'mysql' and not args.lock_only:
        parser.error('MySQL compatibility run is --lock-only; corpus benchmarks use MariaDB')
    digest = hashlib.sha256(args.zip.read_bytes()).hexdigest()
    if args.zip.name == 'cybermaps_8.0.1.zip' and digest != BASELINE_SHA:
        parser.error('Baseline ZIP digest differs from the reviewed package')
    TEMP.mkdir(parents=True, exist_ok=True)
    runtime = Runtime(args)
    print(runtime.output, flush=True)
    try:
        runtime.freeze_package(digest)
        runtime.setup()
        runtime.lock_contention()
        runtime.lock_contention(queue=True)
        for size in ([] if args.lock_only else ([args.stress_page_lists] if args.stress_page_lists else args.sizes)):
            fixture = runtime.seed(size, bool(args.stress_page_lists))
            runtime.measure(size, fixture)
        runtime.verify_package()
        complete = runtime.all_valid()
        runtime.verify_package()
        write_json(runtime.output / 'completion.json', {'complete': complete, 'executed': True, 'zip_sha256': digest})
        if not complete:
            raise SystemExit(2)
    except SystemExit:
        raise
    except BaseException as error:
        write_json(runtime.output / 'completion.json', {'complete': False, 'error': str(error), 'zip_sha256': digest})
        raise
    finally:
        runtime.cleanup()


if __name__ == '__main__':
    main()
