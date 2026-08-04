<?php

namespace wen202402\common\swoole;

use Swoole\Coroutine;
use Swoole\Coroutine\System;
use wen202402\common\helper\EnvHelper;
use yii\console\Application;
use yii\helpers\ArrayHelper;

class SwooleTask{


    private string $rootPath;
    public bool $force=false;
    public string $libsPath;
    public string $targetDbName = '';
    public string $sqlGzPath;
    private string $lockFile;
    private string $importMarkFile;
    private Application $app;

    public function __construct($rootPath){
        $this->rootPath = $rootPath;
        $this->libsPath = $this->rootPath . DIRECTORY_SEPARATOR;
        $this->targetDbName = EnvHelper::getDbName();
        $this->sqlGzPath =  $this->libsPath.  $this->targetDbName.'.sql.gz';
        $this->lockFile =$this->libsPath . '.init.lock';
        $this->importMarkFile = $this->libsPath. ".import_mark";
    }




    public function run(): void{
        \Swoole\Runtime::enableCoroutine(true);

        Coroutine::create([$this, 'importDbAndStartYiiQueue']);
        $config = ArrayHelper::merge(
            require $this->libsPath . 'common/config/main.php',
            require $this->libsPath . 'common/config/main-local.php',
            require $this->libsPath . 'console/config/main.php',
            require $this->libsPath . 'console/config/main-local.php'
        );

        $this->app = new Application($config);
        swoole_timer_tick(15 * 60 * 1000, function ()  {Coroutine::create([$this,'startRibao']);});
        swoole_timer_tick(6 * 60 * 1000, function ()   { Coroutine::create([$this,'startVariable']);});
        swoole_event_wait();
    }





