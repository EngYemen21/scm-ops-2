<?php

/**
 * One-command project setup. Safe to run again: it never overwrites a value you already set and never drops data
 * unless you pass --fresh.
 *
 *   php tools/setup.php              use a MySQL server you already have (fill DB_* in .env when asked)
 *   php tools/setup.php --docker     start MySQL 8.4 from docker-compose.yml with a generated password
 *   php tools/setup.php --fresh      rebuild the database from scratch (DROPS ALL TABLES) and reload the demo data
 *   php tools/setup.php --skip-node  do not install the client's Node packages
 *
 * Every secret (APP_KEY, JWT_ACCESS_SECRET, SEED_PASSWORD, the Docker database password) is generated on YOUR machine
 * into .env, which git ignores. Nothing secret lives in the repository.
 */
$root = dirname(__DIR__);
chdir($root);
$args = array_slice($argv, 1);
$has = fn (string $flag) => in_array($flag, $args, true);
$php = escapeshellarg(PHP_BINARY);

function step(string $title): void
{
    echo "\n==> {$title}\n";
}
function fail(string $message): never
{
    fwrite(STDERR, "\n[setup] STOPPED: {$message}\n");
    exit(1);
}
function run(string $command, bool $must = true): bool
{
    echo "    $ {$command}\n";
    passthru($command, $code);
    if ($code !== 0 && $must) {
        fail("command failed ({$code}): {$command}");
    }

    return $code === 0;
}
function available(string $program): bool
{
    $probe = PHP_OS_FAMILY === 'Windows' ? "where {$program} 2>NUL" : "command -v {$program} 2>/dev/null";

    return trim((string) shell_exec($probe)) !== '';
}
/** @return array<string,string> */
function envRead(string $file): array
{
    $out = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m)) {
            $out[$m[1]] = trim($m[2], "\"'");
        }
    }

    return $out;
}
function envSet(string $file, string $key, string $value): void
{
    $text = file_get_contents($file);
    $line = $key.'='.(preg_match('/[\s#"\']/', $value) ? '"'.$value.'"' : $value);
    $text = preg_match("/^{$key}=.*$/m", $text) ? preg_replace("/^{$key}=.*$/m", addcslashes($line, '\\$'), $text, 1) : rtrim($text)."\n{$line}\n";
    file_put_contents($file, $text);
}
/** Sets $key only when it is missing or empty; returns true when it wrote. */
function envFill(string $file, string $key, callable $make): bool
{
    if ((envRead($file)[$key] ?? '') !== '') {
        return false;
    }
    envSet($file, $key, $make());
    echo "    generated {$key}\n";

    return true;
}
function randomPassword(int $length): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'; // no look-alikes: it is read from the terminal
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $out;
}

// ---------------------------------------------------------------------------------------------------- 1. requirements
step('Checking requirements');
if (PHP_VERSION_ID < 80300) {
    fail('PHP 8.3 or newer is required, this is '.PHP_VERSION);
}
$missing = array_values(array_filter(['pdo_mysql', 'mbstring', 'openssl', 'intl', 'curl', 'fileinfo', 'sodium'], fn ($e) => ! extension_loaded($e)));
if ($missing) {
    fail('enable these PHP extensions in php.ini: '.implode(', ', $missing));
}
echo '    PHP '.PHP_VERSION." with the needed extensions\n";

// -------------------------------------------------------------------------------------------------- 2. PHP packages
if (! is_file('vendor/autoload.php')) {
    step('Installing PHP packages');
    if (! available('composer')) {
        fail('Composer 2 is not installed — https://getcomposer.org/download/');
    }
    run('composer install --no-interaction --no-progress');
}

// --------------------------------------------------------------------------------------------------------- 3. .env
step('Preparing .env');
if (! is_file('.env')) {
    copy('.env.example', '.env');
    echo "    created .env from .env.example\n";
}
envFill('.env', 'JWT_ACCESS_SECRET', fn () => bin2hex(random_bytes(48)));
$newSeedPassword = envFill('.env', 'SEED_PASSWORD', fn () => 'Ops-'.randomPassword(12));
if ($has('--docker')) {
    envFill('.env', 'DB_PASSWORD', fn () => randomPassword(28));
}
if ((envRead('.env')['APP_KEY'] ?? '') === '') {
    run("{$php} artisan key:generate --force --ansi");
}

