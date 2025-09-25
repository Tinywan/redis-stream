<?php

declare(strict_types=1);

namespace Tinywan\RedisStream\Tests\Unit;

use Tinywan\RedisStream\Tests\TestCase;
use Tinywan\RedisStream\Consumer;
use Tinywan\RedisStream\MessageHandlerInterface;

class MessageHandlerInterfaceTest extends TestCase
{
    public function testMessageHandlerInterfaceCanBeImplemented(): void
    {
        $handler = new class implements MessageHandlerInterface {
            public function handle($message) {
                return true;
            }
        };
        
        $this->assertInstanceOf(MessageHandlerInterface::class, $handler);
    }
    
    public function testMessageHandlerCanBeUsedInConsumer(): void
    {
        // 创建一个测试用的消息处理器
        $handler = new class implements MessageHandlerInterface {
            private $handledMessages = [];
            
            public function handle($message) {
                $this->handledMessages[] = $message;
                return true;
            }
            
            public function getHandledMessages(): array {
                return $this->handledMessages;
            }
        };
        
        // 发送测试消息
        $this->queue->send('handler interface test');
        
        // 使用处理器创建消费者
        $consumer = new Consumer($this->queue, $handler);
        $message = $consumer->consume();
        
        $this->assertIsArray($message);
        $this->assertCount(1, $handler->getHandledMessages());
        $this->assertEquals('handler interface test', $handler->getHandledMessages()[0]['message']);
    }
    
    public function testMessageHandlerCanProcessDifferentMessageTypes(): void
    {
        $handler = new class implements MessageHandlerInterface {
            private $processedTypes = [];
            
            public function handle($message) {
                $data = json_decode($message['message'], true);
                $this->processedTypes[] = $data['type'] ?? 'unknown';
                return true;
            }
            
            public function getProcessedTypes(): array {
                return $this->processedTypes;
            }
        };
        
        $consumer = new Consumer($this->queue, $handler);
        
        // 发送不同类型的消息
        $this->queue->send(['type' => 'email', 'content' => 'test email']);
        $this->queue->send(['type' => 'notification', 'content' => 'test notification']);
        $this->queue->send(['type' => 'system', 'content' => 'test system']);
        
        // 消费所有消息
        while ($message = $consumer->consume()) {
            // 消费消息
        }
        
        $processedTypes = $handler->getProcessedTypes();
        $this->assertCount(3, $processedTypes);
        $this->assertContains('email', $processedTypes);
        $this->assertContains('notification', $processedTypes);
        $this->assertContains('system', $processedTypes);
    }
    
    public function testMessageHandlerCanReturnDifferentValues(): void
    {
        // 测试返回 true 的情况
        $trueHandler = new class implements MessageHandlerInterface {
            public function handle($message) {
                return true;
            }
        };
        
        $this->queue->send('true handler test');
        $consumer1 = new Consumer($this->queue, $trueHandler);
        $message1 = $consumer1->consume();
        $this->assertIsArray($message1);
        
        // 测试返回 false 的情况
        $falseHandler = new class implements MessageHandlerInterface {
            public function handle($message) {
                return false;
            }
        };
        
        $this->queue->send('false handler test');
        $consumer2 = new Consumer($this->queue, $falseHandler);
        $message2 = $consumer2->consume();
        $this->assertIsArray($message2);
    }
    
    public function testMessageHandlerCanThrowExceptions(): void
    {
        $exceptionHandler = new class implements MessageHandlerInterface {
            public function handle($message) {
                if (strpos($message['message'], 'error') !== false) {
                    throw new \RuntimeException('Handler error');
                }
                return true;
            }
        };
        
        $consumer = new Consumer($this->queue, $exceptionHandler);
        
        // 发送正常消息
        $this->queue->send('normal message');
        $message1 = $consumer->consume();
        $this->assertIsArray($message1);
        
        // 发送会触发异常的消息
        $this->queue->send('error message');
        $message2 = $consumer->consume();
        $this->assertIsArray($message2); // 异常被捕获，消息仍然返回
    }
    
    public function testMessageHandlerCanAccessMessageMetadata(): void
    {
        $handler = new class implements MessageHandlerInterface {
            private $metadata = [];
            
            public function handle($message) {
                $this->metadata = $message;
                return true;
            }
            
            public function getMetadata(): array {
                return $this->metadata;
            }
        };
        
        $consumer = new Consumer($this->queue, $handler);
        
        // 发送带有元数据的消息
        $this->queue->send('test message', ['priority' => 'high', 'source' => 'api']);
        
        $message = $consumer->consume();
        $metadata = $handler->getMetadata();
        
        $this->assertArrayHasKey('id', $metadata);
        $this->assertArrayHasKey('message', $metadata);
        $this->assertArrayHasKey('attempts', $metadata);
        $this->assertArrayHasKey('priority', $metadata);
        $this->assertArrayHasKey('source', $metadata);
        $this->assertEquals('high', $metadata['priority']);
        $this->assertEquals('api', $metadata['source']);
        $this->assertEquals(1, $metadata['attempts']);
    }
    
