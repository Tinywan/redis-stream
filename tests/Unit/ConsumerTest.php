<?php

declare(strict_types=1);

namespace Tinywan\RedisStream\Tests\Unit;

use Tinywan\RedisStream\Tests\TestCase;
use Tinywan\RedisStream\Consumer;
use Tinywan\RedisStream\MessageHandlerInterface;
use Tinywan\RedisStream\Exception\RedisStreamException;

class ConsumerTest extends TestCase
{
    private ?Consumer $consumer = null;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->consumer = new Consumer($this->queue);
    }
    
    protected function tearDown(): void
    {
        if ($this->consumer && $this->consumer->isRunning()) {
            $this->consumer->stop();
        }
        $this->consumer = null;
        parent::tearDown();
    }
    
    public function testConstructorWithCallback(): void
    {
        $callback = function($message) {
            return true;
        };
        
        $consumer = new Consumer($this->queue, $callback);
        $this->assertInstanceOf(Consumer::class, $consumer);
        $this->assertSame($this->queue, $consumer->getQueue());
    }
    
    public function testConstructorWithMessageHandler(): void
    {
        $handler = new class implements MessageHandlerInterface {
            public function handle($message) {
                return true;
            }
        };
        
        $consumer = new Consumer($this->queue, $handler);
        $this->assertInstanceOf(Consumer::class, $consumer);
        $this->assertSame($this->queue, $consumer->getQueue());
    }
    
    public function testConstructorWithoutHandler(): void
    {
        $consumer = new Consumer($this->queue);
        $this->assertInstanceOf(Consumer::class, $consumer);
        $this->assertSame($this->queue, $consumer->getQueue());
    }
    
    public function testSetCallback(): void
    {
        $callback = function($message) {
            return true;
        };
        
        $result = $this->consumer->setCallback($callback);
        $this->assertSame($this->consumer, $result); // 链式调用
    }
    
    public function testSetMessageHandler(): void
    {
        $handler = new class implements MessageHandlerInterface {
            public function handle($message) {
                return true;
            }
        };
        
        $result = $this->consumer->setCallback($handler);
        $this->assertSame($this->consumer, $result); // 链式调用
    }
    
    public function testConsumeWithCallback(): void
    {
        // 发送测试消息
        $this->queue->send('test message');
        
        $callbackCalled = false;
        $callbackMessage = null;
        
        $callback = function($message) use (&$callbackCalled, &$callbackMessage) {
            $callbackCalled = true;
            $callbackMessage = $message;
            return true;
        };
        
        $message = $this->consumer->setCallback($callback)->consume();
        
        $this->assertIsArray($message);
        $this->assertTrue($callbackCalled);
        $this->assertIsArray($callbackMessage);
        $this->assertEquals('test message', $callbackMessage['message']);
    }
    
    public function testConsumeWithMessageHandler(): void
    {
        // 发送测试消息
        $this->queue->send('handler test message');
        
        $handlerCalled = false;
        $handlerMessage = null;
        
        $handler = new class implements MessageHandlerInterface {
            private $called;
            private $message;
            
            public function handle($message) {
                $this->called = true;
                $this->message = $message;
                return true;
            }
            
            public function wasCalled(): bool {
                return $this->called;
            }
            
            public function getMessage() {
                return $this->message;
            }
        };
        
        $message = $this->consumer->setCallback($handler)->consume();
        
        $this->assertIsArray($message);
        $this->assertTrue($handler->wasCalled());
        $this->assertIsArray($handler->getMessage());
        $this->assertEquals('handler test message', $handler->getMessage()['message']);
    }
    
    public function testConsumeReturnsNullWhenNoMessages(): void
    {
        $message = $this->consumer->consume();
        $this->assertNull($message);
    }
    
    public function testConsumeWithCallbackReturningTrue(): void
    {
        // 发送测试消息
        $this->queue->send('auto ack test');
        
        $callback = function($message) {
            return true; // 应该自动确认消息
        };
        
        $message = $this->consumer->setCallback($callback)->consume();
        
        $this->assertIsArray($message);
        $this->assertEquals('auto ack test', $message['message']);
        
        // 消息应该被自动确认，不再在待处理队列中
        $this->waitFor(100);
        $pendingCount = $this->queue->getPendingCount();
        $this->assertEquals(0, $pendingCount);
    }
    
    public function testConsumeWithCallbackReturningFalse(): void
    {
        // 发送测试消息
        $this->queue->send('no ack test');
        
        $callback = function($message) {
            return false; // 不应该自动确认消息
        };
        
        $message = $this->consumer->setCallback($callback)->consume();
        
        $this->assertIsArray($message);
        $this->assertEquals('no ack test', $message['message']);
        
        // 消息不应该被确认，应该在待处理队列中
        $this->waitFor(100);
        $pendingCount = $this->queue->getPendingCount();
        $this->assertGreaterThan(0, $pendingCount);
    }
    
    public function testConsumeWithCallbackThrowingException(): void
    {
        // 发送测试消息
        $this->queue->send('exception test');
        
        $callback = function($message) {
            throw new \RuntimeException('Test exception');
        };
        
        $message = $this->consumer->setCallback($callback)->consume();
        
        $this->assertIsArray($message);
        $this->assertEquals('exception test', $message['message']);
        
        // 异常应该被捕获，消息不应该被确认
        $this->waitFor(100);
        $pendingCount = $this->queue->getPendingCount();
        $this->assertGreaterThan(0, $pendingCount);
    }
    
    public function testIsRunning(): void
    {
        $this->assertFalse($this->consumer->isRunning());
        
        // 模拟启动（在真实场景中会使用 run() 方法）
        $reflection = new \ReflectionClass($this->consumer);
        $runningProperty = $reflection->getProperty('running');
        $runningProperty->setAccessible(true);
        $runningProperty->setValue($this->consumer, true);
        
        $this->assertTrue($this->consumer->isRunning());
    }
    
    public function testStop(): void
    {
        // 模拟运行状态
        $reflection = new \ReflectionClass($this->consumer);
        $runningProperty = $reflection->getProperty('running');
        $runningProperty->setAccessible(true);
        $runningProperty->setValue($this->consumer, true);
        
        $this->assertTrue($this->consumer->isRunning());
        
        $this->consumer->stop();
        $this->assertFalse($this->consumer->isRunning());
    }
    
    public function testSetMemoryLimit(): void
    {
        $result = $this->consumer->setMemoryLimit(64 * 1024 * 1024); // 64MB
        
        $this->assertSame($this->consumer, $result); // 链式调用
        
        // 验证内存限制被设置
        $reflection = new \ReflectionClass($this->consumer);
        $memoryLimitProperty = $reflection->getProperty('memoryLimit');
        $memoryLimitProperty->setAccessible(true);
        $this->assertEquals(64 * 1024 * 1024, $memoryLimitProperty->getValue($this->consumer));
    }
    
    public function testGetQueue(): void
    {
        $queue = $this->consumer->getQueue();
        $this->assertSame($this->queue, $queue);
    }
    
    public function testDefaultMemoryLimit(): void
    {
        $reflection = new \ReflectionClass($this->consumer);
        $memoryLimitProperty = $reflection->getProperty('memoryLimit');
        $memoryLimitProperty->setAccessible(true);
        
        $defaultLimit = $memoryLimitProperty->getValue($this->consumer);
        $this->assertEquals(128 * 1024 * 1024, $defaultLimit); // 128MB
    }
    
    public function testConsumeWithDifferentCallbackTypes(): void
    {
        // 发送多条消息
        $this->queue->send('message 1');
        $this->queue->send('message 2');
        $this->queue->send('message 3');
        
        // 测试不同的回调返回值
        $callbacks = [
            'return_true' => function($message) { return true; },
            'return_null' => function($message) { return null; },
            'return_false' => function($message) { return false; },
        ];
        
        $results = [];
        foreach ($callbacks as $name => $callback) {
            $message = $this->consumer->setCallback($callback)->consume();
            $results[$name] = $message;
        }
        
        // 所有消息都应该被消费
        $this->assertCount(3, $results);
        $this->assertIsArray($results['return_true']);
        $this->assertIsArray($results['return_null']);
        $this->assertIsArray($results['return_false']);
    }
    
    public function testCallbackReceivingMessageData(): void
    {
        // 发送带有元数据的消息
        $testData = [
            'content' => 'test content',
            'metadata' => ['source' => 'test', 'timestamp' => time()]
        ];
        
        $this->queue->send($testData, ['priority' => 'high']);
        
        $receivedMessage = null;
        $callback = function($message) use (&$receivedMessage) {
            $receivedMessage = $message;
            return true;
        };
        
        $this->consumer->setCallback($callback)->consume();
        
        $this->assertIsArray($receivedMessage);
        $this->assertArrayHasKey('id', $receivedMessage);
        $this->assertArrayHasKey('message', $receivedMessage);
        $this->assertArrayHasKey('attempts', $receivedMessage);
        $this->assertArrayHasKey('priority', $receivedMessage);
        $this->assertEquals('high', $receivedMessage['priority']);
        $this->assertEquals(1, $receivedMessage['attempts']);
        
        // 消息字段应该是JSON字符串，需要解码
        $decodedContent = json_decode($receivedMessage['message'], true);
        $this->assertIsArray($decodedContent);
        $this->assertEquals('test content', $decodedContent['content']);
    }
}