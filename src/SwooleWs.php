<?php
declare(strict_types=1);
namespace wen202402\common;


use Yii;
use JsonException;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;
use Throwable;


class SwooleWs{
    public $host = '0.0.0.0';
    public $port = 38000;
    public $wtime = 300*1000;

    private Server $server;







    public static function getData(): array{
        return [];
    }



    public static function refreshCache(): array{

        return [];
    }

    public $option=[
        'worker_num' => 1,
        'daemonize' => false,
        'enable_coroutine' => false,
        'heartbeat_check_interval' => 30,
        'heartbeat_idle_time' => 75,
    ];
    public function __construct(){

        $this->server = new Server($this->host, $this->port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);

        $this->server->set($this->option);

        $this->registerEvents();
    }

    public function start(): void{
        $this->server->start();
    }









    private function registerEvents(): void{
        $this->server->on('Start', [$this, 'onStart']);
        $this->server->on('WorkerStart', [$this, 'onWorkerStart']);
        $this->server->on('Open', [$this, 'onOpen']);
        $this->server->on('Message', [$this, 'onMessage']);
        $this->server->on('Request', [$this, 'onRequest']);
        $this->server->on('Close', [$this, 'onClose']);
    }












    public function onStart(Server $server): void{
        echo sprintf("WebSocket started: ws://127.0.0.1:%d\n", $this->port);
    }









    public function onWorkerStart(Server $server, int $workerId): void{
        if ($workerId !== 0) return;
        try {
            static::refreshCache();
            echo sprintf("[%s] initial cache refresh success\n", date('Y-m-d H:i:s'));
        } catch (Throwable $e) {
            Yii::error($e, SwooleWs::class.__FUNCTION__);
            echo sprintf("[%s] initial cache refresh failed: %s\n", date('Y-m-d H:i:s'), $e->getMessage());
        }

        Timer::tick($this->wtime, function () use ($server): void {
            try {

                echo sprintf("[%s] refresh success, pushed=%d\n", date('Y-m-d H:i:s'), $count = $this->broadcast($server, ['type' => 'realtime.update', 'data' =>  $data = static::refreshCache(), 'timestamp' => time(),]));
            } catch (Throwable $e) {
                Yii::error($e, SwooleWs::class.__FUNCTION__);
                echo sprintf("[%s] refresh failed: %s\n", date('Y-m-d H:i:s'), $e->getMessage());
            }
        });
    }

    public function onOpen(Server $server, Request $request): void{
        try {
            $this->sendSnapshot($fd = (int)$request->fd);
        } catch (Throwable $e) {

            Yii::error($e, SwooleWs::class.__FUNCTION__);
            $this->sendError($fd);
        }
    }

    public function onMessage(Server $server, Frame $frame): void{
        try {

            $type = is_array($message = json_decode($frame->data, true, 512, JSON_THROW_ON_ERROR)) ? (string)($message['type'] ?? '') : '';
            if ($type === 'ping') {
                $this->send($server, $frame->fd, ['type' => 'pong', 'timestamp' => time(),]);
                return;
            }

            if ($type === 'realtime.get') {
                $this->sendSnapshot( $frame->fd);
                return;
            }

            $this->sendError($frame->fd);
        } catch (JsonException $e) {
            $this->sendError($frame->fd);
        } catch (Throwable $e) {
            Yii::error($e, SwooleWs::class.__FUNCTION__);
            $this->sendError($frame->fd);
        }
    }



    public function sendError($fd){
        $this->send($this->server, $fd, ['type' => 'message.error', 'message' => '消息处理失败', 'timestamp' => time(),]);
    }
    public function sendSnapshot($fd){
        $this->send($this->server, $fd, ['type' => 'realtime.snapshot', 'data' => static::getData(), 'timestamp' => time(),]);

    }



    public function onRequest(Request $request, Response $response): void{
        $path = (string)($request->server['request_uri'] ?? '/');

        $response->header('Content-Type', 'application/json; charset=utf-8');

        if ($path === '/health') {
            $response->end($this->encode(['code' => 0, 'msg' => 'ok', 'timestamp' => time()]));

            return;
        }

        if ($path === '/data') {
            try {
                $response->end($this->encode(new Result(0, 'success', static::getData())));
            } catch (Throwable $e) {
                Yii::error($e, SwooleWs::class.__FUNCTION__);
                $response->status(500);
                $response->end($this->encode(new Result(500, '实时数据加载失败', static::getData())));
            }

            return;
        }

        $response->status(404);
        $response->end($this->encode(new Result(404, 'Not Found')));

    }




    public function onClose(Server $server, int $fd, int $reactorId): void{
        echo sprintf("client closed: fd=%d, reactorId=%d\n", $fd, $reactorId);
    }






    private function send(Server $server, int $fd, array $payload): bool{
        if (!$server->isEstablished($fd)) return false;
        return $server->push($fd, $this->encode($payload));
    }

    private function broadcast(Server $server, array $payload): int{
        $message = $this->encode($payload);
        $count = 0;

        foreach ($server->connections as $fd) {
            $fd = (int)$fd;

            if (!$server->isEstablished($fd)) continue;
            if ($server->push($fd, $message)) $count++;
        }

        return $count;
    }

    private function encode(mixed $data): string{
        if (is_object($data)) $data = get_object_vars($data);
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }


}







