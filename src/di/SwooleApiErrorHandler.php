<?php
namespace wen202402\common\di;


use Yii;


use yii\base\ErrorException;

use yii\base\ExitException;
use yii\helpers\VarDumper;



class SwooleApiErrorHandler extends \yii\web\ErrorHandler{


    private $_memoryReserve;


    public function handleError($code, $message, $file, $line){
        if (error_reporting() & $code) {
            // load ErrorException manually here because autoloading them will not work
            // when error occurs while autoloading a class
            if (!class_exists('yii\\base\\ErrorException', false)) {
                require_once __DIR__ . '/ErrorException.php';
            }
            $exception = new ErrorException($message, $code, $code, $file, $line);

            if (PHP_VERSION_ID < 70400) {
                // prior to PHP 7.4 we can't throw exceptions inside of __toString() - it will result a fatal error
                $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                array_shift($trace);
                foreach ($trace as $frame) {
                    if ($frame['function'] === '__toString') {
                        $this->handleException($exception);
                        if (defined('HHVM_VERSION')) {
                            flush();
                        }
                      //  exit(1);
                    }
                }
            }

            throw $exception;
        }

        return false;
    }

    /**
     * Handles fatal PHP errors.
     */
    public function handleFatalError()
    {
        $this->_memoryReserve = null;

        if (!empty($this->_workingDirectory)) {
            // fix working directory for some Web servers e.g. Apache
            chdir($this->_workingDirectory);
            // flush memory
            $this->_workingDirectory = null;
        }

        $error = error_get_last();
        if ($error === null) {
            return;
        }

        // load ErrorException manually here because autoloading them will not work
        // when error occurs while autoloading a class
       // if (!class_exists('yii\\base\\ErrorException', false)) require_once __DIR__ . '/ErrorException.php';

        if (!ErrorException::isFatalError($error)) {
            return;
        }

        if (!empty($this->_hhvmException)) {
            $this->exception = $this->_hhvmException;
        } else {
            $this->exception = new ErrorException(
                $error['message'],
                $error['type'],
                $error['type'],
                $error['file'],
                $error['line']
            );
        }
        unset($error);

        $this->logException($this->exception);

        if ($this->discardExistingOutput) {
            $this->clearOutput();
        }
        $this->renderException($this->exception);

        // need to explicitly flush logs because exit() next will terminate the app immediately
        Yii::getLogger()->flush(true);
        if (defined('HHVM_VERSION')) {
            flush();
        }

       // $this->trigger(static::EVENT_SHUTDOWN);

        // ensure it is called after user-defined shutdown functions
      //  register_shutdown_function(function () {exit(1);});
    }


    protected function handleFallbackExceptionMessage($exception, $previousException)
    {
        $msg = "An Error occurred while handling another error:\n";
        $msg .= (string) $exception;
        $msg .= "\nPrevious exception:\n";
        $msg .= (string) $previousException;
        if (YII_DEBUG) {
            if (PHP_SAPI === 'cli') {
                error_log($msg . "\n") ;
            } else   echo '<pre>' . htmlspecialchars($msg, ENT_QUOTES, Yii::$app->charset) . '</pre>';

            $msg .= "\n\$_SERVER = " . VarDumper::export($_SERVER);
        } else {
            error_log('An internal server error occurred.');
        }
        error_log($msg);
        if (defined('HHVM_VERSION')) flush();

      //  exit(1);
    }



    public function handleException($exception)
    {
        if ($exception instanceof ExitException) {
            return;
        }

        $this->exception = $exception;

        $this->unregister();

        if (PHP_SAPI !== 'cli') http_response_code(500);


        try {
            $this->logException($exception);
            if ($this->discardExistingOutput) $this->clearOutput();

            $this->renderException($exception);
            if (!$this->silentExitOnException) {
                \Yii::getLogger()->flush(true);
                if (defined('HHVM_VERSION')) flush();

               // exit(1);
            }
        } catch (\Exception $e) {
            $this->handleFallbackExceptionMessage($e, $exception);
        } catch (\Throwable $e) {
            $this->handleFallbackExceptionMessage($e, $exception);
        }

        $this->exception = null;
    }



}