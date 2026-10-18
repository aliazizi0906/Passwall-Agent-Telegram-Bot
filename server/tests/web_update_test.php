<?php
declare(strict_types=1);

define('PW_WEB_UPDATE_TEST_MODE', true);
require dirname(__DIR__) . '/web-update.php';

function pw_update_test_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . "\n");
        exit(1);
    }
}

function pw_update_test_throws(callable $callback, string $message): void {
    try {
        $callback();
    } catch (Throwable $e) {
        return;
    }
    pw_update_test_assert(false, $message);
}

pw_update_test_assert(pw_web_update_valid_version('0.7.0'), 'stable version should be accepted');
pw_update_test_assert(pw_web_update_valid_version('1.2.3-rc.1'), 'pre-release version should be accepted');
foreach (['', '../0.7.0', '0.7', '0.7.0/evil', '0.7.0..x', 'v0.7.0', '0.7.0-'] as $version) {
    pw_update_test_assert(!pw_web_update_valid_version($version), 'invalid version should be rejected: ' . $version);
}
pw_update_test_assert(pw_web_update_version_from_tag('v0.7.0') === '0.7.0', 'valid tag should yield version');
pw_update_test_assert(pw_web_update_version_from_tag('main') === null, 'branch name must not be treated as a version');
pw_update_test_throws(
    static fn() => pw_web_update_validate_telegram_init_data('auth_date=' . (time() + 3600) . '&hash=' . str_repeat('a', 64) . '&user=%7B%22id%22%3A1%7D'),
    'future Telegram initData timestamp should be rejected before HMAC validation'
);

$assetNames = pw_web_update_asset_names('0.7.0');
pw_update_test_assert($assetNames['archive'] === 'pw-agent-server-v0.7.0.zip', 'archive asset name must be fixed');
pw_update_test_assert($assetNames['manifest'] === 'pw-agent-server-v0.7.0.manifest.json', 'manifest asset name must be fixed');
pw_update_test_assert($assetNames['signature'] === 'pw-agent-server-v0.7.0.manifest.json.sig', 'signature asset name must be fixed');
pw_update_test_throws(
    static fn() => pw_web_update_release_url('0.7.0', 'https://evil.example/payload.zip'),
    'arbitrary release asset name must be rejected'
);

$manifest = [
    'schema' => 1,
    'version' => '0.7.0',
    'asset' => $assetNames['archive'],
    'size' => 1024,
    'sha256' => str_repeat('a', 64),
    'php_min_version' => '8.1.0',
    'required_extensions' => ['curl', 'pdo_mysql', 'openssl'],
    'db_migrations' => 'additive',
];
$manifestRaw = json_encode($manifest, JSON_UNESCAPED_SLASHES);
pw_update_test_assert(is_string($manifestRaw), 'test manifest should encode');
$parsed = pw_web_update_parse_manifest($manifestRaw, '0.7.0');
pw_update_test_assert($parsed['version'] === '0.7.0', 'manifest version should parse');
pw_update_test_assert($parsed['db_migrations'] === 'additive', 'additive migration policy should parse');

$bad = $manifest;
$bad['asset'] = 'https://evil.example/payload.zip';
pw_update_test_throws(
    static fn() => pw_web_update_parse_manifest((string)json_encode($bad), '0.7.0'),
    'manifest with arbitrary archive URL should be rejected'
);
$bad = $manifest;
$bad['version'] = '0.8.0';
pw_update_test_throws(
    static fn() => pw_web_update_parse_manifest((string)json_encode($bad), '0.7.0'),
    'manifest version mismatch should be rejected'
);
$bad = $manifest;
$bad['db_migrations'] = 'destructive';
pw_update_test_throws(
    static fn() => pw_web_update_parse_manifest((string)json_encode($bad), '0.7.0'),
    'manifest with an undeclared migration policy should be rejected'
);
$bad = $manifest;
$bad['required_extensions'] = ['curl'];
pw_update_test_throws(
    static fn() => pw_web_update_parse_manifest((string)json_encode($bad), '0.7.0'),
    'manifest omitting required backend extensions should be rejected'
);
$bad = $manifest;
$bad['size'] = PW_WEB_UPDATE_MAX_ARCHIVE_BYTES + 1;
pw_update_test_throws(
    static fn() => pw_web_update_parse_manifest((string)json_encode($bad), '0.7.0'),
    'oversized manifest archive should be rejected'
);

