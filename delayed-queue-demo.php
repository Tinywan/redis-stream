<?php

require_once __DIR__ . '/vendor/autoload.php';

use Tinywan\RedisStream\RedisStreamQueue;
use Tinywan\RedisStream\MonologFactory;

try {
    echo "=== Redis Stream 延迟队列演示 ===\n\n";
    
    // Redis 配置
    $redisConfig = [
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 0,
        'timeout' => 5,
    ];
    
    // 队列配置
    $queueConfig = [
        'stream_name' => 'delayed_demo_queue',
        'consumer_group' => 'delayed_demo_group',
        'consumer_name' => 'demo_consumer_' . getmypid(),
        'block_timeout' => 2000,
        'debug' => true,
    ];
    
    // 创建队列实例
    $queue = RedisStreamQueue::getInstance($redisConfig, $queueConfig);
    
    // 清理旧数据（演示用）
    $queue->getRedis()->del($queue->getStreamName());
    $queue->getRedis()->del($queue->getDelayedQueueName());
    
    echo "1. 发送延迟消息...\n";
    
    // 发送延迟消息
    $delayedTasks = [
        ['message' => '5秒后执行的任务', 'delay' => 5, 'type' => 'short_delay'],
        ['message' => '10秒后执行的任务', 'delay' => 10, 'type' => 'medium_delay'],
        ['message' => '15秒后执行的任务', 'delay' => 15, 'type' => 'long_delay'],
    ];
    
    foreach ($delayedTasks as $task) {
        $taskId = $queue->send($task['message'], ['type' => $task['type']], $task['delay']);
        echo "   ✅ 已发送延迟任务: {$task['message']} (ID: $taskId)\n";
    }
    
    // 发送立即消息
    $immediateId = $queue->send('立即执行的任务', ['type' => 'immediate']);
    echo "   ✅ 已发送立即任务: 立即执行的任务 (ID: $immediateId)\n";
    
    echo "\n2. 队列状态:\n";
    $stats = $queue->getDelayedQueueStats();
    echo "   延迟队列长度: {$stats['total_delayed_tasks']}\n";
    echo "   即将到期(60s): {$stats['upcoming_tasks_60s']}\n";
    echo "   过期任务: {$stats['expired_tasks']}\n";
    echo "   Stream队列长度: {$queue->getStreamLength()}\n";
    
    echo "\n3. 启动调度器...\n";
    
    // 运行调度器30秒
    $queue->startDelayedScheduler(30, function($processed, $stats) use ($queue) {
        echo "   📊 调度器运行中 - 处理了 {$processed} 个任务\n";
        echo "   延迟队列剩余: {$stats['total_delayed_tasks']} 个任务\n";
        echo "   Stream队列长度: {$queue->getStreamLength()}\n";
        echo "   " . str_repeat('-', 40) . "\n";
    });
    
    echo "\n4. 最终队列状态:\n";
    $finalStats = $queue->getDelayedQueueStats();
    echo "   延迟队列长度: {$finalStats['total_delayed_tasks']}\n";
    echo "   Stream队列长度: {$queue->getStreamLength()}\n";
    
    echo "\n5. 消费消息验证:\n";
    
    // 消费所有消息
    $consumedCount = 0;
    while ($message = $queue->consume()) {
        $consumedCount++;
        echo "   📥 消费消息 #{$consumedCount}: {$message['message']}\n";
        echo "      类型: {$message['type']}\n";
        echo "      延迟时间: " . ($message['original_delay'] ?? '无') . "秒\n";
        echo "      消息ID: {$message['id']}\n";
        echo "      " . str_repeat('-', 30) . "\n";
        
        // 确认消息
        $queue->ack($message['id']);
        
        // 防止消费过快
        usleep(500000); // 0.5秒
    }
    
    echo "\n=== 演示完成 ===\n";
    echo "总共消费了 {$consumedCount} 条消息\n";
    
} catch (Exception $e) {
    echo "❌ 错误: " . $e->getMessage() . "\n";
    echo "请确保:\n";
    echo "1. Redis 服务正在运行\n";
    echo "2. Redis 版本 >= 5.0\n";
    echo "3. 已安装 composer 依赖\n";
}