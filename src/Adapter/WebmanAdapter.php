<?php

declare(strict_types=1);

namespace Tinywan\RedisStream\Adapter;

use support\Container;
use Tinywan\RedisStream\RedisStreamQueue;
use Tinywan\RedisStream\Producer;
use Tinywan\RedisStream\Consumer;
use Tinywan\RedisStream\Exception\RedisStreamException;
use Webman\Config;
use Webman\RedisQueue\Redis as WebmanRedis;

/**
 * Webman 框架适配器
 * 
 * 复用 webman 的 Redis 配置和日志配置
 */
class WebmanAdapter
{
    /**
     * 获取 Redis 连接配置
     */
    public static function getRedisConfig(): array
    {
        // 优先使用 config/redis.php 的 default 配置
        $redisConfig = config('redis', []);
        if (!empty($redisConfig) && isset($redisConfig['default'])) {
            $defaultConfig = $redisConfig['default'];
            return [
                'host' => $defaultConfig['host'] ?? '127.0.0.1',
                'port' => $defaultConfig['port'] ?? 6379,
                'password' => $defaultConfig['password'] ?? null,
                'database' => $defaultConfig['database'] ?? 0,
                'timeout' => $defaultConfig['timeout'] ?? 5,
            ];
        }

        // 默认配置
        return [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => null,
            'database' => 0,
            'timeout' => 5,
        ];
    }

    /**
     * 获取队列配置
     */
    public static function getQueueConfig(string $queueName = 'default'): array
    {
        $config = config('redis-stream', []);
        
        return [
            'stream_name' => $config['stream_name'] ?? "{$queueName}_stream",
            'consumer_group' => $config['consumer_group'] ?? "{$queueName}_group",
            'consumer_name' => $config['consumer_name'] ?? "worker_" . getmypid(),
            'block_timeout' => $config['block_timeout'] ?? 5000,
            'retry_attempts' => $config['retry_attempts'] ?? 3,
            'retry_delay' => $config['retry_delay'] ?? 1000,
        ];
    }

    /**
     * 获取日志记录器
     */
    public static function getLogger(string $name = 'redis-stream')
    {
        return \support\Log::channel($name);
    }

    /**
     * 创建队列实例
     */
    public static function createQueue(string $queueName = 'default'): RedisStreamQueue
    {
        $redisConfig = self::getRedisConfig();
        $queueConfig = self::getQueueConfig($queueName);
        $logger = self::getLogger($queueName);

        return RedisStreamQueue::getInstance($redisConfig, $queueConfig, $logger);
    }

    /**
     * 创建生产者
     */
    public static function createProducer(string $queueName = 'default'): Producer
    {
        $queue = self::createQueue($queueName);
        return new Producer($queue);
    }

    /**
     * 创建消费者
     */
    public static function createConsumer(string $queueName = 'default'): Consumer
    {
        $queue = self::createQueue($queueName);
        $consumer = new Consumer($queue);
        
        // 从配置中读取内存限制
        $config = config('redis-stream', []);
        $memoryLimit = $config['memory_limit'] ?? 128 * 1024 * 1024; // 默认 128MB
        $consumer->setMemoryLimit($memoryLimit);
        
        return $consumer;
    }

    /**
     * 获取连接池状态
     */
    public static function getConnectionPoolStatus(): array
    {
        try {
            $queue = self::createQueue();
            return $queue->getConnectionPoolStatus();
        } catch (RedisStreamException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 获取实例状态
     */
    public static function getInstancesStatus(): array
    {
        try {
            $queue = self::createQueue();
            return $queue->getInstancesStatus();
        } catch (RedisStreamException $e) {
            return ['error' => $e->getMessage()];
        }
    }
}