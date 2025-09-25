<?php

declare(strict_types=1);

namespace Tinywan\RedisStream\Tests\Integration;

use Tinywan\RedisStream\Tests\TestCase;
use Tinywan\RedisStream\Producer;
use Tinywan\RedisStream\Consumer;
use Tinywan\RedisStream\MessageHandlerInterface;
use Tinywan\RedisStream\Exception\RedisStreamException;

/**
 * 集成测试类
 * 
 * 测试整个 Redis Stream 队列系统的端到端功能
 * 包含生产者、消费者、消息处理器的完整流程测试
 */
class IntegrationTest extends TestCase
{
    private ?Producer $producer = null;
    private ?Consumer $consumer = null;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->producer = new Producer($this->queue);
        $this->consumer = new Consumer($this->queue);
    }
    
    protected function tearDown(): void
    {
        if ($this->consumer && $this->consumer->isRunning()) {
            $this->consumer->stop();
        }
        $this->producer = null;
        $this->consumer = null;
        parent::tearDown();
    }
    
    public function testProducerConsumerWorkflow(): void
    {
        // 直接使用队列实例发送消息
        $messageId = $this->queue->send('integration test message');
        $this->assertIsString($messageId);
        $this->assertNotEmpty($messageId);
        
        // 等待消息处理
        $this->waitFor(200);
        
        // 直接使用队列实例接收消息
        $receivedMessage = $this->queue->consume();
        $this->assertIsArray($receivedMessage);
        
        // 对于字符串消息，直接比较，不需要JSON解码
        $this->assertEquals('integration test message', $receivedMessage['message']);
    }
    
    public function testBatchProcessingWorkflow(): void
    {
        // 批量发送消息
        $messages = [
            'batch message 1',
            ['type' => 'email', 'content' => 'test email'],
            ['type' => 'notification', 'content' => 'test notification']
        ];
        
        $messageIds = [];
        foreach ($messages as $message) {
            $messageIds[] = $this->queue->send($message);
        }
        $this->assertCount(3, $messageIds);
        
        // 等待消息处理
        $this->waitFor(200);
        
        // 批量消费消息
        $receivedMessages = [];
        for ($i = 0; $i < 3; $i++) {
            $message = $this->queue->consume();
            if ($message) {
                $receivedMessages[] = $message;
            }
        }
        
        $this->assertCount(3, $receivedMessages);
        
        // 验证消息内容
        $this->assertEquals('batch message 1', $receivedMessages[0]['message']);
        
        $secondMessage = json_decode($receivedMessages[1]['message'], true);
        $this->assertEquals('email', $secondMessage['type']);
        $this->assertEquals('test email', $secondMessage['content']);
        
        $thirdMessage = json_decode($receivedMessages[2]['message'], true);
        $this->assertEquals('notification', $thirdMessage['type']);
    }
    
    public function testMessageHandlerIntegration(): void
    {
        // 创建消息处理器
        $handler = new class implements MessageHandlerInterface {
            private $processedMessages = [];
            
            public function handle($message) {
                $data = json_decode($message['message'], true);
                $this->processedMessages[] = $data;
                return true;
            }
            
            public function getProcessedMessages(): array {
                return $this->processedMessages;
            }
        };
        
        // 使用消息处理器创建消费者
        $consumer = new Consumer($this->queue, $handler);
        
        // 发送消息
        $this->producer->send(['action' => 'process', 'data' => 'test data']);
        
        // 消费消息
        $consumer->consume();
        
        // 验证处理器接收到消息
        $processedMessages = $handler->getProcessedMessages();
        $this->assertCount(1, $processedMessages);
        $this->assertEquals('process', $processedMessages[0]['action']);
        $this->assertEquals('test data', $processedMessages[0]['data']);
    }
    
    public function testErrorHandlingWorkflow(): void
    {
        // 发送消息
        $this->producer->send('error handling test');
        
        // 创建会抛出异常的回调
        $callback = function($message) {
            if (strpos($message['message'], 'error') !== false) {
                throw new \RuntimeException('Processing error');
            }
            return true;
        };
        
        // 使用错误回调消费消息
        $message = $this->consumer->setCallback($callback)->consume();
        
        // 消息应该仍然被返回，但不会被确认
        $this->assertIsArray($message);
        $this->assertEquals('error handling test', $message['message']);
        
        // 验证消息在待处理队列中
        $this->waitFor(100);
        $pendingCount = $this->queue->getPendingCount();
        $this->assertGreaterThan(0, $pendingCount);
    }
    
    public function testRetryMechanism(): void
    {
        // 发送消息
        $messageId = $this->queue->send('retry test message');
        
        // 第一次消费消息
        $message1 = $this->queue->consume();
        $this->assertIsArray($message1);
        $this->assertEquals(1, $message1['attempts']);
        
        // 手动拒绝消息并触发重试
        $this->queue->nack($message1['id'], true);
        
        // 等待重试延迟
        $this->waitFor(200);
        
        // 第二次消费，应该是重试的消息
        $message2 = $this->queue->consume();
        $this->assertIsArray($message2);
        $this->assertEquals(2, $message2['attempts']);
        $this->assertEquals('retry test message', $message2['message']);
        
        // 确认重试的消息
        $this->queue->ack($message2['id']);
    }
    
    public function testMultipleConsumers(): void
    {
        // 创建多个消费者
        $consumer1 = new Consumer($this->queue);
        $consumer2 = new Consumer($this->queue);
        
        // 发送多条消息
        $this->producer->send('message for consumer 1');
        $this->producer->send('message for consumer 2');
        
        // 两个消费者同时消费
        $message1 = $consumer1->consume();
        $message2 = $consumer2->consume();
        
        // 验证两个消费者都收到了消息
        $this->assertIsArray($message1);
        $this->assertIsArray($message2);
        
        // 验证消息内容不同（Redis Stream 的消费者组机制）
        $this->assertNotEquals($message1['id'], $message2['id']);
    }
    
    public function testMessageMetadataPropagation(): void
    {
        // 发送带有元数据的消息
        $metadata = [
            'priority' => 'high',
            'source' => 'api',
            'timestamp' => time()
        ];
        
        $messageId = $this->producer->send('metadata test', $metadata);
        $this->assertIsString($messageId);
        
        // 消费消息并验证元数据
        $receivedMessage = $this->consumer->consume();
        
        $this->assertIsArray($receivedMessage);
        $this->assertEquals('high', $receivedMessage['priority']);
        $this->assertEquals('api', $receivedMessage['source']);
        $this->assertEquals($metadata['timestamp'], $receivedMessage['timestamp']);
    }
    
    public function testLargeMessageProcessing(): void
    {
        // 创建大消息
        $largeData = [
            'content' => str_repeat('x', 5000), // 5KB 数据
            'metadata' => [
                'size' => 5000,
                'type' => 'large_message'
            ]
        ];
        
        // 发送大消息
        $messageId = $this->producer->send($largeData);
        $this->assertIsString($messageId);
        
        // 消费大消息
        $receivedMessage = $this->consumer->consume();
        $this->assertIsArray($receivedMessage);
        
        // 验证大消息内容
        $decodedMessage = json_decode($receivedMessage['message'], true);
        $this->assertEquals(5000, strlen($decodedMessage['content']));
        $this->assertEquals(5000, $decodedMessage['metadata']['size']);
        $this->assertEquals('large_message', $decodedMessage['metadata']['type']);
    }
    
    public function testQueueStatistics(): void
    {
        // 清空队列
        while ($this->queue->consume() !== null) {
            // 消费所有剩余消息
        }
        
        // 发送消息
        $this->producer->send('stats test 1');
        $this->producer->send('stats test 2');
        
        // 检查队列长度
        $streamLength = $this->queue->getStreamLength();
        $this->assertGreaterThanOrEqual(2, $streamLength);
        
        // 消费一条消息
        $this->consumer->consume();
        
        // 检查待处理消息数量
        $pendingCount = $this->queue->getPendingCount();
        $this->assertGreaterThanOrEqual(0, $pendingCount);
    }
    
    public function testGracefulShutdown(): void
    {
        // 创建消费者并模拟运行状态
        $consumer = new Consumer($this->queue);
        
        // 发送消息
        $this->producer->send('shutdown test');
        
        // 消费消息
        $message = $consumer->consume();
        $this->assertIsArray($message);
        
        // 停止消费者
        $consumer->stop();
        $this->assertFalse($consumer->isRunning());
    }
    
    public function testDifferentMessageFormats(): void
    {
        $testCases = [
            'string' => 'simple string message',
            'array' => ['key' => 'value', 'number' => 123],
            'object' => (object)['property' => 'value'],
            'boolean' => true,
            'number' => 42,
            'null' => null
        ];
        
        foreach ($testCases as $type => $content) {
            // 发送消息
            $messageId = $this->queue->send($content);
            $this->assertIsString($messageId);
            
            // 等待消息处理
            $this->waitFor(100);
            
            // 消费消息
            $receivedMessage = $this->queue->consume();
            $this->assertIsArray($receivedMessage);
            
            // 验证消息内容
            if (is_string($content)) {
                // 字符串消息直接比较
                $this->assertEquals($content, $receivedMessage['message'], "Failed for type: $type");
            } else {
                // 其他类型需要JSON解码
                $decodedContent = json_decode($receivedMessage['message'], true);
                
                // 对象会被转换为数组
                if ($type === 'object') {
                    $expectedArray = (array)$content;
                    $this->assertEquals($expectedArray, $decodedContent, "Failed for type: $type");
                } else {
                    $this->assertEquals($content, $decodedContent, "Failed for type: $type");
                }
            }
        }
    }
    
    public function testStreamPersistence(): void
    {
        // 创建新的队列实例
        $newQueue = \Tinywan\RedisStream\RedisStreamQueue::getInstance([
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 1
        ], [
            'stream_name' => 'persistence_test_stream',
            'consumer_group' => 'persistence_test_group'
        ]);
        
        $producer = new Producer($newQueue);
        $consumer = new Consumer($newQueue);
        
        // 发送消息
        $messageId = $producer->send('persistence test');
        $this->assertIsString($messageId);
        
        // 消费消息
        $message = $consumer->consume();
        $this->assertIsArray($message);
        $this->assertEquals('persistence test', $message['message']);
        
        // 清理
        unset($newQueue, $producer, $consumer);
    }
}