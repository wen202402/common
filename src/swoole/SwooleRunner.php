<?php

namespace wen202402\common\swoole;

use Swoole\Coroutine;
use Swoole\Coroutine\System;
use wen202402\common\helper\EnvHelper;
use yii\console\Application;
use yii\helpers\ArrayHelper;
class SwooleRunner{
    private string $rootPath;
    private string $libsPath;

    private string $targetDbName = 'your_db_name';
    private string $sqlGzPath;

    private string $lockFile;
    private string $importMarkFile;

    public function __construct($rootPath){
        $this->rootPath = $rootPath;
        $this->libsPath = $this->rootPath . DIRECTORY_SEPARATOR;
        $this->targetDbName = EnvHelper::getDbName();

        $this->sqlGzPath = $this->rootPath . '/swoole/import/your.sql.gz';

        $this->lockFile = $this->rootPath . '/swoole/import/.init.lock';
        $this->importMarkFile = $this->rootPath . "/swoole/import/.import_mark_{$this->targetDbName}";
    }




    public function run(): void{
        $this->checkdbImportDB($this->targetDbName, $this->sqlGzPath);
        error_log('DB init done.');
        \Swoole\Runtime::enableCoroutine(true);



        $config = ArrayHelper::merge(
            require $this->libsPath . 'common/config/main.php',
            require $this->libsPath . 'common/config/main-local.php',
            require $this->rootPath . '/console/config/main.php',
            require $this->rootPath . '/console/config/main-local.php'
        );

        $app = new Application($config);

        Coroutine::create(function () use ($app) {
            try {
                error_log('queue worker start...');
                System::exec('/usr/bin/php ' . $this->rootPath . '/swoole/queue');
            } catch (\Throwable $e) {
                error_log('queue worker start failed: ' . $e->getMessage());
            }
        });

        $test = 'order';

        swoole_timer_tick(1 * 60 * 1000, function () use ($test, $app) {
            Coroutine::create(function () use ($test, $app) {
                try {
                    error_log('ribao timer start: ' . date('Y-m-d H:i:s'));
                    $exitCode = $app->runAction('cron/contrab/ribao', ['type' => $test]);
                    error_log('ribao exitCode: ' . $exitCode);
                } catch (\Throwable $e) {
                    error_log('ribao error: ' . $e->getMessage());
                    error_log($e->getTraceAsString());
                }
            });
        });

        swoole_timer_tick(6 * 60 * 1000, function () use ($app) {
            Coroutine::create(function () use ($app) {
                try {
                    error_log('variable timer start: ' . date('Y-m-d H:i:s'));
                    $exitCode = $app->runAction('cron/contrab/variable');
                    error_log('variable exitCode: ' . $exitCode);
                } catch (\Throwable $e) {
                    error_log('variable error: ' . $e->getMessage());
                    error_log($e->getTraceAsString());
                }
            });
        });

        swoole_event_wait();
    }

    private function checkdbImportDB(string $targetDbName, string $sqlGzPath): void{
        if (!file_exists($sqlGzPath)) throw new \RuntimeException("sql.gz not found: {$sqlGzPath}");

        $fp = fopen($this->lockFile, 'c');
        if (!$fp) throw new \RuntimeException("Cannot open lock file: {$this->lockFile}");

        flock($fp, LOCK_EX);
        try {
            if (file_exists($this->importMarkFile)) {
                error_log("Import already marked, skip: {$targetDbName}");
                return;
            }

            $host = EnvHelper::getDbHost();
            $port = EnvHelper::getDbPort();
            $rootUser = EnvHelper::getBakRoot();
            $rootPass = EnvHelper::getBakPassword();

            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $rootPdo = new \PDO($dsn, $rootUser, $rootPass);

            $stmt = $rootPdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :db LIMIT 1');
            $stmt->execute([':db' => $targetDbName]);
            $exists = (bool)$stmt->fetchColumn();

            if (!$exists) {
                $rootPdo->exec("CREATE DATABASE `{$targetDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                error_log("Database created: {$targetDbName}");
            } else   error_log("Database exists: {$targetDbName}");
            $this->importGzWithExec($targetDbName, $sqlGzPath);

            @file_put_contents($this->importMarkFile, date('c'));
            error_log("Import success: {$targetDbName}");
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function importGzWithExec(string $targetDbName, string $sqlGzPath): void{
        $host = EnvHelper::getDbHost();
        $port = EnvHelper::getDbPort();
        $user = EnvHelper::getBakRoot();
        $pass = EnvHelper::getBakPassword();

        $mysqlBin = '/usr/bin/mysql';
        if (!file_exists($mysqlBin)) throw new \RuntimeException("mysql client not found: {$mysqlBin}");

        $zcatBin = '/usr/bin/zcat';
        if (!file_exists($zcatBin)) $zcatBin = '/bin/zcat';
        if (!file_exists($zcatBin)) throw new \RuntimeException("zcat not found. Please ensure zcat exists on system.");
        $hostArg = '--host=' . escapeshellarg((string)$host);
        $portArg = '--port=' . escapeshellarg((string)$port);
        $dbArg = '--database=' . escapeshellarg((string)$targetDbName);
        $userArg = '--user=' . escapeshellarg((string)$user);
        $passArg = '--password=' . escapeshellarg((string)$pass);
        $cmd = 'bash -c ' . escapeshellarg($zcatBin . ' ' . escapeshellarg($sqlGzPath) . ' | ' . $mysqlBin . ' ' . $hostArg . ' ' . $portArg . ' ' . $dbArg . ' ' . $userArg . ' ' . $passArg);

        error_log("Import start(exec pipe gz) db={$targetDbName}");

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            $tail = !empty($output) ? implode("\n", array_slice($output, -50)) : '';
            throw new \RuntimeException("Import failed(exitCode={$exitCode}) tail={$tail}");
        }

        error_log("Import complete(exec pipe gz) db={$targetDbName}");
    }
}