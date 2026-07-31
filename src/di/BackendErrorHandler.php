<?php

namespace wen202402\common\di;


use Error;
use Exception;
use Throwable;
use Yii;
use yii\base\ErrorException;
use yii\base\InvalidRouteException;
use yii\base\UserException;
use yii\console\Controller;
use yii\console\UnknownCommandException;

use yii\helpers\Console;
use yii\helpers\VarDumper;

use yii\web\Response;



class BackendErrorHandler extends \yii\web\ErrorHandler{
    public $exception;

    /**
     * @inheritDoc
     * @throws Exception
     */
    public function handleException($exception){
        $this->exception = $exception;
        try {
            $this->logException($exception);
            if ($this->discardExistingOutput) $this->clearOutput();
            $this->renderException($exception);
            if (!YII_ENV_TEST) Yii::getLogger()->flush(true);

        } catch (Exception $e) {
            $this->handleFallbackExceptionMessage($e, $exception);
        } catch (Throwable $e) {

            $this->handleFallbackExceptionMessage($e, $exception);
        }

        $this->exception = null;
    }

    /**
     * @inheritDoc
     * @throws Exception
     */
    protected function handleFallbackExceptionMessage($exception, $previousException)
    {
        $msg = "An Error occurred while handling another error:\n";
        $msg .= (string)$exception;
        $msg .= "\nPrevious exception:\n";
        $msg .= (string)$previousException;
        if (YII_DEBUG) {
            if (PHP_SAPI === 'cli') {
                echo $msg . "\n";
            } else {
                echo '<pre>' . htmlspecialchars($msg, ENT_QUOTES, Yii::$app->charset) . '</pre>';
            }
        } else {
            echo 'An internal server error occurred.';
        }
        $msg .= "\n\$_SERVER = " . VarDumper::export($_SERVER);
        throw new Exception($msg);
    }

    /**
     * @param int $code
     * @param string $message
     * @param string $file
     * @param int $line
     * @return bool
     * @throws ErrorException
     * @throws Exception
     */
    public function handleError($code, $message, $file, $line)
    {
        if (error_reporting() & $code) {
            if (!class_exists('yii\\base\\ErrorException', false)) require_once Yii::getAlias('@yii/base/ErrorException.php');

            $exception = new ErrorException($message, $code, $code, $file, $line);
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            array_shift($trace);
            foreach ($trace as $frame) {
                if ($frame['function'] === '__toString') $this->handleException($exception);

            }

            throw $exception;
        }

        return false;
    }

    /**
     * @throws InvalidRouteException
     * @throws \yii\console\Exception
     */
    public function handleFatalError(){
        if (!class_exists('yii\\base\\ErrorException', false)) require_once Yii::getAlias('@yii/base/ErrorException.php');
        if (!ErrorException::isFatalError($error = error_get_last())) return;
        $this->logException(  $this->exception =   $exception = new ErrorException($error['message'], $error['type'], $error['type'], $error['file'], $error['line']));
        if ($this->discardExistingOutput) $this->clearOutput();
        $this->renderException($exception);
        Yii::getLogger()->flush(true);

    }

    /**
     * @param Error|Exception $exception
     * @throws InvalidRouteException
     * @throws \yii\console\Exception
     */
    protected function renderException($exception){
        if (!Yii::$app->has('response') || Yii::$app->response->getResponse() == null) $this->renderConsoleException($exception);
         else        $this->renderWebException($exception);

    }

