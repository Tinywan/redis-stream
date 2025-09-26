<?php

declare(strict_types=1);

namespace Tinywan\RedisStream\Adapter;

use think\facade\Config;
use think\facade\Log;
use Tinywan\RedisStream\RedisStreamQueue;
use Tinywan\RedisStream\Producer;
use Tinywan\RedisStream\Consumer;
use Tinywan\RedisStream\Exception\RedisStreamException;

/**
 * ThinkPHP 6.1 框架适配器
 * 
 * 复用 ThinkPHP 的 Redis 配置和日志配置
 */
class ThinkPHPAdapter
{
    /**
     * 获取 Redis 连接配置
     */
    public static function getRedisConfig(): array
    {
        // 优先使用 config/redis.php 的 default 配置
        $redisConfig = Config::get('redis', []);
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

        // 如果没有 default 配置，取第一个 Redis 配置
        if (!empty($redisConfig)) {
            $defaultConfig = current($redisConfig);
            return [
                'host' => $defaultConfig['host'] ?? '127.0.0.1',
                'port' => $defaultConfig['port'] ?? 6379,
                'password' => $defaultConfig['password'] ?? null,
                'database' => $defaultConfig['database'] ?? 0,
                'timeout' => $defaultConfig['timeout'] ?? 5,
            ];
        }

        // 使用 redis 缓存配置
        $cacheConfig = Config::get('cache.stores.redis', []);
        if (!empty($cacheConfig)) {
            return [
                'host' => $cacheConfig['host'] ?? '127.0.0.1',
                'port' => $cacheConfig['port'] ?? 6379,
                'password' => $cacheConfig['password'] ?? null,
                'database' => $cacheConfig['database'] ?? 0,
                'timeout' => $cacheConfig['timeout'] ?? 5,
            ];
        }

        // 使用 session Redis 配置
        $sessionConfig = Config::get('session.type', []);
        if ($sessionConfig === 'redis') {
            $redisConfig = Config::get('session.redis', []);
            if (!empty($redisConfig)) {
                return [
                    'host' => $redisConfig['host'] ?? '127.0.0.1',
                    'port' => $redisConfig['port'] ?? 6379,
                    'password' => $redisConfig['password'] ?? null,
                    'database' => $redisConfig['database'] ?? 0,
                    'timeout' => $redisConfig['timeout'] ?? 5,
                ];
            }
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
        $config = Config::get('redis_stream', []);
        
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
        return Log::channel($name);
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
        $config = Config::get('redis_stream', []);
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

    /**
     * 注册 ThinkPHP 服务
     */
    public static function registerServices(): void
    {
        // 注册服务到容器
        \think\Container::set('redis_stream_queue', function ($queueName = 'default') {
            return self::createQueue($queueName);
        });

        \think\Container::set('redis_stream_producer', function ($queueName = 'default') {
            return self::createProducer($queueName);
        });

        \think\Container::set('redis_stream_consumer', function ($queueName = 'default') {
            return self::createConsumer($queueName);
        });
    }

    /**
     * 获取 ThinkPHP 助手函数
     */
    public static function getHelperFunctions(): string
    {
        return <<<'PHP'
<?php

if (!function_exists('redis_queue')) {
    /**
     * 获取 Redis Stream 队列实例
     */
    function redis_queue(string $queueName = 'default'): \Tinywan\RedisStream\RedisStreamQueue
    {
        return \Tinywan\RedisStream\Adapter\ThinkPHPAdapter::createQueue($queueName);
    }
}

if (!function_exists('redis_producer')) {
    /**
     * 获取 Redis Stream 生产者实例
     */
    function redis_producer(string $queueName = 'default'): \Tinywan\RedisStream\Producer
    {
        return \Tinywan\RedisStream\Adapter\ThinkPHPAdapter::createProducer($queueName);
    }
}

if (!function_exists('redis_consumer')) {
    /**
     * 获取 Redis Stream 消费者实例
     */
    function redis_consumer(string $queueName = 'default'): \Tinywan\RedisStream\Consumer
    {
        return \Tinywan\RedisStream\Adapter\ThinkPHPAdapter::createConsumer($queueName);
    }
}

if (!function_exists('redis_queue_send')) {
    /**
     * 快速发送消息到队列
     */
    function redis_queue_send(string $message, array $metadata = [], string $queueName = 'default'): string
    {
        return redis_producer($queueName)->send($message, $metadata);
    }
}

PHP;
    }
}