pw_update_test_assert(pw_web_update_normalize_archive_path('api/update_manifest.php') === 'api/update_manifest.php', 'normal relative path should be accepted');
foreach ([
    '../escape.php',
    'api/../../escape.php',
    '/absolute.php',
    'C:/windows/system.ini',
    'api\\escape.php',
    'api//escape.php',
    './health.php',
    'api/./escape.php',
    'dir/name:stream.php',
    ".pw-backend-maintenance.lock",
] as $path) {
    pw_update_test_throws(
        static fn() => pw_web_update_normalize_archive_path($path),
        'unsafe archive path should be rejected: ' . $path
    );
}

foreach ([
    'config.php',
    'config.local.php',
    '.env',
    'releases/pw-agent-openwrt-0.6.0-amd64.tar.gz',
    'cache/item.bin',
    'uploads/user-file.png',
    'runtime/instance.json',
    'keys/instance.key',
    'private/private.pem',
    'database.sqlite',
] as $path) {
    pw_update_test_assert(pw_web_update_is_preserved_path($path), 'runtime path must be preserved: ' . $path);
}
foreach (['lib.php', 'health.php', 'api/update_manifest.php', '.htaccess'] as $path) {
    pw_update_test_assert(!pw_web_update_is_preserved_path($path), 'backend code path should remain updateable: ' . $path);
}

$repoKeyPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'UPDATE_PUBLIC_KEY.b64';
$repoKey = is_file($repoKeyPath) ? trim((string)file_get_contents($repoKeyPath)) : '';
pw_update_test_assert($repoKey !== '', 'repository UPDATE_PUBLIC_KEY.b64 must be available for trust-root comparison');
pw_update_test_assert(hash_equals($repoKey, PW_WEB_UPDATE_PUBLIC_KEY_B64), 'embedded updater trust root must match UPDATE_PUBLIC_KEY.b64');
pw_update_test_assert(strlen((string)base64_decode(PW_WEB_UPDATE_PUBLIC_KEY_B64, true)) === 32, 'Ed25519 public key must decode to 32 bytes');

if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "FAIL: sodium extension is required to run updater security tests.\n");
    exit(1);
}
$keypair = sodium_crypto_sign_keypair();
$testPublicKey = sodium_crypto_sign_publickey($keypair);
$testSecretKey = sodium_crypto_sign_secretkey($keypair);
$signedPayload = '{"test":"signed manifest"}';
$signature = sodium_crypto_sign_detached($signedPayload, $testSecretKey);
pw_update_test_assert(
    pw_web_update_verify_signature_bytes($signedPayload, $signature, $testPublicKey),
    'valid detached Ed25519 signature should verify'
);
pw_update_test_assert(
    !pw_web_update_verify_signature_bytes($signedPayload . 'x', $signature, $testPublicKey),
    'tampered signed payload should fail verification'
);
pw_update_test_assert(
    !pw_web_update_verify_signature_bytes($signedPayload, str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES), $testPublicKey),
    'invalid detached Ed25519 signature should fail verification'
);

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "FAIL: zip extension is required to run updater archive security tests.\n");
    exit(1);
}

function pw_update_test_zip(string $path, array $entries, ?string $symlinkName = null): void {
    $zip = new ZipArchive();
    $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) throw new RuntimeException('Could not create test ZIP.');
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
        if ($name === './') {
            $zip->setExternalAttributesName('./', ZipArchive::OPSYS_UNIX, (0040755 << 16));
        }
    }
    if ($symlinkName !== null) {
        $zip->setExternalAttributesName($symlinkName, ZipArchive::OPSYS_UNIX, (0120777 << 16));
    }
    $zip->close();
}