    /**
     * Web环境异常渲染
     * @param Exception $exception
     * @throws InvalidRouteException
     * @throws \yii\console\Exception
     */
    protected function renderWebException($exception){
        $response = Yii::$app->getResponse();

        $response->isSent = false;
        $response->stream = null;
        $response->data = null;
        $response->content = null;

        $response->setStatusCodeByException($exception);

        if (( $useErrorView = $response->format === Response::FORMAT_HTML && (!YII_DEBUG || $exception instanceof UserException)) && $this->errorAction !== null) {
            $result = Yii::$app->runAction($this->errorAction);
            if ($result instanceof Response) $response = $result; else   $response->data = $result;

        } elseif ($response->format === Response::FORMAT_HTML) {
            if ($this->shouldRenderSimpleHtml()) $response->data = '<pre>' . $this->htmlEncode(static::convertExceptionToString($exception)) . '</pre>';                // AJAX request
             else {
                if (YII_DEBUG) ini_set('display_errors', 1);
                $file = $useErrorView ? $this->errorView : $this->exceptionView;
                $response->data = $this->renderFile($file, ['exception' => $exception,]);
            }
        } elseif ($response->format === Response::FORMAT_RAW) $response->data = static::convertExceptionToString($exception);
         else    $response->data = $this->convertExceptionToArray($exception);


        $response->send();
    }

    /**
     * Console环境异常渲染
     * @param Exception $exception
     */
    protected function renderConsoleException($exception)
    {
        if ($exception instanceof UnknownCommandException) {
            // display message and suggest alternatives in case of unknown command
            $message = $this->formatMessage($exception->getName() . ': ') . $exception->command;
            $alternatives = $exception->getSuggestedAlternatives();
            if (count($alternatives) === 1) {
                $message .= "\n\nDid you mean \"" . reset($alternatives) . '"?';
            } elseif (count($alternatives) > 1) {
                $message .= "\n\nDid you mean one of these?\n    - " . implode("\n    - ", $alternatives);
            }
        } elseif ($exception instanceof \yii\console\Exception && ($exception instanceof UserException || !YII_DEBUG)) {
            $message = $this->formatMessage($exception->getName() . ': ') . $exception->getMessage();
        } elseif (YII_DEBUG) {
            if ($exception instanceof Exception) {
                $message = $this->formatMessage("Exception ({$exception->getName()})");
            } elseif ($exception instanceof ErrorException) {
                $message = $this->formatMessage($exception->getName());
            } else {
                $message = $this->formatMessage('Exception');
            }
            $message .= $this->formatMessage(" '" . get_class($exception) . "'", [Console::BOLD, Console::FG_BLUE])
                . ' with message ' . $this->formatMessage("'{$exception->getMessage()}'", [Console::BOLD]) //. "\n"
                . "\n\nin " . dirname($exception->getFile()) . DIRECTORY_SEPARATOR . $this->formatMessage(basename($exception->getFile()),
                    [Console::BOLD])
                . ':' . $this->formatMessage($exception->getLine(), [Console::BOLD, Console::FG_YELLOW]) . "\n";
            if ($exception instanceof \yii\db\Exception && !empty($exception->errorInfo)) {
                $message .= "\n" . $this->formatMessage("Error Info:\n", [Console::BOLD]) . print_r($exception->errorInfo, true);
            }
            $message .= "\n" . $this->formatMessage("Stack trace:\n", [Console::BOLD]) . $exception->getTraceAsString();
        } else {
            $message = $this->formatMessage('Error: ') . $exception->getMessage();
        }

        if (PHP_SAPI === 'cli') {
            Console::stderr($message . "\n");
        } else {
            echo $message . "\n";
        }
    }

    /**
     * Colorizes a message for console output.
     * @param string $message the message to colorize.
     * @param array $format the message format.
     * @return string the colorized message.
     * @see Console::ansiFormat() for details on how to specify the message format.
     */
    protected function formatMessage($message, $format = [Console::FG_RED, Console::BOLD]){
        $stream = (PHP_SAPI === 'cli') ? STDERR : STDOUT;
        // try controller first to allow check for --color switch
        if (Yii::$app->controller instanceof Controller && Yii::$app->controller->isColorEnabled($stream)
            || Yii::$app instanceof \yii\console\Application && Console::streamSupportsAnsiColors($stream)) {
            $message = Console::ansiFormat($message, $format);
        }

        return $message;
    }
}