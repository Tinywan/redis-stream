<?php

declare(strict_types=1);

namespace Tinywan\RedisStream\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Tinywan\RedisStream\RedisStreamQueue;
use Tinywan\RedisStream\MonologFactory;

abstract class TestCase extends BaseTestCase
{
    protected ?RedisStreamQueue $queue = null;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Redis 配置
        $redisConfig = [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 1, // 使用数据库1进行测试
        ];
        
        // 队列配置
        $queueConfig = [
            'stream_name' => 'test_stream',
            'consumer_group' => 'test_group',
            'consumer_name' => 'test_consumer_' . getmypid(),
            'block_timeout' => 100, // 测试使用较短的超时时间
            'retry_attempts' => 2,
            'retry_delay' => 100,
        ];
        
        // 创建测试队列实例
        $this->queue = RedisStreamQueue::getInstance($redisConfig, $queueConfig, MonologFactory::createConsoleLogger());
    }
    
    protected function tearDown(): void
    {
        // 清理测试数据
        if ($this->queue) {
            try {
                $redis = $this->queue->getRedis();
                $redis->del('test_stream');
            } catch (\Throwable $e) {
                // 忽略清理错误
            }
        }
        
        // 清理单例实例，避免测试间干扰
        RedisStreamQueue::clearInstances();
        
        parent::tearDown();
    }
    
    /**
     * 创建测试消息
     */
    protected function createTestMessage(array $data = []): array
    {
        return array_merge([
            'type' => 'test',
            'content' => 'test message',
            'timestamp' => time(),
        ], $data);
    }
    
    /**
     * 等待指定时间（毫秒）
     */
    protected function waitFor(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}