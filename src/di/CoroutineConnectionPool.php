<?php

declare(strict_types=1);

namespace wen202402\common\di;

use PDO;
use RuntimeException;
use Swoole\Coroutine\Channel;
use yii\base\InvalidConfigException;

final class CoroutineConnectionPool
{
    private Channel $channel;
    private int $maxActive;
    private float $waitTimeout;
    /** @var callable */
    private $factory;

    public function __construct(callable $factory, int $maxActive, float $waitTimeout)
    {
        if ($maxActive < 1) {
            throw new InvalidConfigException('"poolMaxActive" must be greater than or equal to 1.');
        }

        $this->factory = $factory;
        $this->maxActive = $maxActive;
        $this->waitTimeout = $waitTimeout;
        $this->channel = new Channel($maxActive);

        try {
            for ($i = 0; $i < $this->maxActive; $i++) {
                $this->pushConnection($this->createConnection());
            }
        } catch (\Throwable $exception) {
            $this->drainPool();
            throw $exception;
        }
    }

    private function inCoroutine(): bool
    {
        if (!class_exists(\Swoole\Coroutine::class)) {
            return false;
        }

        // Swoole 常见写法：cid > 0 表示在协程内
        return (int) \Swoole\Coroutine::getCid() > 0;
    }

    public function acquire(): PDO
    {
        // 非协程：不要调用 Channel 协程 API，否则会触发
        // "API must be called in the coroutine"
        if (!$this->inCoroutine()) {
            return $this->createConnection();
        }

        $connection = $this->channel->pop($this->waitTimeout);

        if ($connection instanceof PDO) {
            return $connection;
        }

        if ($connection === false) {
            // Timeout or channel closed - pool exhausted
            $stats = $this->channel->stats();

            throw new RuntimeException(
                sprintf(
                    'Database connection pool exhausted. Max active: %d, idle: %d, waiting consumers: %d',
                    $this->maxActive,
                    (int)($stats['queue_num'] ?? 0),
                    (int)($stats['consumer_num'] ?? 0)
                )
            );
        }

        // Connection is null or invalid - create a new one
        return $this->createConnection();
    }

    public function release(PDO $connection): void
    {
        // 非协程：不要 push 回 Channel（避免再次触发协程 API）
        if (!$this->inCoroutine()) {
            $this->closeConnection($connection);
            return;
        }

        $this->pushConnection($connection);
    }

    private function closeConnection(PDO $connection): void
    {
        // PDO connections close automatically when all references are destroyed
        unset($connection);
    }

    /**
     * @return array{created:int,idle:int,in_use:int,waiters:int,capacity:int}
     */
    public function getStats(): array
    {
        $stats = $this->channel->stats();

        return [
            'created' => $this->maxActive,
            'idle' => (int)($stats['queue_num'] ?? 0),
            'in_use' => max(0, $this->maxActive - (int)($stats['queue_num'] ?? 0)),
            'waiters' => (int)($stats['consumer_num'] ?? 0),
            'capacity' => $this->maxActive,
        ];
    }

    private function createConnection(): PDO
    {
        try {
            /** @var PDO $connection */
            $connection = ($this->factory)();
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RuntimeException('Failed to create a database connection for the pool.', 0, $exception);
        }

        return $connection;
    }

    private function pushConnection(PDO $connection): void
    {
        if (!$this->channel->push($connection, 0.0)) {
            $this->closeConnection($connection);
            throw new RuntimeException('Database connection pool channel is closed.');
        }
    }

    private function drainPool(): void
    {
        // 非协程：不能 pop()
        if (!$this->inCoroutine()) {
            return;
        }

        // Get current pool size to avoid infinite loop
        try {
            $stats = $this->channel->stats();
            $count = $stats['queue_num'] ?? 0;
        } catch (\Throwable $e) {
            return;
        }

        for ($i = 0; $i < $count; $i++) {
            $connection = $this->channel->pop(0.01);

            if ($connection === false || !($connection instanceof PDO)) {
                break;
            }

            try {
                $this->closeConnection($connection);
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * Gracefully shuts down the connection pool
     * Closes all connections and the channel
     */
    public function shutdown(): void
    {
        // 非协程：不要 drain/close Channel，避免协程 API 报错
        if (!$this->inCoroutine()) {
            return;
        }

        try {
            $this->drainPool();
        } catch (\Throwable $e) {
            // ignore
        }

        try {
            $this->channel->close();
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
