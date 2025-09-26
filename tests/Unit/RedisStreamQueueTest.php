<?php

declare(strict_types=1);

namespace Tinywan\RedisStream\Tests\Unit;

use Tinywan\RedisStream\Tests\TestCase;
use Tinywan\RedisStream\Exception\RedisStreamException;
use Tinywan\RedisStream\MonologFactory;

class RedisStreamQueueTest extends TestCase
{
    public function testConstructorWithSeparatedConfig(): void
    {
        $redisConfig = [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => 'testpass',
            'database' => 2,
        ];
        
        $queueConfig = [
            'stream_name' => 'test_separated',
            'consumer_group' => 'test_group_separated',
            'block_timeout' => 3000,
            'retry_attempts' => 5,
        ];
        
        $queue = \Tinywan\RedisStream\RedisStreamQueue::getInstance($redisConfig, $queueConfig, MonologFactory::createConsoleLogger());
        
        $this->assertEquals('test_separated', $queue->getStreamName());
        $this->assertEquals('test_group_separated', $queue->getConsumerGroup());
        $this->assertEquals('testpass', $queue->getRedisConfig()['password']);
        $this->assertEquals(2, $queue->getRedisConfig()['database']);
        $this->assertEquals(3000, $queue->getQueueConfig()['block_timeout']);
        $this->assertEquals(5, $queue->getQueueConfig()['retry_attempts']);
    }
    
    public function testSendMessage(): void
    {
        $messageId = $this->queue->send('test message', ['type' => 'test']);
        
        $this->assertIsString($messageId);
        $this->assertNotEmpty($messageId);
        $this->assertStringMatchesFormat('%x-%x', $messageId); // Redis Stream ID format
    }
    
    public function testSendComplexMessage(): void
    {
        $complexData = [
            'user_id' => 123,
            'action' => 'update_profile',
            'data' => [
                'name' => 'John Doe',
                'email' => 'john@example.com'
            ]
        ];
        
        $messageId = $this->queue->send($complexData, ['priority' => 'high']);
        
        $this->assertIsString($messageId);
        $this->assertNotEmpty($messageId);
    }
    
    public function testConsumeMessage(): void
    {
        // 发送消息
        $testMessage = ['content' => 'test consume message'];
        $this->queue->send($testMessage);
        
        // 消费消息
        $consumedMessage = $this->queue->consume();
        
        $this->assertIsArray($consumedMessage);
        $this->assertArrayHasKey('id', $consumedMessage);
        $this->assertArrayHasKey('message', $consumedMessage);
        $this->assertArrayHasKey('attempts', $consumedMessage);
        $this->assertEquals(1, $consumedMessage['attempts']);
        
        // 消息字段应该是JSON字符串，需要解码
        $messageJson = $consumedMessage['message'];
        $this->assertIsString($messageJson);
        $messageData = json_decode($messageJson, true);
        $this->assertIsArray($messageData);
        $this->assertEquals('test consume message', $messageData['content']);
    }
    
    public function testConsumeWithCallback(): void
    {
        // 发送消息
        $this->queue->send('callback test');
        
        $callbackCalled = false;
        $callbackMessage = null;
        
        // 使用回调函数消费消息
        $result = $this->queue->consume(function($message) use (&$callbackCalled, &$callbackMessage) {
            $callbackCalled = true;
            $callbackMessage = $message;
            return true; // 自动确认
        });
        
        $this->assertIsArray($result);
        $this->assertTrue($callbackCalled);
        $this->assertIsArray($callbackMessage);
        $this->assertEquals('callback test', $callbackMessage['message']);
    }
    
    public function testAckMessage(): void
    {
        // 发送消息
        $this->queue->send('ack test message');
        
        // 消费消息但不自动确认
        $message = $this->queue->consume();
        
        $this->assertIsArray($message);
        
        // 手动确认消息
        $ackResult = $this->queue->ack($message['id']);
        $this->assertTrue($ackResult);
    }
    
    public function testNackMessageWithRetry(): void
    {
        // 发送消息
        $this->queue->send('nack test message');
        
        // 消费消息
        $message = $this->queue->consume();
        $originalId = $message['id'];
        
        // 拒绝消息并重试
        $nackResult = $this->queue->nack($originalId, true);
        $this->assertTrue($nackResult);
        
        // 等待重试消息被重新加入队列
        $this->waitFor(200);
        
        // 再次消费应该是新的消息ID
        $retryMessage = $this->queue->consume();
        $this->assertNotEquals($originalId, $retryMessage['id']);
        $this->assertEquals(2, $retryMessage['attempts']);
    }
    
    public function testNackMessageWithoutRetry(): void
    {
        // 发送消息
        $this->queue->send('nack no retry test');
        
        // 消费消息
        $message = $this->queue->consume();
        
        // 拒绝消息不重试
        $nackResult = $this->queue->nack($message['id'], false);
        $this->assertTrue($nackResult);
    }
    
    public function testGetQueueStatus(): void
    {
        // 发送几条消息
        $this->queue->send('status test 1');
        $this->queue->send('status test 2');
        $this->queue->send('status test 3');
        
        $streamLength = $this->queue->getStreamLength();
        $this->assertGreaterThan(0, $streamLength);
        
        $pendingCount = $this->queue->getPendingCount();
        $this->assertGreaterThanOrEqual(0, $pendingCount);
    }
    
    public function testDefaultConfigValues(): void
    {
        $queue = \Tinywan\RedisStream\RedisStreamQueue::getInstance([], [], MonologFactory::createConsoleLogger());
        
        $redisConfig = $queue->getRedisConfig();
        $this->assertEquals('127.0.0.1', $redisConfig['host']);
        $this->assertEquals(6379, $redisConfig['port']);
        $this->assertNull($redisConfig['password']);
        $this->assertEquals(0, $redisConfig['database']);
        $this->assertEquals(5, $redisConfig['timeout']);
        
        $queueConfig = $queue->getQueueConfig();
        $this->assertEquals('redis_stream_queue', $queueConfig['stream_name']);
        $this->assertEquals('redis_stream_group', $queueConfig['consumer_group']);
        $this->assertEquals(5000, $queueConfig['block_timeout']);
        $this->assertEquals(3, $queueConfig['retry_attempts']);
        $this->assertEquals(1000, $queueConfig['retry_delay']);
    }
    
    public function testConsumeReturnsNullWhenNoMessages(): void
    {
        // 确保队列为空
        $this->waitFor(100);
        
        $result = $this->queue->consume();
        $this->assertNull($result);
    }
    
    public function testGetters(): void
    {
        $this->assertInstanceOf(\Redis::class, $this->queue->getRedis());
        $this->assertIsString($this->queue->getStreamName());
        $this->assertIsString($this->queue->getConsumerGroup());
        $this->assertIsString($this->queue->getConsumerName());
        $this->assertInstanceOf(\Monolog\Logger::class, $this->queue->getLogger());
        $this->assertIsArray($this->queue->getConfig());
    }
    
    public function testInvalidRedisConnection(): void
    {
        $this->expectException(RedisStreamException::class);
        
        \Tinywan\RedisStream\RedisStreamQueue::getInstance(
            [
                'host' => 'invalid_host',
                'port' => 9999,
                'timeout' => 1,
            ],
            [],
            MonologFactory::createConsoleLogger()
        );
    }
}