// ---------------------------------------------------------------------------------------------------- 4. database
if ($has('--docker')) {
    step('Starting MySQL 8.4 in Docker');
    if (! available('docker')) {
        fail('Docker is not installed. Install Docker Desktop, or run this script without --docker against your own MySQL.');
    }
    run('docker compose up -d --wait');
}

step('Connecting to the database');
$env = envRead('.env');
$pdo = null;
$lastError = '';
for ($try = 0; $try < 20 && ! $pdo; $try++) {
    try {
        $pdo = new PDO("mysql:host={$env['DB_HOST']};port={$env['DB_PORT']};dbname={$env['DB_DATABASE']};charset=utf8mb4", $env['DB_USERNAME'], $env['DB_PASSWORD'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    } catch (PDOException $e) {
        $lastError = $e->getMessage();
        if (! $has('--docker')) {
            break;
        }
        sleep(2);
    }
}
if (! $pdo) {
    fail("cannot connect to MySQL as {$env['DB_USERNAME']}@{$env['DB_HOST']}:{$env['DB_PORT']}/{$env['DB_DATABASE']}\n        {$lastError}\n\n"
        ."    Either run:  php tools/setup.php --docker\n"
        ."    or create the database yourself (as the MySQL root user), put the same values in .env and run this again:\n\n"
        ."        CREATE DATABASE scm_ops CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
        ."        CREATE DATABASE scm_ops_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n"
        ."        CREATE USER 'scm'@'%' IDENTIFIED BY '<choose a password>';\n"
        ."        GRANT ALL ON `scm_ops`.* TO 'scm'@'%';\n"
        ."        GRANT ALL ON `scm_ops_test%`.* TO 'scm'@'%';\n");
}
echo '    connected — MySQL '.$pdo->query('SELECT VERSION()')->fetchColumn()."\n";
try {
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `scm_ops_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo "    test database scm_ops_test is ready\n";
} catch (PDOException) {
    echo "    note: this user may not create scm_ops_test — `php artisan test` needs that database (SQL above)\n";
}

$tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
if ($has('--fresh')) {
    step('Rebuilding the database with the demo data (--fresh)');
    run("{$php} artisan migrate:fresh --seed --force --ansi");
} elseif ($tables === 0) {
    step('Creating the schema and loading the demo data');
    run("{$php} artisan migrate --seed --force --ansi");
} else {
    step('Applying new migrations (existing data is kept)');
    run("{$php} artisan migrate --force --ansi");
}
run("{$php} artisan scm:reconcile", false);

// ------------------------------------------------------------------------------------------------------ 5. client
if (! $has('--skip-node')) {
    step('Installing the client packages');
    if (available('npm')) {
        run('npm ci --no-audit --no-fund', false);
    } else {
        echo "    Node is not installed: the committed build in public/build still serves the app;\n    install Node 20+ when you start changing resources/js.\n";
    }
}
if (! is_file('public/build/manifest.json')) {
    step('Building the client');
    run('npm run build');
}

// -------------------------------------------------------------------------------------------------------- 6. done
$env = envRead('.env');
echo "\n============================================================\n";
echo " Ready.\n\n";
echo "   php artisan serve --port=8000     then open http://127.0.0.1:8000\n";
echo "   npm run dev                       (second terminal) hot reload while editing resources/js\n";
echo "   php artisan test                  the whole backend suite\n\n";
echo "   Demo logins: admin, sales, wm, inv, proc, disp, worker, driver, gm, finance\n";
echo '   Password   : '.$env['SEED_PASSWORD'].($newSeedPassword ? '   (generated now — kept in .env as SEED_PASSWORD)' : '   (SEED_PASSWORD in .env)')."\n";
if (! $newSeedPassword && $tables > 0 && ! $has('--fresh')) {
    echo "                demo users keep the password they were seeded with; --fresh resets them\n";
}
echo "============================================================\n";
