<?php

use PHPUnit\Framework\TestCase;
use Tinywan\RedisStream\RedisStreamQueue;
use Tinywan\RedisStream\MonologFactory;
use Tinywan\RedisStream\Exception\RedisStreamException;

class DelayedQueueTest extends TestCase
{
    private RedisStreamQueue $queue;
    private string $testStreamName = 'test_delayed_queue';
    private string $testDelayedQueueName;
    
    protected function setUp(): void
    {
        $redisConfig = [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
            'timeout' => 1,
        ];
        
        $queueConfig = [
            'stream_name' => $this->testStreamName,
            'consumer_group' => 'test_delayed_group',
            'consumer_name' => 'test_consumer_' . getmypid(),
            'block_timeout' => 1000,
            'debug' => false,
            'scheduler_interval' => 1,
            'scheduler_batch_size' => 10,
        ];
        
        $this->queue = RedisStreamQueue::getInstance($redisConfig, $queueConfig, MonologFactory::createLogger('test'));
        $this->testDelayedQueueName = $this->queue->getDelayedQueueName();
        
        // 清理测试数据
        $this->cleanupTestData();
    }
    
    protected function tearDown(): void
    {
        $this->cleanupTestData();
    }
    
    private function cleanupTestData(): void
    {
        try {
            $this->queue->getRedis()->del($this->testStreamName);
            $this->queue->getRedis()->del($this->testDelayedQueueName);
        } catch (Throwable $e) {
            // 忽略清理错误
        }
    }
    
    public function testSendImmediateMessage(): void
    {
        $messageId = $this->queue->send('立即消息', ['type' => 'immediate']);
        
        $this->assertIsString($messageId);
        $this->assertNotEmpty($messageId);
        $this->assertStringMatchesFormat('%x-%x', $messageId);
        
        // 验证消息在Stream中
        $streamLength = $this->queue->getStreamLength();
        $this->assertEquals(1, $streamLength);
        
        // 验证延迟队列为空
        $delayedLength = $this->queue->getDelayedQueueLength();
        $this->assertEquals(0, $delayedLength);
    }
    
    public function testSendDelayedMessage(): void
    {
        $taskId = $this->queue->send('延迟消息', ['type' => 'delayed'], 30);
        
        $this->assertIsString($taskId);
        $this->assertNotEmpty($taskId);
        $this->assertStringStartsWith('delayed_', $taskId);
        
        // 验证任务在延迟队列中
        $delayedLength = $this->queue->getDelayedQueueLength();
        $this->assertEquals(1, $delayedLength);
        
        // 验证Stream为空
        $streamLength = $this->queue->getStreamLength();
        $this->assertEquals(0, $streamLength);
    }
    
    public function testRunDelayedScheduler(): void
    {
        // 发送一个即将到期的延迟消息
        $this->queue->send('即将到期消息', ['type' => 'expiring'], 1);
        
        // 等待消息到期
        sleep(2);
        
        // 运行调度器
        $processedCount = $this->queue->runDelayedScheduler();
        $this->assertGreaterThanOrEqual(1, $processedCount);
        
        // 验证延迟队列为空
        $delayedLength = $this->queue->getDelayedQueueLength();
        $this->assertEquals(0, $delayedLength);
        
        // 验证Stream中有消息
        $streamLength = $this->queue->getStreamLength();
        $this->assertGreaterThanOrEqual(1, $streamLength);
    }
    
    public function testGetDelayedQueueStats(): void
    {
        // 发送一些延迟消息
        $this->queue->send('消息1', [], 30);
        $this->queue->send('消息2', [], 60);
        $this->queue->send('消息3', [], 120);
        
        $stats = $this->queue->getDelayedQueueStats();
        
        $this->assertArrayHasKey('total_delayed_tasks', $stats);
        $this->assertArrayHasKey('upcoming_tasks_60s', $stats);
        $this->assertArrayHasKey('expired_tasks', $stats);
        $this->assertArrayHasKey('earliest_task_time', $stats);
        $this->assertArrayHasKey('queue_name', $stats);
        
        $this->assertEquals(3, $stats['total_delayed_tasks']);
        $this->assertEquals($this->testDelayedQueueName, $stats['queue_name']);
    }
    
