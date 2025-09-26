<?php

declare(strict_types=1);

namespace Tinywan\RedisStream;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;
use Monolog\Processor\IntrospectionProcessor;

class MonologFactory
{
    /**
     * 创建统一的日志记录器
     * 
     * @param string $channel 日志通道名称
     * @param bool $enableFile 是否启用文件日志记录
     * @param bool $debug 是否启用调试模式（影响日志详细程度）
     * @param string|null $logPath 自定义日志文件路径
     * @return Logger
     */
    public static function createLogger(
        string $channel = 'redis-stream', 
        bool $enableFile = false, 
        bool $debug = false, 
        string $logPath = null
    ): Logger {
        $logger = new Logger($channel);
        
        // 始终添加控制台处理器
        $consoleHandler = new StreamHandler('php://stdout', $debug ? Logger::DEBUG : Logger::INFO);
        
        // 根据调试模式选择控制台格式
        if ($debug) {
            $consoleFormatter = new LineFormatter(
                "[%datetime%] [%level_name%] %message% %context%\n",
                'Y-m-d H:i:s.v'
            );
        } else {
            $consoleFormatter = new LineFormatter(
                "[%datetime%] [%level_name%] %message% %context%\n",
                'Y-m-d H:i:s'
            );
        }
        $consoleHandler->setFormatter($consoleFormatter);
        $logger->pushHandler($consoleHandler);
        
        // 根据配置添加文件处理器
        if ($enableFile) {
            if ($logPath === null) {
                $logPath = __DIR__ . '/../../logs/redis-stream.log';
            }
            
            // 确保日志目录存在
            $logDir = dirname($logPath);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            // 文件处理器配置
            $fileLogLevel = $debug ? Logger::DEBUG : Logger::INFO;
            $fileHandler = new RotatingFileHandler($logPath, 30, $fileLogLevel);
            
            // 根据调试模式选择格式
            if ($debug) {
                $fileFormatter = new LineFormatter(
                    "[%datetime%] [%channel%.%level_name%] %message% %context% %extra%\n",
                    'Y-m-d H:i:s.v',
                    true,
                    true
                );
                // 调试模式下添加调用信息处理器
                $logger->pushProcessor(new IntrospectionProcessor());
            } else {
                $fileFormatter = new LineFormatter(
                    "[%datetime%] [%channel%.%level_name%] %message% %context%\n",
                    'Y-m-d H:i:s'
                );
            }
            
            $fileHandler->setFormatter($fileFormatter);
            $logger->pushHandler($fileHandler);
        }
        
        return $logger;
    }
}