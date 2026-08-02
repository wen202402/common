<?php

namespace wen202402\common\di;

use Swoole\Coroutine;
use Yii;
use yii\base\ExitException;
use yii\base\InvalidRouteException;
use yii\helpers\Url;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\UrlNormalizerRedirectException;

class Application extends \yii\web\Application{
    public function __construct($config = []){
        parent::__construct($config);
        Coroutine::getCid() > 0 ? Coroutine::getContext()->app = $this : Yii::$app = $this;
    }

    public function run()
    {
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

            $this->end($e->statusCode, $response ?? null);

            if ($response instanceof \yii\web\Response) {
                $response->send();
            }

            return $e->statusCode;
        } catch (\yii\web\HttpException $e) {

            $this->state = self::STATE_AFTER_REQUEST;

            if ($this->has('errorHandler')) {
                $handler = $this->get('errorHandler');
                $handler->handleException($e);
            } else {
                Yii::getLogger()->logException($e);
            }

            return (int)$e->statusCode;
        } catch (\Throwable $e) {

            if ($this->has('errorHandler')) {
                $handler = $this->get('errorHandler');
                $handler->handleException($e);
            } else {
                Yii::getLogger()->logException($e);
            }

            return 500;
        } finally {
            if ($cid > 0) {
                Coroutine::getContext()->app = null;
            }
        }
    }





    public function handleRequest($request){
        if (empty($this->catchAll)) {
            try {
                list($route, $params) = $request->resolve();
            } catch (UrlNormalizerRedirectException $e) {
                $url = $e->url;
                if (is_array($url)) {
                    if (isset($url[0])) { $url[0] = '/' . ltrim($url[0], '/'); }
                    $url += $request->getQueryParams();
                }

                return $this->getResponse()->redirect(Url::to($url, $e->scheme), $e->statusCode);
            }
        } else {
            $route = $this->catchAll[0];
            $params = $this->catchAll;
            unset($params[0]);
        }
        try {
            Yii::debug("Route requested: '$route'", __METHOD__);
            $this->requestedRoute = $route;
            if (($result = $this->runAction($route, $params)) instanceof Response) return $result;
            $response = $this->getResponse();
            if ($result !== null) $response->data = $result;
            return $response;
        } catch (InvalidRouteException $e) {
            throw new NotFoundHttpException(Yii::t('yii', 'Page not found.'), $e->getCode(), $e);
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