    public function testMessageHandlerCanBeCombinedWithRouting(): void
    {
        // 创建不同类型的处理器
        $emailHandler = new class implements MessageHandlerInterface {
            public function handle($message) {
                return 'email_processed';
            }
        };
        
        $notificationHandler = new class implements MessageHandlerInterface {
            public function handle($message) {
                return 'notification_processed';
            }
        };
        
        // 创建路由器
        $router = new class($emailHandler, $notificationHandler) implements MessageHandlerInterface {
            private $emailHandler;
            private $notificationHandler;
            
            public function __construct($emailHandler, $notificationHandler) {
                $this->emailHandler = $emailHandler;
                $this->notificationHandler = $notificationHandler;
            }
            
            public function handle($message) {
                $data = json_decode($message['message'], true);
                $type = $data['type'] ?? 'unknown';
                
                if ($type === 'email') {
                    return $this->emailHandler->handle($message);
                } elseif ($type === 'notification') {
                    return $this->notificationHandler->handle($message);
                }
                
                return false;
            }
        };
        
        $consumer = new Consumer($this->queue, $router);
        
        // 测试邮件消息
        $this->queue->send(['type' => 'email', 'content' => 'test email']);
        $result1 = $consumer->consume();
        $this->assertIsArray($result1);
        
        // 测试通知消息
        $this->queue->send(['type' => 'notification', 'content' => 'test notification']);
        $result2 = $consumer->consume();
        $this->assertIsArray($result2);
    }
    
    public function testMessageHandlerCanMaintainState(): void
    {
        $handler = new class implements MessageHandlerInterface {
            private $processedCount = 0;
            private $lastProcessedMessage = null;
            
            public function handle($message) {
                $this->processedCount++;
                $this->lastProcessedMessage = $message;
                return true;
            }
            
            public function getProcessedCount(): int {
                return $this->processedCount;
            }
            
            public function getLastProcessedMessage() {
                return $this->lastProcessedMessage;
            }
        };
        
        $consumer = new Consumer($this->queue, $handler);
        
        // 发送多条消息
        $this->queue->send('message 1');
        $this->queue->send('message 2');
        $this->queue->send('message 3');
        
        // 消费消息
        $consumer->consume();
        $consumer->consume();
        $consumer->consume();
        
        $this->assertEquals(3, $handler->getProcessedCount());
        $this->assertNotNull($handler->getLastProcessedMessage());
        $this->assertEquals('message 3', $handler->getLastProcessedMessage()['message']);
    }
    
    public function testMessageHandlerCanValidateMessage(): void
    {
        $handler = new class implements MessageHandlerInterface {
            public function handle($message) {
                $data = json_decode($message['message'], true);
                
                // 验证消息格式
                if (!isset($data['required_field'])) {
                    return false;
                }
                
                if (empty($data['content'])) {
                    return false;
                }
                
                return true;
            }
        };
        
        $consumer = new Consumer($this->queue, $handler);
        
        // 发送有效消息
        $this->queue->send(['required_field' => 'value', 'content' => 'valid content']);
        $validMessage = $consumer->consume();
        $this->assertIsArray($validMessage);
        
        // 发送无效消息（缺少必需字段）
        $this->queue->send(['content' => 'invalid content']);
        $invalidMessage = $consumer->consume();
        $this->assertIsArray($invalidMessage);
        
        // 发送无效消息（内容为空）
        $this->queue->send(['required_field' => 'value', 'content' => '']);
        $emptyMessage = $consumer->consume();
        $this->assertIsArray($emptyMessage);
    }
    
    public function testMessageHandlerCanTransformMessage(): void
    {
        $handler = new class implements MessageHandlerInterface {
            public function handle($message) {
                $data = json_decode($message['message'], true);
                
                // 转换消息数据
                $transformed = [
                    'original_id' => $message['id'],
                    'processed_at' => date('Y-m-d H:i:s'),
                    'data' => $data,
                    'status' => 'processed'
                ];
                
                // 这里可以保存转换后的数据或执行其他操作
                // 现在只返回成功状态
                return true;
            }
        };
        
        $consumer = new Consumer($this->queue, $handler);
        
        $this->queue->send(['action' => 'transform', 'data' => 'test']);
        $message = $consumer->consume();
        
        $this->assertIsArray($message);
        $decoded = json_decode($message['message'], true);
        $this->assertEquals('transform', $decoded['action']);
    }
}