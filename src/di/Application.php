<?php

namespace wen202402\common\di;

use Swoole\Coroutine;
use Yii;
use yii\base\ExitException;

class Application extends \yii\web\Application{


    public function __construct($config = []){
        parent::__construct($config);
        Coroutine::getCid()>0? Coroutine::getContext()->app=$this: Yii::$app=$this;
    }

    public function run(){
        $response = null;

        $cid = Coroutine::getCid();
        try {
            $this->state = self::STATE_BEFORE_REQUEST;
            $this->trigger(self::EVENT_BEFORE_REQUEST);

            $this->state = self::STATE_HANDLING_REQUEST;
            $response = $this->handleRequest($this->getRequest());

            $this->state = self::STATE_AFTER_REQUEST;
            $this->trigger(self::EVENT_AFTER_REQUEST);

            $this->state = self::STATE_SENDING_RESPONSE;
            $response->send();

            $this->state = self::STATE_END;

            return $response->exitStatus;
        } catch (ExitException $e) {

            $this->end($e->statusCode, $response ?? null);                                // 结束当前请求，不要让异常继续冒泡到 Swoole worker 级别

            if ($response instanceof \yii\web\Response) $response->send();// 有些分支 end() 可能不触发 send，这里兜底


            return $e->statusCode;
        } catch (\yii\web\HttpException $e) {

            $this->exception = $e;
            $this->state = self::STATE_AFTER_REQUEST;         // 404/403/500 等都应当走 errorHandler 渲染并 send 响应，然后正常 return

            if ($this->has('errorHandler')) {
                /** @var \yii\web\ErrorHandler $handler */
                $handler = $this->get('errorHandler');
                $handler->handleException($e);
            } else      Yii::getLogger()->logException($e);            // 没有 errorHandler 就至少别让 worker 崩


            // handler 通常会 send；这里只返回状态码
            return (int)$e->statusCode;
        } catch (\Throwable $e) {
            // 最兜底：避免 worker 因未捕获异常直接退出
            $this->exception = $e;

            if ($this->has('errorHandler')) {
                $handler = $this->get('errorHandler');
                $handler->handleException($e);
            } else {
                Yii::getLogger()->logException($e);
            }

            return 500;
        } finally {

            if ($cid > 0)   Coroutine::getContext()->app = null;

        }
    }


    public function end($status = 0, $response = null){
        if ($this->state === self::STATE_BEFORE_REQUEST || $this->state === self::STATE_HANDLING_REQUEST) {
            $this->state = self::STATE_AFTER_REQUEST;
            $this->trigger(self::EVENT_AFTER_REQUEST);
        }

        if ($this->state !== self::STATE_SENDING_RESPONSE && $this->state !== self::STATE_END) {
            $this->state = self::STATE_END;
            $response = $response ?: $this->getResponse();
            $response->send();
        }

        if (YII_ENV_TEST) throw new ExitException($status);


      return $status;
    }
}