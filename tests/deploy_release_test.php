<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/upgrade.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = sys_get_temp_dir() . '/cas-deploy-release-' . bin2hex(random_bytes(5));
$source = sys_get_temp_dir() . '/cas-deploy-source-' . bin2hex(random_bytes(5));

$removeDirectory = static function (string $path) use (&$removeDirectory): void {
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($path);
};

try {
    mkdir($root . '/lib', 0755, true);
    mkdir($root . '/assets/uploads', 0755, true);
    mkdir($root . '/storage/upgrades', 0755, true);
    mkdir($root . '/.git', 0755, true);

    file_put_contents($root . '/index.php', "<?php echo 'old index';\n");
    file_put_contents($root . '/keep.php', "<?php echo 'old keep';\n");
    file_put_contents($root . '/obsolete.php', "<?php echo 'remove';\n");
    file_put_contents($root . '/lib/old_only.php', "<?php echo 'remove lib';\n");
    file_put_contents($root . '/config.php', "<?php return ['secret' => 'live'];\n");
    file_put_contents($root . '/.env', "SECRET=live\n");
    file_put_contents($root . '/.git/HEAD', "ref: refs/heads/main\n");
    file_put_contents($root . '/assets/uploads/keep.txt', "user upload\n");
    file_put_contents($root . '/storage/runtime.txt', "runtime\n");

    chmod($root . '/index.php', 0644);
    chmod($root . '/keep.php', 0640);
    chmod($root . '/config.php', 0600);
    $keepMeta = [
        'uid' => fileowner($root . '/keep.php'),
        'gid' => filegroup($root . '/keep.php'),
        'mode' => fileperms($root . '/keep.php') & 0777,
    ];
    $indexMode = fileperms($root . '/index.php') & 0777;

    mkdir($source . '/lib', 0755, true);
    mkdir($source . '/newdir', 0755, true);
    file_put_contents($source . '/index.php', "<?php echo 'new index';\n");
    file_put_contents($source . '/keep.php', "<?php echo 'new keep';\n");
    file_put_contents($source . '/new.php', "<?php echo 'new file';\n");
    file_put_contents($source . '/lib/new_lib.php', "<?php echo 'new lib';\n");
    file_put_contents($source . '/newdir/asset.txt', "new asset\n");
    file_put_contents($source . '/config.php', "<?php return ['secret' => 'release'];\n");
    file_put_contents($source . '/.env', "SECRET=release\n");

    $engine = new UpgradeEngine($root);
    $engine->deployRelease($source, [
        '.git',
        '.env',
        'config.php',
        'assets/uploads',
        'storage',
        'storage/upgrades',
    ]);

    $assert(file_get_contents($root . '/index.php') === "<?php echo 'new index';\n", 'Existing application file was not updated.');
    $assert(file_get_contents($root . '/keep.php') === "<?php echo 'new keep';\n", 'Existing keep.php was not updated.');
    $assert(!file_exists($root . '/obsolete.php'), 'Obsolete root application file survived exact deployment.');
    $assert(!file_exists($root . '/lib/old_only.php'), 'Obsolete nested application file survived exact deployment.');
    $assert(is_file($root . '/new.php'), 'New root application file was not deployed.');
    $assert(is_file($root . '/lib/new_lib.php'), 'New nested application file was not deployed.');
    $assert(is_file($root . '/newdir/asset.txt'), 'New application directory/file was not deployed.');

    $assert(file_get_contents($root . '/config.php') === "<?php return ['secret' => 'live'];\n", 'Preserved config.php was overwritten.');
    $assert(file_get_contents($root . '/.env') === "SECRET=live\n", 'Preserved .env was overwritten.');
    $assert(file_get_contents($root . '/.git/HEAD') === "ref: refs/heads/main\n", 'Preserved .git state was changed.');
    $assert(file_get_contents($root . '/assets/uploads/keep.txt') === "user upload\n", 'Preserved upload was changed.');
    $assert(file_get_contents($root . '/storage/runtime.txt') === "runtime\n", 'Preserved storage was changed.');

    clearstatcache(true, $root . '/keep.php');
    $assert(fileowner($root . '/keep.php') === $keepMeta['uid'], 'Existing file owner changed during deployment.');
    $assert(filegroup($root . '/keep.php') === $keepMeta['gid'], 'Existing file group changed during deployment.');
    $assert((fileperms($root . '/keep.php') & 0777) === $keepMeta['mode'], 'Existing file mode changed during deployment.');

    clearstatcache(true, $root . '/new.php');
    $assert((fileperms($root . '/new.php') & 0777) === $indexMode, 'New application file did not inherit trusted app-file mode.');
    $assert(fileowner($root . '/new.php') === fileowner($root . '/index.php'), 'New application file owner differs from trusted app file.');
    $assert(filegroup($root . '/new.php') === filegroup($root . '/index.php'), 'New application file group differs from trusted app file.');

    fwrite(STDOUT, "Exact metadata-preserving release deployment test passed.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Deploy release test failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    $removeDirectory($root);
    $removeDirectory($source);
}
