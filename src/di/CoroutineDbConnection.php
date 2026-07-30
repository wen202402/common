<?php

declare(strict_types=1);
namespace wen202402\common\di;

use PDO;
use Swoole\Coroutine\Channel;
use yii\db\Connection;
//https://jishuzhan.net/article/1982692684713230338
class CoroutineDbConnection extends Connection{
    public int $poolMaxActive = 20;

    public float $poolWaitTimeout = 3.0;

    public bool $enableCoroutinePooling = true;

    /**
     * @var array<string, CoroutineConnectionPool>
     */
    private static array $sharedPools = [];

    /**
     * @var array<string, Channel>
     */
    private static array $poolLocks = [];


    private static bool $shutdownRegistered = false;

    private ?string $poolKey = null;

    private bool $released = false;

    public function open(): void{
        if ($this->pdo !== null) return;
        if (!$this->isPoolingEnabled()) {
            parent::open();
            return;
        }

        $this->pdo = $this->ensurePool()->acquire();
        $this->released = false;

        $this->trigger(self::EVENT_AFTER_OPEN);
    }

    public function close(): void{
        if (!$this->isPoolingEnabled()) {
            parent::close();
            return;
        }

        if ($this->pdo === null) return;

        if (!$this->released) {
            $pdo = $this->pdo;
            $this->released = true;
            parent::close();
            try { $this->ensurePool()->release($pdo);  } catch (\Throwable $e) { \Yii::error('Error releasing connection to pool: ' . $e->getMessage(), __CLASS__); }
        } else     parent::close();

    }

    public function reset(): void{
        if ($this->pdo !== null && !$this->released) $this->close();

        $this->released = false;
        $this->pdo = null;
    }

    private function ensurePool(): CoroutineConnectionPool{
        $key = $this->poolKey ??= $this->buildPoolKey();

        // Register shutdown function on first pool creation as a safety net
        if (!self::$shutdownRegistered) self::registerShutdownHandler();

        if (!isset(self::$sharedPools[$key])) {
            $lock = self::$poolLocks[$key] ??= $this->createPoolLock();
            $token = $lock->pop();
            try {
                if (!isset(self::$sharedPools[$key])) self::$sharedPools[$key] = new CoroutineConnectionPool(fn (): PDO => $this->createPdoForPool(), $this->poolMaxActive, $this->poolWaitTimeout);

            } finally { $lock->push($token); }
        }

        return self::$sharedPools[$key];
    }

    public function getPool(): CoroutineConnectionPool{
        return $this->ensurePool();
    }

    private function buildPoolKey(): string{
        return md5(implode('|', [static::class, (string) $this->dsn, (string) $this->username, (string) $this->charset,]));
    }

    private function createPdoForPool(): PDO{
        $pdo = parent::createPdoInstance();
        $original = $this->pdo;
        $this->pdo = $pdo;
        $this->initConnection();
        $this->pdo = $original;
        return $pdo;
    }

    private function isPoolingEnabled(): bool{
        return $this->enableCoroutinePooling && \Swoole\Coroutine::getCid() >= 0;
    }

    private function createPoolLock(): Channel{
        $lock = new Channel(1);
        $lock->push(true);
        return $lock;
    }


    public static function shutdownAllPools(): void{
        foreach (self::$sharedPools as $pool) {
            try {
                $pool->shutdown();
            } catch (\Throwable $e) {
                // Silently handle shutdown errors
            }
        }
        
        // Close and clear all pool locks
        foreach (self::$poolLocks as $lock) {
            try {
                if ($lock instanceof Channel) $lock->close();

            } catch (\Throwable $e) {
                // Silently handle lock close errors (channel may already be closed)
            }
        }
        
        self::$sharedPools = [];
        self::$poolLocks = [];
    }





    private static function registerShutdownHandler(): void{
        if (self::$shutdownRegistered) return;
        self::$shutdownRegistered = true;
        register_shutdown_function(function (): void {
         if (empty(self::$sharedPools) &&empty(self::$poolLocks)) return;
         try { self::shutdownAllPools(); } catch (\Throwable $e) { error_log('[CoroutineDbConnection] Error in shutdown handler: ' . $e->getMessage()); }

        });
    }
}
