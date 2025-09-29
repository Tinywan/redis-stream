<?php

declare(strict_types=1);

namespace Tinywan\RedisStream\Tests\Unit;

use Tinywan\RedisStream\Tests\TestCase;
use Tinywan\RedisStream\Producer;
use Tinywan\RedisStream\Exception\RedisStreamException;

class ProducerTest extends TestCase
{
    private ?Producer $producer = null;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->producer = new Producer($this->queue);
    }
    
    protected function tearDown(): void
    {
        $this->producer = null;
        parent::tearDown();
    }
    
    public function testConstructor(): void
    {
        $producer = new Producer($this->queue);
        $this->assertInstanceOf(Producer::class, $producer);
        $this->assertSame($this->queue, $producer->getQueue());
    }
    
    public function testSendSimpleMessage(): void
    {
        $messageId = $this->producer->send('simple test message');
        
        $this->assertIsString($messageId);
        $this->assertNotEmpty($messageId);
        $this->assertStringMatchesFormat('%x-%x', $messageId);
    }
    
    public function testSendMessageWithMetadata(): void
    {
        $metadata = ['priority' => 'high', 'source' => 'web'];
        $messageId = $this->producer->send('message with metadata', $metadata);
        
        $this->assertIsString($messageId);
        $this->assertNotEmpty($messageId);
        
        // 验证消息确实被发送
        $consumedMessage = $this->queue->consume();
        $this->assertEquals('high', $consumedMessage['priority']);
        $this->assertEquals('web', $consumedMessage['source']);
    }
    
    public function testSendArrayMessage(): void
    {
        $arrayMessage = [
            'user_id' => 123,
            'action' => 'create',
            'data' => ['name' => 'test']
        ];
        
        $messageId = $this->producer->send($arrayMessage);
        
        $this->assertIsString($messageId);
        $this->assertNotEmpty($messageId);
        
        // 验证消息内容
        $consumedMessage = $this->queue->consume();
        $decodedMessage = json_decode($consumedMessage['message'], true);
        $this->assertEquals(123, $decodedMessage['user_id']);
        $this->assertEquals('create', $decodedMessage['action']);
    }
    
    public function testSendObjectMessage(): void
    {
        $objectMessage = new \stdClass();
        $objectMessage->id = 456;
        $objectMessage->name = 'test object';
        
        $messageId = $this->producer->send($objectMessage);
        
        $this->assertIsString($messageId);
        $this->assertNotEmpty($messageId);
    }
    
    public function testSendBatchStringMessages(): void
    {
        $messages = [
            'batch message 1',
            'batch message 2',
            'batch message 3'
        ];
        
        $results = $this->producer->sendBatch($messages);
        
        $this->assertIsArray($results);
        $this->assertCount(3, $results);
        $this->assertNotEmpty($results[0]);
        $this->assertNotEmpty($results[1]);
        $this->assertNotEmpty($results[2]);
        
        // 验证所有消息都被发送
        for ($i = 0; $i < 3; $i++) {
            $message = $this->queue->consume();
            $this->assertEquals("batch message " . ($i + 1), $message['message']);
        }
    }
    
    public function testSendBatchMixedMessages(): void
    {
        $messages = [
            'simple string',
            ['message' => 'structured message', 'metadata' => ['type' => 'structured']],
            ['message' => 'another structured', 'metadata' => ['priority' => 'high']]
        ];
        
        $results = $this->producer->sendBatch($messages);
        
        $this->assertIsArray($results);
        $this->assertCount(3, $results);
        
        // 验证消息内容
        $message1 = $this->queue->consume();
        $this->assertEquals('simple string', $message1['message']);
        
        $message2 = $this->queue->consume();
        $this->assertEquals('structured', $message2['type']);
        $this->assertEquals('structured message', $message2['message']);
        
        $message3 = $this->queue->consume();
        $this->assertEquals('high', $message3['priority']);
        $this->assertEquals('another structured', $message3['message']);
    }
    
    public function testSendBatchEmptyArray(): void
    {
        $results = $this->producer->sendBatch([]);
        
        $this->assertIsArray($results);
        $this->assertEmpty($results);
    }
    
    public function testSendBatchWithInvalidMessage(): void
    {
        $messages = [
            ['message' => 'valid message'],
            'invalid message without message key', // 这会被当作简单字符串处理
            ['message' => 'another valid message']
        ];
        
        $results = $this->producer->sendBatch($messages);
        
        $this->assertIsArray($results);
        $this->assertCount(3, $results);
        $this->assertNotEmpty($results[0]);
        $this->assertNotEmpty($results[1]);
        $this->assertNotEmpty($results[2]);
    }
    
    public function testGetQueue(): void
    {
        $queue = $this->producer->getQueue();
        $this->assertSame($this->queue, $queue);
    }
    
    public function testSendMessageWithEmptyMetadata(): void
    {
        $messageId = $this->producer->send('test message', []);
        
        $this->assertIsString($messageId);
        $this->assertNotEmpty($messageId);
        
        // 验证消息被发送
        $message = $this->queue->consume();
        $this->assertEquals('test message', $message['message']);
    }
    
    public function testSendLargeMessage(): void
    {
        $largeMessage = str_repeat('x', 10000); // 10KB message
        
        $messageId = $this->producer->send($largeMessage);
        
        $this->assertIsString($messageId);
        $this->assertNotEmpty($messageId);
        
        // 验证消息内容
        $message = $this->queue->consume();
        $this->assertEquals($largeMessage, $message['message']);
    }
    
    public function testSendMultipleMessagesWithSameContent(): void
    {
        $messageContent = 'duplicate test message';
        
        $id1 = $this->producer->send($messageContent);
        $id2 = $this->producer->send($messageContent);
        
        $this->assertIsString($id1);
        $this->assertIsString($id2);
        $this->assertNotEquals($id1, $id2); // 每条消息应该有唯一的ID
    }
    
    public function testSendNullMessage(): void
    {
        $messageId = $this->producer->send(null);
        
        $this->assertIsString($messageId);
        $this->assertNotEmpty($messageId);
        
        // 验证消息内容
        $message = $this->queue->consume();
        $this->assertNull(json_decode($message['message'], true));
    }
    
    public function testSendBooleanMessage(): void
    {
        $messageId = $this->producer->send(true);
        
        $this->assertIsString($messageId);
        $this->assertNotEmpty($messageId);
        
        // 验证消息内容
        $message = $this->queue->consume();
        $this->assertEquals('true', $message['message']);
    }
    
    public function testSendNumericMessage(): void
    {
        $messageId = $this->producer->send(12345);
        
        $this->assertIsString($messageId);
        $this->assertNotEmpty($messageId);
        
        // 验证消息内容
        $message = $this->queue->consume();
        $this->assertEquals(12345, $message['message']);
    }
}