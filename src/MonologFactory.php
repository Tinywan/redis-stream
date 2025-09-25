<?php

declare(strict_types=1);

namespace Tinywan\RedisStream;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Processor\IntrospectionProcessor;
use Monolog\Processor\WebProcessor;
use Psr\Log\LogLevel;

class MonologFactory
{
    /**
     * 创建默认的文件日志记录器
     */
    public static function createFileLogger(string $channel = 'redis-stream', string $logPath = null): Logger
    {
        if ($logPath === null) {
            $logPath = __DIR__ . '/../../logs/redis-stream.log';
        }
        
        $logger = new Logger($channel);
        
        // 确保日志目录存在
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // 使用轮转文件处理器，保留30天的日志
        $handler = new RotatingFileHandler($logPath, 30, Logger::DEBUG);
        
        // 设置自定义格式
        $formatter = new LineFormatter(
            "[%datetime%] [%channel%.%level_name%] %message% %context% %extra%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler->setFormatter($formatter);
        
        $logger->pushHandler($handler);
        
        // 添加调用信息处理器
        $logger->pushProcessor(new IntrospectionProcessor());
        
        return $logger;
    }
    
    /**
     * 创建控制台日志记录器
     */
    public static function createConsoleLogger(string $channel = 'redis-stream'): Logger
    {
        $logger = new Logger($channel);
        
        // 控制台处理器
        $handler = new StreamHandler('php://stdout', Logger::DEBUG);
        
        // 设置简单的控制台格式
        $formatter = new LineFormatter(
            "[%datetime%] [%level_name%] %message% %context%\n",
            'H:i:s'
        );
        $handler->setFormatter($formatter);
        
        $logger->pushHandler($handler);
        
        return $logger;
    }
    
    /**
     * 创建组合日志记录器（文件 + 控制台）
     */
    public static function createCombinedLogger(string $channel = 'redis-stream', string $logPath = null): Logger
    {
        $logger = new Logger($channel);
        
        if ($logPath === null) {
            $logPath = __DIR__ . '/../../logs/redis-stream.log';
        }
        
        // 确保日志目录存在
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // 文件处理器（只记录 INFO 及以上级别）
        $fileHandler = new RotatingFileHandler($logPath, 30, Logger::INFO);
        $fileFormatter = new LineFormatter(
            "[%datetime%] [%channel%.%level_name%] %message% %context% %extra%\n",
            'Y-m-d H:i:s'
        );
        $fileHandler->setFormatter($fileFormatter);
        $logger->pushHandler($fileHandler);
        
        // 控制台处理器（记录 DEBUG 及以上级别）
        $consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);
        $consoleFormatter = new LineFormatter(
            "[%datetime%] [%level_name%] %message%\n",
            'H:i:s'
        );
        $consoleHandler->setFormatter($consoleFormatter);
        $logger->pushHandler($consoleHandler);
        
        // 添加调用信息处理器
        $logger->pushProcessor(new IntrospectionProcessor());
        
        return $logger;
    }
    
    /**
     * 创建生产环境日志记录器（更严格的日志级别）
     */
    public static function createProductionLogger(string $channel = 'redis-stream', string $logPath = null): Logger
    {
        if ($logPath === null) {
            $logPath = __DIR__ . '/../../logs/redis-stream.log';
        }
        
        $logger = new Logger($channel);
        
        // 确保日志目录存在
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // 生产环境只记录 WARNING 及以上级别
        $handler = new RotatingFileHandler($logPath, 30, Logger::WARNING);
        
        // 生产环境使用更简洁的格式
        $formatter = new LineFormatter(
            "[%datetime%] [%level_name%] %message% %context%\n",
            'Y-m-d H:i:s'
        );
        $handler->setFormatter($formatter);
        
        $logger->pushHandler($handler);
        
        return $logger;
    }
    
    /**
     * 创建开发环境日志记录器（详细信息）
     */
    public static function createDevelopmentLogger(string $channel = 'redis-stream', string $logPath = null): Logger
    {
        $logger = new Logger($channel);
        
        if ($logPath === null) {
            $logPath = __DIR__ . '/../../logs/redis-stream-dev.log';
        }
        
        // 确保日志目录存在
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // 开发环境记录所有级别
        $handler = new RotatingFileHandler($logPath, 7, Logger::DEBUG);
        
        // 开发环境使用详细格式
        $formatter = new LineFormatter(
            "[%datetime%] [%channel%.%level_name%] [%extra.file%:%extra.line%] %message% %context%\n",
            'Y-m-d H:i:s.v'
        );
        $handler->setFormatter($formatter);
        
        $logger->pushHandler($handler);
        
        // 添加详细的处理器
        $logger->pushProcessor(new IntrospectionProcessor());
        
        return $logger;
    }
    
    /**
     * 根据环境自动创建合适的日志记录器
     */
    public static function createLogger(string $channel = 'redis-stream', string $environment = null, string $logPath = null): Logger
    {
        if ($environment === null) {
            $environment = getenv('APP_ENV') ?: 'production';
        }
        
        switch (strtolower($environment)) {
            case 'development':
            case 'dev':
                return self::createDevelopmentLogger($channel, $logPath);
            
            case 'production':
            case 'prod':
                return self::createProductionLogger($channel, $logPath);
            
            case 'console':
            case 'cli':
                return self::createConsoleLogger($channel);
            
            default:
                return self::createCombinedLogger($channel, $logPath);
        }
    }
}