                                                                                                     // $tmpDir = $this->libsPath . 'console/runtime/tmp/';        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
    public function importDbAndStartYiiQueue(){
        try {
            $this->checkdbImportDB($this->targetDbName, $this->sqlGzPath,$this->force);
            error_log(__FUNCTION__.'--------------DB init done.');
            error_log(__FUNCTION__.'---------------Yii-queue worker start...');
            System::exec('/usr/bin/php ' . $this->rootPath . '/cmd/queue' . ' >> ' . escapeshellarg('/tmp/yii-queue-' . date('Y-m-d') . '.log') . ' 2>&1');
        } catch (\Throwable $e) {
            @file_put_contents('/tmp/yii-queue-' . date('Y-m-d') . 'error..log', '[' . date('Y-m-d H:i:s') . '] queue worker start failed: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }
    }






    public function startVariable(){
        try {
            error_log(__FUNCTION__.' timer start: ' . date('Y-m-d H:i:s'));
            $exitCode = $this->app->runAction('cron/contrab/variable');
            error_log(__FUNCTION__.'variable exitCode: ' . $exitCode);
        } catch (\Throwable $e) {
            error_log('variable error: ' . $e->getMessage());
            error_log($e->getTraceAsString());
        }
    }





    public function startRibao(){
        try {
            error_log(__FUNCTION__.' timer start: ' . date('Y-m-d H:i:s'));
            $exitCode = $this->app->runAction('cron/contrab/ribao',['order']);
        } catch (\Throwable $e) {
            error_log('ribao error: ' . $e->getMessage());
            error_log($e->getTraceAsString());
        }
    }



    private function createRootPdo(): \PDO{
        $host = EnvHelper::getDbHost();
        $port = EnvHelper::getDbPort();
        return new \PDO("mysql:host={$host};port={$port};charset=utf8mb4", EnvHelper::getBakRoot(), EnvHelper::getBakPassword(), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,]);
    }


    private function createDatabase($targetDbName){
        $stmt = ($rootPdo = $this->createRootPdo())->prepare('select 1 from information_schema.SCHEMATA WHERE SCHEMA_NAME = :db limit 1');
        $stmt->execute([':db' => $targetDbName]);
        if (($exists = (bool)$stmt->fetchColumn())) return $exists;
        $rootPdo->exec("create database `{$targetDbName}` character set utf8mb4 collate utf8mb4_unicode_ci");
        error_log("database created: {$targetDbName}");
        return $exists;
    }




    private function checkdbImportDB(string $targetDbName, string $sqlGzPath, bool $force = false): bool{
        if (!file_exists($sqlGzPath)) return   error_log("sql.gz not found: {$sqlGzPath}");
        if (!($fp = fopen($this->lockFile, 'c')))     throw new \RuntimeException("Cannot open lock file: {$this->lockFile}");
        flock($fp, LOCK_EX);
        try {
            if (!$force && file_exists($this->importMarkFile)) return  error_log("import already marked, skip: {$this->importMarkFile} (rm -f {$this->importMarkFile} to re-import)");
            $this->importDatabase($targetDbName, $sqlGzPath,  $exists=$this->createDatabase($targetDbName),$force);
            $this->ensureUserAndGrantAll($targetDbName, (string)EnvHelper::getDbUsername(), (string)EnvHelper::getDbPassword(), '%');

            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }


    private function importDatabase(string $targetDbName, string $sqlGzPath,bool $exists,bool $force=false): bool{
        if ($exists && !$this->force) return error_log(__FUNCTION__."------Database exists skip : {$targetDbName}");
        $host = EnvHelper::getDbHost();
        $port = EnvHelper::getDbPort();
        $user = EnvHelper::getBakRoot();
        $pass = EnvHelper::getBakPassword();
        if (!file_exists($mysqlBin = '/usr/bin/mysql'))                         throw new \RuntimeException("mysql client not found: {$mysqlBin}");
        if (!file_exists($zcatBin = '/usr/bin/zcat')) $zcatBin = '/bin/zcat';
        if (!file_exists($zcatBin))                                             throw new \RuntimeException("/usr/bin/zcat and /bin/zcat not found.  sudo apt-get install -y gzip");
        $hostArg = '--host=' . escapeshellarg((string)$host);
        $portArg = '--port=' . escapeshellarg((string)$port);
        $dbArg = '--database=' . escapeshellarg((string)$targetDbName);
        $userArg = '--user=' . escapeshellarg((string)$user);
        $passArg = '--password=' . escapeshellarg((string)$pass);
        $cmd = 'bash -c ' . escapeshellarg($zcatBin . ' ' . escapeshellarg($sqlGzPath) . ' | ' . $mysqlBin . ' ' . $hostArg . ' ' . $portArg . ' ' . $dbArg . ' ' . $userArg . ' ' . $passArg);
        error_log("Import starting db={$targetDbName}");

        if (false!==($exitCode = System::exec($cmd)) ) {
            error_log(__FUNCTION__."-----------------Import success  db={$targetDbName}");
            @file_put_contents($this->importMarkFile, date('c'));
            return true;
        }

        throw new \RuntimeException("Import failed tail=".$tail = !empty($exitCode) ? implode("\n", array_slice($exitCode, -50)) : '');


    }














    private function ensureUserAndGrantAll(string $dbName, string $user, string $pass, string $hostPattern = '%'): void{
        ( $stmt = ($rootPdo = $this->createRootPdo())->prepare("SELECT 1 FROM mysql.user WHERE User = :user AND Host = :host LIMIT 1"))->execute([':user' => $user, ':host' => $hostPattern]);
        $exists = (bool)$stmt->fetchColumn();
        $quotedUser = $rootPdo->quote($user);                                                                            // 'user'
        $quotedHost = $rootPdo->quote($hostPattern);                                                                  // '%'
        $userHost = "{$quotedUser}@{$quotedHost}";                                                                    // 'user'@'%'

        if (!$exists) {
            $quotedPass = $rootPdo->quote($pass);
            $createSql = "CREATE USER {$userHost} IDENTIFIED BY {$quotedPass}";
            $rootPdo->exec($createSql);
            error_log("MySQL user created: {$user}@{$hostPattern}");
        } else  error_log("MySQL user exists: {$user}@{$hostPattern}");


        $rootPdo->exec( "GRANT ALL PRIVILEGES ON `{$dbName}`.* TO {$userHost}");
        $rootPdo->exec("FLUSH PRIVILEGES");

        error_log("Granted ALL on {$dbName} to {$user}@{$hostPattern}");
    }
}