    public function testCleanupExpiredDelayedTasks(): void
    {
        // 发送一个已过期的消息（通过修改Redis数据模拟）
        $taskId = $this->queue->send('过期消息', ['type' => 'expired'], 30);
        
        // 直接修改Redis中的任务时间，使其过期
        $expiredTime = time() - 86400; // 1天前
        $taskJson = $this->queue->getRedis()->zRange($this->testDelayedQueueName, 0, 0)[0];
        $this->queue->getRedis()->zRem($this->testDelayedQueueName, $taskJson);
        $this->queue->getRedis()->zAdd($this->testDelayedQueueName, $expiredTime, $taskJson);
        
        // 清理过期任务
        $cleanedCount = $this->queue->cleanupExpiredDelayedTasks(3600); // 1小时前过期
        $this->assertGreaterThanOrEqual(1, $cleanedCount);
    }
    
    public function testDelayedQueueWithVariousDelays(): void
    {
        // 发送不同延迟时间的消息
        $delays = [5, 10, 15, 30, 60];
        $taskIds = [];
        
        foreach ($delays as $delay) {
            $taskId = $this->queue->send("延迟{$delay}秒的消息", ['delay' => $delay], $delay);
            $taskIds[] = $taskId;
        }
        
        // 验证所有任务都在延迟队列中
        $delayedLength = $this->queue->getDelayedQueueLength();
        $this->assertEquals(count($delays), $delayedLength);
        
        // 验证Stream为空
        $streamLength = $this->queue->getStreamLength();
        $this->assertEquals(0, $streamLength);
        
        // 验证统计信息
        $stats = $this->queue->getDelayedQueueStats();
        $this->assertEquals(count($delays), $stats['total_delayed_tasks']);
    }
    
    public function testSchedulerProcessesTasksInOrder(): void
    {
        // 发送多个延迟消息，按时间排序
        $tasks = [
            ['message' => '第一个任务', 'delay' => 3],
            ['message' => '第二个任务', 'delay' => 1], // 应该先执行
            ['message' => '第三个任务', 'delay' => 5],
        ];
        
        foreach ($tasks as $task) {
            $this->queue->send($task['message'], [], $task['delay']);
        }
        
        // 等待第一个任务到期
        sleep(2);
        
        // 运行调度器
        $processedCount = $this->queue->runDelayedScheduler();
        $this->assertEquals(1, $processedCount);
        
        // 验证只有第二个任务被处理
        $streamLength = $this->queue->getStreamLength();
        $this->assertEquals(1, $streamLength);
        
        $delayedLength = $this->queue->getDelayedQueueLength();
        $this->assertEquals(2, $delayedLength);
        
        // 验证被处理的消息内容
        $message = $this->queue->consume();
        $this->assertEquals('第二个任务', $message['message']);
        $this->queue->ack($message['id']);
    }
    
    public function testProducerSendDelayedMessage(): void
    {
        $producer = new \Tinywan\RedisStream\Producer($this->queue);
        
        $taskId = $producer->send('生产者延迟消息', ['source' => 'producer'], 10);
        
        $this->assertIsString($taskId);
        $this->assertStringStartsWith('delayed_', $taskId);
        
        // 验证延迟队列中有任务
        $delayedLength = $this->queue->getDelayedQueueLength();
        $this->assertEquals(1, $delayedLength);
    }
    
    public function testProducerSendBatchDelayedMessages(): void
    {
        $producer = new \Tinywan\RedisStream\Producer($this->queue);
        
        $messages = [
            ['message' => '批量延迟消息1', 'metadata' => ['batch' => 1], 'delay' => 5],
            ['message' => '批量延迟消息2', 'metadata' => ['batch' => 2], 'delay' => 10],
            ['message' => '立即消息', 'metadata' => ['batch' => 3], 'delay' => 0],
        ];
        
        $results = $producer->sendBatch($messages);
        
        $this->assertIsArray($results);
        $this->assertCount(3, $results);
        
        // 验证延迟队列中有2个任务
        $delayedLength = $this->queue->getDelayedQueueLength();
        $this->assertEquals(2, $delayedLength);
        
        // 验证Stream中有1个消息
        $streamLength = $this->queue->getStreamLength();
        $this->assertEquals(1, $streamLength);
    }
}