<?php

use Symfony\Component\Process\Process;

function runDeploymentCheck(string $code): Process
{
    $process = new Process(['python3', '-c', $code, dirname(__DIR__, 2).'/deploy/docker/autodeploy.py']);
    $process->setTimeout(10);
    $process->run();

    return $process;
}

test('deployment accepts only an exact git revision', function (string $value, bool $valid) {
    $code = <<<'PY'
import runpy, sys
module = runpy.run_path(sys.argv[1])
try:
    module['revision'](VALUE)
except ValueError:
    sys.exit(2)
PY;
    $process = runDeploymentCheck(str_replace('VALUE', json_encode($value), $code));

    expect($process->isSuccessful())->toBe($valid);
})->with([
    [str_repeat('a', 40), true],
    ['main', false],
    [str_repeat('a', 40).'; echo unsafe', false],
    ['../release', false],
]);

test('deployment restores the previous release when a public page returns an error', function () {
    $process = runDeploymentCheck(<<<'PY'
import runpy, sys, tempfile
from pathlib import Path
from unittest.mock import Mock
module = runpy.run_path(sys.argv[1])
activate = module['activate']
calls = []
def compose(config, *args, **kwargs):
    calls.append(args)
check = Mock(side_effect=[RuntimeError('Event returned HTTP 502'), None])
activate.__globals__['compose'] = compose
activate.__globals__['check_site'] = check
with tempfile.TemporaryDirectory() as directory:
    root = Path(directory)
    config = root / 'config'
    config.mkdir()
    original = b'SITE_RELEASE_TAG=previous\nSENDER_RELEASE_TAG=unchanged\nSENDER_DELIVERY_ENABLED=false\n'
    (config / '.env').write_bytes(original)
    state = root / 'current.json'
    state.write_text('{"revision":"previous"}')
    try:
        activate(config, 'a' * 40, state, root / 'backup')
        raise AssertionError('Deployment should fail')
    except RuntimeError:
        pass
    assert (config / '.env').read_bytes() == original
    assert state.read_text() == '{"revision":"previous"}'
    assert (root / 'backup/compose.env').read_bytes() == original
    assert check.call_count == 2
    assert len(calls) == 2
    assert all(call == ('up', '-d', '--no-deps', 'site-app', 'site-web', 'site-worker', 'site-scheduler') for call in calls)
PY);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});

test('successful deployment records the revision only after public checks pass', function () {
    $process = runDeploymentCheck(<<<'PY'
import json, runpy, sys, tempfile
from pathlib import Path
module = runpy.run_path(sys.argv[1])
activate = module['activate']
activate.__globals__['compose'] = lambda *args, **kwargs: None
with tempfile.TemporaryDirectory() as directory:
    root = Path(directory)
    (root / '.env').write_text('SITE_RELEASE_TAG=previous\nSENDER_RELEASE_TAG=keep-sender\n')
    state = root / 'current.json'
    def check():
        assert not state.exists()
        assert 'SENDER_RELEASE_TAG=keep-sender' in (root / '.env').read_text()
    activate.__globals__['check_site'] = check
    activate(root, 'b' * 40, state, root / 'backup')
    assert json.loads(state.read_text())['revision'] == 'b' * 40
    assert 'SITE_RELEASE_TAG=main-' + 'b' * 40 in (root / '.env').read_text()
PY);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
});
