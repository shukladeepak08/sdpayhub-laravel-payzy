<?php

declare(strict_types=1);

$git = 'C:\\Program Files\\Git\\cmd\\git.exe';
$cwd = getcwd();

function run(string $git, array $args, string $cwd, ?array $env = null): string
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(array_merge([$git], $args), $descriptors, $pipes, $cwd, $env ?? [
        'GIT_AUTHOR_NAME' => 'Deepak Shukla',
        'GIT_AUTHOR_EMAIL' => 'dshukla0806@gmail.com',
        'GIT_COMMITTER_NAME' => 'Deepak Shukla',
        'GIT_COMMITTER_EMAIL' => 'dshukla0806@gmail.com',
        'PATH' => getenv('PATH') ?: '',
        'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
    ]);
    if (! is_resource($proc)) {
        throw new RuntimeException('Failed to start git');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) {
        throw new RuntimeException(trim($err ?: $out) ?: 'git failed');
    }

    return trim((string) $out);
}

run($git, ['add', '-A'], $cwd);
$status = run($git, ['status', '--porcelain'], $cwd);
if ($status === '') {
    echo "Nothing to commit\n";
    exit(0);
}

$msgFile = $cwd.DIRECTORY_SEPARATOR.'.git'.DIRECTORY_SEPARATOR.'MSG_CLEAN.txt';
file_put_contents($msgFile, <<<'MSG'
Fix idempotency breaking fresh Laravel installs

Stop applying idempotency to read-only calls like status/verify, default
auto-generated keys to off, and surface a clear configuration error when
the Laravel cache store is unavailable (common with CACHE_STORE=database
before migrations).
MSG);

run($git, ['commit', '-F', $msgFile], $cwd);
@unlink($msgFile);
echo run($git, ['log', '-1', '--format=fuller'], $cwd)."\n";
echo run($git, ['status', '-sb'], $cwd)."\n";
