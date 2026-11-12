#!/usr/bin/env python3
"""Build and deploy MagExpert main; invoked by the production systemd timer."""
import fcntl
import json
import os
import re
import shutil
import subprocess
import sys
import time
from pathlib import Path

REPOSITORY = 'https://github.com/awave21/magexpert-prod.git'
BASE = Path('/srv/mag-services')
PUBLIC_HOST = 'www.medalliance-expert.ru'
ROUTES = ('/', '/contacts', '/events', '/documents', '/events/podderzka-liuteinovoi-fazy')
SERVICES = ('site-app', 'site-web', 'site-worker', 'site-scheduler')


def run(*args, **kwargs):
    return subprocess.run(args, check=True, **kwargs)


def revision(value):
    if not re.fullmatch(r'[0-9a-f]{40}', value):
        raise ValueError('Invalid Git revision')
    return value


def write_json(path, data):
    temporary = path.with_suffix('.tmp')
    temporary.write_text(json.dumps(data, indent=2) + '\n')
    temporary.chmod(0o600)
    temporary.replace(path)


def set_tag(path, tag):
    if not re.fullmatch(r'[a-zA-Z0-9_.-]+', tag):
        raise ValueError('Invalid release tag')
    content = path.read_text()
    if len(re.findall(r'^SITE_RELEASE_TAG=', content, re.M)) != 1:
        raise ValueError('Expected exactly one SITE_RELEASE_TAG setting')
    temporary = path.with_suffix('.new')
    temporary.write_text(re.sub(r'^SITE_RELEASE_TAG=.*$', 'SITE_RELEASE_TAG=' + tag, content, flags=re.M))
    temporary.chmod(0o600)
    temporary.replace(path)


def compose(config, *args, tag=None, **kwargs):
    environment = dict(os.environ)
    if tag:
        environment['SITE_RELEASE_TAG'] = tag
    return run('docker', 'compose', '--project-directory', str(config), '-f', str(config / 'compose.yaml'), *args, env=environment, **kwargs)


def check_site():
    for attempt in range(15):
        try:
            for route in ROUTES:
                status = run('curl', '--silent', '--show-error', '--max-time', '20',
                             '--resolve', PUBLIC_HOST + ':443:127.0.0.1',
                             '--output', '/dev/null', '--write-out', '%{http_code}',
                             'https://' + PUBLIC_HOST + route,
                             capture_output=True, text=True).stdout
                if status != '200':
                    raise RuntimeError('Public route {} returned HTTP {}'.format(route, status))
            return
        except (subprocess.CalledProcessError, RuntimeError):
            if attempt == 14:
                raise
            time.sleep(2)


def activate(config, sha, state, backup):
    env_file = config / '.env'
    old_environment = env_file.read_bytes()
    backup.mkdir(parents=True, exist_ok=True)
    shutil.copy2(env_file, backup / 'compose.env')
    try:
        set_tag(env_file, 'main-' + revision(sha))
        compose(config, 'up', '-d', '--no-deps', *SERVICES)
        check_site()
        write_json(state, {'revision': sha, 'deployed_at': int(time.time()), 'backup': str(backup)})
    except BaseException:
        temporary = env_file.with_suffix('.rollback')
        temporary.write_bytes(old_environment)
        temporary.chmod(0o600)
        temporary.replace(env_file)
        compose(config, 'up', '-d', '--no-deps', *SERVICES)
        check_site()
        print('Previous application images restored; database backup retained.', flush=True)
        raise


def deploy(base, repository, sha):
    sha = revision(sha)
    if shutil.disk_usage(base).free < 2 * 1024 ** 3:
        raise RuntimeError('At least 2 GiB of free disk space is required before building')
    automation = base / 'autodeploy'
    config = base / 'release/deploy/docker'
    stage = automation / 'releases' / sha
    if stage.exists():
        shutil.rmtree(stage)
    stage.mkdir(parents=True)
    archive = automation / 'source.tar'
    with archive.open('wb') as output:
        run('git', '-C', str(repository), 'archive', '--format=tar', sha, stdout=output)
    run('tar', '-xf', str(archive), '-C', str(stage))
    archive.unlink()
    tag = 'main-' + sha
    build_args = ('--secret', 'id=frontend_env,src=' + str(automation / 'frontend.env'),
                  '--build-arg', 'APP_SERVICE=site', '--label', 'org.opencontainers.image.revision=' + sha,
                  '-f', str(stage / 'deploy/docker/Dockerfile'))
    for target, image in (('app', 'magexpert-site'), ('web', 'magexpert-web')):
        run('docker', 'build', *build_args, '--target', target, '-t', image + ':' + tag, str(stage))
    backup = base / 'backups' / ('autodeploy-' + sha + '-' + str(int(time.time())))
    backup.mkdir(parents=True)
    with (backup / 'site.dump').open('wb') as output:
        compose(config, 'exec', '-T', 'site-db', 'pg_dump', '-U', 'postgres', '-d', 'magexpert',
                '-Fc', '--no-owner', '--no-acl', stdout=output)
    compose(config, 'run', '--rm', '--no-deps', '-T', 'site-app', 'php', 'artisan',
            'migrate', '--force', '--no-interaction', tag=tag)
    activate(config, sha, automation / 'current.json', backup)
    installed = Path('/usr/local/sbin/magexpert-autodeploy')
    source = stage / 'deploy/docker/autodeploy.py'
    if source.is_file():
        temporary = installed.with_suffix('.new')
        shutil.copyfile(source, temporary)
        temporary.chmod(0o700)
        temporary.replace(installed)
    print('Deployed main revision ' + sha, flush=True)


def main():
    os.umask(0o077)
    automation = BASE / 'autodeploy'
    automation.mkdir(parents=True, exist_ok=True)
    with (automation / 'deploy.lock').open('w') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        repository = automation / 'repository'
        if not repository.exists():
            run('git', 'clone', '--bare', REPOSITORY, str(repository))
        run('git', '-C', str(repository), 'fetch', '--prune', 'origin', 'main')
        sha = revision(run('git', '-C', str(repository), 'rev-parse', 'FETCH_HEAD', capture_output=True, text=True).stdout.strip())
        current = automation / 'current.json'
        if current.exists() and json.loads(current.read_text()).get('revision') == sha:
            return
        failed = automation / 'failed.json'
        if failed.exists():
            last = json.loads(failed.read_text())
            if last.get('revision') == sha and time.time() - last.get('attempted_at', 0) < 300:
                return
        try:
            deploy(BASE, repository, sha)
            failed.unlink(missing_ok=True)
        except BaseException as error:
            write_json(failed, {'revision': sha, 'attempted_at': int(time.time()), 'error': str(error)})
            raise


if __name__ == '__main__':
    main()