$tmp = tempnam(sys_get_temp_dir(), 'pw-web-update-test-');
if ($tmp === false) throw new RuntimeException('Could not create a temporary test path.');
$normalEntries = [
    './' => '',
    './health.php' => '<?php echo "ok";',
    './lib.php' => '<?php function app_version(): string { return "0.7.0"; }',
    './web-update.php' => '<?php declare(strict_types=1);',
    './api/example.php' => '<?php echo "example";',
    './uploads/preserved.txt' => 'runtime data',
];
try {
    pw_update_test_zip($tmp, $normalEntries);
    $zip = new ZipArchive();
    pw_update_test_assert($zip->open($tmp, ZipArchive::CHECKCONS) === true, 'normal ZIP should open');
    $validated = pw_web_update_validate_zip_archive($zip);
    $zip->close();
    pw_update_test_assert(count($validated['entries']) === count($normalEntries) - 1, 'normal ZIP entries should validate after skipping the root marker');
    pw_update_test_assert($validated['total_bytes'] > 0, 'expanded size should be counted');

    pw_update_test_zip($tmp, [
        'health.php' => '<?php',
        'lib.php' => '<?php',
        'web-update.php' => '<?php',
        '../escape.php' => 'outside',
    ]);
    $zip = new ZipArchive();
    pw_update_test_assert($zip->open($tmp, ZipArchive::CHECKCONS) === true, 'traversal ZIP should open for validation');
    pw_update_test_throws(
        static fn() => pw_web_update_validate_zip_archive($zip),
        'ZIP traversal entry must be rejected'
    );
    $zip->close();

    pw_update_test_zip($tmp, [
        'health.php' => '<?php',
        'lib.php' => '<?php',
        'web-update.php' => '<?php',
        'linked-file' => 'outside-target',
    ], 'linked-file');
    $zip = new ZipArchive();
    pw_update_test_assert($zip->open($tmp, ZipArchive::CHECKCONS) === true, 'symlink ZIP should open for validation');
    pw_update_test_throws(
        static fn() => pw_web_update_validate_zip_archive($zip),
        'ZIP symlink entry must be rejected'
    );
    $zip->close();
} finally {
    @unlink($tmp);
}

$tempBase = realpath(sys_get_temp_dir());
pw_update_test_assert($tempBase !== false, 'system temporary directory should resolve for recovery test');
$suffix = bin2hex(random_bytes(8));
$recoveryRoot = $tempBase . DIRECTORY_SEPARATOR . 'pw-web-update-root-test-' . $suffix;
$recoveryDocRoot = $tempBase . DIRECTORY_SEPARATOR . 'pw-web-update-docroot-test-' . $suffix;
$recoveryBackup = $tempBase . DIRECTORY_SEPARATOR . 'pw-web-update-backup-test-' . $suffix;
$recoveryLockPath = tempnam($tempBase, 'pw-web-update-lock-test-');
if (!@mkdir($recoveryRoot, 0700) || !@mkdir($recoveryDocRoot, 0700) || !@mkdir($recoveryBackup, 0700) || $recoveryLockPath === false) {
    throw new RuntimeException('Could not prepare the interrupted-update recovery test.');
}
$previousDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
try {
    file_put_contents($recoveryRoot . DIRECTORY_SEPARATOR . 'lib.php', 'partially updated');
    file_put_contents($recoveryRoot . DIRECTORY_SEPARATOR . 'new.php', 'partially installed');
    file_put_contents($recoveryBackup . DIRECTORY_SEPARATOR . 'lib.php', 'previous version');
    $recoveryRecords = [
        ['path' => 'lib.php', 'existed' => true, 'mode' => 0644, 'sha256' => hash_file('sha256', $recoveryBackup . DIRECTORY_SEPARATOR . 'lib.php')],
        ['path' => 'new.php', 'existed' => false, 'mode' => null, 'sha256' => null],
    ];
    $state = [
        'phase' => 'installing',
        'version' => '0.7.0',
        'started_at' => gmdate('c'),
        'user_id' => 123,
        'backup_directory' => $recoveryBackup,
        'records' => $recoveryRecords,
        'applied' => ['lib.php'],
        'current_path' => 'new.php',
    ];
    $lock = fopen($recoveryLockPath, 'w+');
    if (!is_resource($lock)) throw new RuntimeException('Could not open the recovery test lock.');
    fwrite($lock, json_encode($state, JSON_UNESCAPED_SLASHES) . "\n");
    rewind($lock);
    $_SERVER['DOCUMENT_ROOT'] = $recoveryDocRoot;
    pw_web_update_recover_stale_lock($recoveryRoot, $lock);
    fclose($lock);
    pw_update_test_assert(file_get_contents($recoveryRoot . DIRECTORY_SEPARATOR . 'lib.php') === 'previous version', 'interrupted update recovery should restore previous file contents');
    pw_update_test_assert(!file_exists($recoveryRoot . DIRECTORY_SEPARATOR . 'new.php'), 'interrupted update recovery should remove newly added files');
    pw_update_test_assert(file_get_contents($recoveryLockPath) === '', 'successful interrupted-update recovery should clear the stale lock');
} finally {
    if (is_string($previousDocumentRoot)) $_SERVER['DOCUMENT_ROOT'] = $previousDocumentRoot;
    else unset($_SERVER['DOCUMENT_ROOT']);
    @unlink($recoveryLockPath);
    pw_web_update_remove_tree($recoveryRoot);
    pw_web_update_remove_tree($recoveryDocRoot);
    pw_web_update_remove_tree($recoveryBackup);
}

echo "web_update_test: OK\n";
