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
        $this->checkdbImportDB($this->targetDbName, $this->sqlGzPath);
        error_log('DB init done.');
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
            error_log('Yii-queue worker start...');
            System::exec('/usr/bin/php ' . $this->rootPath . '/cmd/queue' . ' >> ' . escapeshellarg('/tmp/yii-queue-' . date('Y-m-d') . '.log') . ' 2>&1');
        } catch (\Throwable $e) {
            @file_put_contents('/tmp/yii-queue-' . date('Y-m-d') . 'error..log', '[' . date('Y-m-d H:i:s') . '] queue worker start failed: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }
    }






    public function startVariable(){
        try {
            error_log(__FUNCTION__.' timer start: ' . date('Y-m-d H:i:s'));
            $exitCode = $this->app->runAction('cron/contrab/variable');
            error_log('variable exitCode: ' . $exitCode);
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





    private function checkdbImportDB(string $targetDbName, string $sqlGzPath, bool $force = false): bool{
        if (!file_exists($sqlGzPath)) {
            error_log("sql.gz not found: {$sqlGzPath}");
            return false;
        }

        if (!($fp = fopen($this->lockFile, 'c')))
            throw new \RuntimeException("Cannot open lock file: {$this->lockFile}");


        flock($fp, LOCK_EX);
        try {
            if (file_exists($this->importMarkFile) && !$force) {
                error_log("Import already marked, skip: {$this->importMarkFile}" . "If you need to re-import into the database, please delete it rm -f {$this->importMarkFile}");
                return true;
            }

            $rootPdo = $this->createRootPdo();
            $stmt = $rootPdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :db LIMIT 1');
            $stmt->execute([':db' => $targetDbName]);
            $exists = (bool)$stmt->fetchColumn();

            if (!$exists) {
                $rootPdo->exec("CREATE DATABASE `{$targetDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                error_log("Database created: {$targetDbName}");
               $this->importDatabase($targetDbName, $sqlGzPath);
                error_log("Import success: {$targetDbName}");
            } else {
             /*   if ($force) {
                    $rootPdo->exec("DROP DATABASE `{$targetDbName}`");
                    $rootPdo->exec("CREATE DATABASE `{$targetDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    error_log("Database dropped and recreated: {$targetDbName}");
                } else  */
                if ($force)    {
                    $this->importDatabase($targetDbName, $sqlGzPath);
                    error_log("Import success: {$targetDbName}");
                }
                else         error_log("Database exists skip : {$targetDbName}");
            }

            $this->ensureUserAndGrantAll( $targetDbName, (string)EnvHelper::getDbUsername(), (string)EnvHelper::getDbPassword(), '%');

            @file_put_contents($this->importMarkFile, date('c'));
            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }




    private function importDatabase(string $targetDbName, string $sqlGzPath): bool{
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
        error_log("Import start(exec pipe gz) db={$targetDbName}");

        if (false!==($exitCode = System::exec($cmd)) ) return     error_log("Import complete(exec pipe gz) db={$targetDbName}");
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
