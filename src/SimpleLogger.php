<?php

declare(strict_types=1);

namespace Tinywan\RedisStream;

/**
 * Simple logger class to replace PSR-3 logger
 */
class SimpleLogger
{
    private string $channel;
    private ?string $logPath;
    
    public function __construct(string $channel = 'redis-stream', string $logPath = null)
    {
        $this->channel = $channel;
        $this->logPath = $logPath;
    }
    
    public function emergency($message, array $context = []): void
    {
        $this->log('EMERGENCY', $message, $context);
    }
    
    public function alert($message, array $context = []): void
    {
        $this->log('ALERT', $message, $context);
    }
    
    public function critical($message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }
    
    public function error($message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }
    
    public function warning($message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }
    
    public function notice($message, array $context = []): void
    {
        $this->log('NOTICE', $message, $context);
    }
    
    public function info($message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }
    
    public function debug($message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }
    
    public function log($level, $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        
        $logMessage = sprintf(
            "[%s] [%s.%s] %s%s\n",
            $timestamp,
            $this->channel,
            $level,
            $message,
            $contextStr
        );
        
        if ($this->logPath) {
            $logDir = dirname($this->logPath);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            file_put_contents($this->logPath, $logMessage, FILE_APPEND | LOCK_EX);
        } else {
            echo $logMessage;
        }
    }
}