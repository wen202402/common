<?php

namespace wen202402\common\swoole;

use Swoole\Coroutine;

use Swoole\Coroutine\Http\Client;
use Swoole\Coroutine\Channel;
use function Swoole\Coroutine\run;

class SwooleStressTester
{
    public string $targetHost = '127.0.0.1';
    public int $targetPort = 48000;
    public bool $sslEnable = false;

    public int $totalUsers = 20000;
    public int $concurrency = 1000;



    public int $timeoutSec = 10;





    private array $stats = [
        'total' => 0,
        'success' => 0,
        'failed' => 0,
        'steps' => []
    ];

    public function __construct(array $config = []){
        ini_set('memory_limit', '2048M');

        foreach ($config as $k => $v) if (property_exists($this, $k)) $this->$k = $v;



    }

    private function randFloat(float $min, float $max): float{
        $scaledMin = (int)round($min * 1000);
        $scaledMax = (int)round($max * 1000);
        return rand($scaledMin, $scaledMax) / 1000;
    }

    private function baseHeaders(string $virtualIp): array
    {
        return [
            'Host' => $this->targetHost,
            'User-Agent' => 'SwooleStressTester/1.0',
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'api/web',
            'Accept-Language' => 'zh',
            'v' => '1',
            'X-Forwarded-For' => $virtualIp,
            'X-Real-IP' => $virtualIp,
        ];
    }
//        Coroutine::sleep($this->randFloat($this->thinkMinSec, $this->thinkMaxSec));


    public function runUserJourney(Client $client, array $headers, int $userId): array{

        return [];
    }

    private function simulateUserJourney(int $userId, string $virtualIp): array{
        $client = new Client($this->targetHost, $this->targetPort, $this->sslEnable);
        $client->set(['timeout' => $this->timeoutSec, 'keep_alive' => true]);

        try {
            return call_user_func([$this, 'runUserJourney'], $client, $this->baseHeaders($virtualIp), $userId);
        } catch (\Throwable $e) {
            return ['status' => false, 'step' => 'exception', 'code' => $e->getCode(), 'msg' => $e->getMessage()];
        } finally {
            $client->close();
        }
    }

    public function run(): void{

        echo "总模拟用户数: {$this->totalUsers} | 最大并发窗口: {$this->concurrency}" . PHP_EOL;

        $startTime = microtime(true);
        $channel = new Channel($this->concurrency);

        run(function () use ($channel, &$startTime) {
            for ($i = 1; $i <= $this->totalUsers; $i++) {
                $channel->push(true);

                $virtualIp = sprintf("10.%d.%d.%d", ($i >> 16) & 255, ($i >> 8) & 255, $i & 255);

                Coroutine::create(function () use ($i, $virtualIp, $channel) {
                    $result = $this->simulateUserJourney($i, $virtualIp);

                    $this->stats['total']++;

                    if (($result['status'] ?? false) === true) $this->stats['success']++;
                    else {
                        $this->stats['failed']++;
                        $stepKey = ($result['step'] ?? 'unknown') . '_err_' . ($result['code'] ?? 'unknown');
                        $this->stats['steps'][$stepKey] = ($this->stats['steps'][$stepKey] ?? 0) + 1;
                    }

                    $channel->pop();
                });
            }

            while ($channel->stats()['queue_num'] > 0) Coroutine::sleep(0.1);

        });

        $costTime = round(microtime(true) - $startTime, 2);

        echo PHP_EOL . "=== 压测结果汇总 ===" . PHP_EOL;
        echo "总耗时: {$costTime} 秒" . PHP_EOL;
        echo "请求并发/用户数: " . $this->stats['total'] . PHP_EOL;
        echo "成功完成全流程: " . $this->stats['success'] . PHP_EOL;
        echo "失败用户数: " . $this->stats['failed'] . PHP_EOL;

        $qps = $costTime > 0 ? round(($this->stats['total'] * 3) / $costTime, 2) : 0;
        echo "QPS (平均估算): {$qps} req/s" . PHP_EOL;

        if (empty($this->stats['steps'])) return;
        echo PHP_EOL . "失败节点拆分:" . PHP_EOL;
        print_r($this->stats['steps']);

    }
}
