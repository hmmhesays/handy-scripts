<?php

class ErrorHandler
{
    private $logHandler;
    private $displayErrors;

    public function __construct(callable $logHandler, $displayErrors = false)
    {
        $this->logHandler = $logHandler;
        $this->displayErrors = $displayErrors;
        
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
    }

    public function handleError($errno, $errstr, $errfile, $errline)
    {
        $error = $this->formatError($errno, $errstr, $errfile, $errline);
        $this->logError($error);
        
        if ($this->displayErrors) {
            $this->displayError($error);
        }
    }

    public function handleException($exception)
    {
        $error = $this->formatException($exception);
        $this->logError($error);
        
        if ($this->displayErrors) {
            $this->displayError($error);
        }
    }

    private function formatError($errno, $errstr, $errfile, $errline)
    {
        $errorType = $this->getErrorType($errno);
        $trace = $this->getAbbreviatedTrace(debug_backtrace());
        
        return [
            'timestamp' => date('Y-m-d\TH:i:s.uP'),
            'type' => $errorType,
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'trace' => $trace
        ];
    }

    private function formatException($exception)
    {
        $trace = $this->getAbbreviatedTrace($exception->getTrace());
        
        return [
            'timestamp' => date('Y-m-d\TH:i:s.uP'),
            'type' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $trace
        ];
    }

    private function getErrorType($errno)
    {
        $errorTypes = [
            E_ERROR => 'Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            // Add more error types as needed
        ];

        return $errorTypes[$errno] ?? 'Unknown Error';
    }

    private function getAbbreviatedTrace($trace, $limit = 5)
    {
        $abbreviated = array_slice($trace, 0, $limit);
        return array_map(function ($item) {
            return [
                'file' => $item['file'] ?? 'unknown',
                'line' => $item['line'] ?? 'unknown',
                'function' => $item['function'] ?? 'unknown'
            ];
        }, $abbreviated);
    }

    private function logError($error)
    {
        $jsonError = json_encode($error);
        call_user_func($this->logHandler, $jsonError);
    }

    private function displayError($error)
    {
        header('Content-Type: application/json');
        echo json_encode($error, JSON_PRETTY_PRINT);
    }
}
