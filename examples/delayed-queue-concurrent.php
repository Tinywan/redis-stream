<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Tinywan\RedisStream\DelayedQueue;
use Tinywan\RedisStream\DelayedScheduler;

// 并发延时队列演示
// 展示多个生产者、调度器和消费者同时工作的情况

// 加载配置文件
$redisConfigs = require __DIR__ . '/../src/config/redis.php';
$queueConfigs = require __DIR__ . '/../src/config/redis-stream-queue.php';

// 环境配置
$enableDebug = getenv('REDIS_STREAM_DEBUG') === 'true' || in_array('--debug', $argv);

// 选择配置
$redisConfig = $redisConfigs['default'];
$delayedConfig = $queueConfigs['delayed_queue'];

$delayedConfig['debug'] = $enableDebug;

// 创建延时队列实例
$delayedQueue = DelayedQueue::getInstance($redisConfig, $delayedConfig);
$logger = $delayedQueue->getLogger();

echo "=== 并发延时队列演示 ===\n";
echo "调试模式: " . ($enableDebug ? '启用' : '禁用') . "\n";
echo "========================\n\n";

// 创建生产者进程
function createProducerProcess(int $producerId, DelayedQueue $queue): void
{
    $pid = pcntl_fork();
    
    if ($pid == -1) {
        die("Could not fork producer process\n");
    } elseif ($pid == 0) {
        // 子进程 - 生产者
        $queue->getLogger()->info("Producer $producerId started", ['pid' => getmypid()]);
        
        for ($i = 0; $i < 5; $i++) {
            $delay = rand(5, 60); // 5-60秒随机延时
            $messageData = [
                'producer_id' => $producerId,
                'message_number' => $i + 1,
                'data' => "Message from producer $producerId - #" . ($i + 1),
                'created_at' => microtime(true)
            ];
            
            try {
                $messageId = $queue->sendDelayed(json_encode($messageData), $delay, [
                    'producer_id' => $producerId,
                    'message_type' => 'concurrent_test'
                ]);
                
                echo "📤 Producer $producerId sent message with {$delay}s delay (ID: $messageId)\n";
                $queue->getLogger()->info('Producer sent message', [
                    'producer_id' => $producerId,
                    'message_number' => $i + 1,
                    'delay' => $delay,
                    'message_id' => $messageId
                ]);
                
            } catch (Throwable $e) {
                echo "❌ Producer $producerId failed to send message: " . $e->getMessage() . "\n";
                $queue->getLogger()->error('Producer failed to send message', [
                    'producer_id' => $producerId,
                    'error' => $e->getMessage()
                ]);
            }
            
            // 随机间隔发送消息
            sleep(rand(1, 3));
        }
        
        $queue->getLogger()->info("Producer $producerId finished", ['pid' => getmypid()]);
        exit(0);
    }
    
    return $pid;
}

// 创建调度器进程
function createSchedulerProcess(int $schedulerId, DelayedQueue $queue): void
{
    $pid = pcntl_fork();
    
    if ($pid == -1) {
        die("Could not fork scheduler process\n");
    } elseif ($pid == 0) {
        // 子进程 - 调度器
        $queue->getLogger()->info("Scheduler $schedulerId started", ['pid' => getmypid()]);
        
        $scheduler = new DelayedScheduler($queue, 1, 50, 32 * 1024 * 1024); // 32MB内存限制
        
        // 运行30秒后自动停止
        $startTime = time();
        $runtime = 30; // 运行30秒
        
        while (time() - $startTime < $runtime) {
            $transferred = $queue->runScheduler(10); // 最多处理10条消息
            if ($transferred > 0) {
                echo "⚙️  Scheduler $schedulerId transferred $transferred messages\n";
                $queue->getLogger()->info('Scheduler transferred messages', [
                    'scheduler_id' => $schedulerId,
                    'transferred' => $transferred
                ]);
            }
            sleep(1);
        }
        
        $queue->getLogger()->info("Scheduler $schedulerId finished", ['pid' => getmypid()]);
        exit(0);
    }
    
    return $pid;
}

// 创建消费者进程
function createConsumerProcess(int $consumerId, DelayedQueue $queue): void
{
    $pid = pcntl_fork();
    
    if ($pid == -1) {
        die("Could not fork consumer process\n");
    } elseif ($pid == 0) {
        // 子进程 - 消费者
        $queue->getLogger()->info("Consumer $consumerId started", ['pid' => getmypid()]);
        
        $processedCount = 0;
        $maxProcessing = 20; // 最多处理20条消息
        
        while ($processedCount < $maxProcessing) {
            $message = $queue->consume(function($message) use ($consumerId, &$processedCount, $queue) {
                $messageData = json_decode($message['message'], true);
                $processedCount++;
                
                echo "🔄 Consumer $consumerId processing message #{$processedCount}\n";
                echo "   From producer: {$messageData['producer_id']}\n";
                echo "   Message number: {$messageData['message_number']}\n";
                echo "   Attempts: {$message['attempts']}\n";
                
                // 模拟处理时间
                $processingTime = rand(1, 3);
                sleep($processingTime);
                
                $queue->getLogger()->info('Consumer processed message', [
                    'consumer_id' => $consumerId,
                    'processed_count' => $processedCount,
                    'producer_id' => $messageData['producer_id'],
                    'message_number' => $messageData['message_number'],
                    'processing_time' => $processingTime
                ]);
                
                return true;
            });
            
            if ($message === null) {
                echo "⏳ Consumer $consumerId waiting for messages...\n";
                sleep(2);
            }
        }
        
        $queue->getLogger()->info("Consumer $consumerId finished", [
            'pid' => getmypid(),
            'processed_count' => $processedCount
        ]);
        exit(0);
    }
    
    return $pid;
}

// 监控进程
function monitorProcesses(array $pids, DelayedQueue $queue): void
{
    $logger = $queue->getLogger();
    $startTime = time();
    $maxRuntime = 60; // 最大运行60秒
    
    echo "📊 Starting process monitor...\n";
    
    while (count($pids) > 0 && (time() - $startTime) < $maxRuntime) {
        $status = $queue->getStatus();
        
        echo "\n=== 状态报告 " . date('Y-m-d H:i:s') . " ===\n";
        echo "延时流长度: {$status['delayed_stream_length']}\n";
        echo "就绪流长度: {$status['ready_stream_length']}\n";
        echo "待处理消息: {$status['pending_count']}\n";
        echo "即将到期 (60s): {$queue->getUpcomingMessageCount(60)}\n";
        echo "运行中的进程: " . count($pids) . "\n";
        
        // 检查子进程状态
        foreach ($pids as $key => $pid) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result == -1 || $result > 0) {
                unset($pids[$key]);
                echo "🔚 Process $pid has finished\n";
            }
        }
        
        if (count($pids) > 0) {
            sleep(3);
        }
    }
    
    // 强制结束剩余进程
    foreach ($pids as $pid) {
        posix_kill($pid, SIGTERM);
        echo "🛑 Sent SIGTERM to process $pid\n";
    }
    
    $logger->info('Process monitoring completed', [
        'duration' => time() - $startTime,
        'final_status' => $queue->getStatus()
    ]);
}

// 主程序
function runConcurrentDemo(DelayedQueue $queue): void
{
    echo "🚀 Starting concurrent delayed queue demo...\n\n";
    
    $pids = [];
    
    // 创建2个生产者
    echo "📤 Starting 2 producer processes...\n";
    for ($i = 1; $i <= 2; $i++) {
        $pids[] = createProducerProcess($i, $queue);
    }
    
    // 等待生产者创建一些消息
    sleep(5);
    
    // 创建1个调度器
    echo "\n⚙️  Starting scheduler process...\n";
    $pids[] = createSchedulerProcess(1, $queue);
    
    // 等待调度器运行一段时间
    sleep(5);
    
    // 创建2个消费者
    echo "\n🔄 Starting 2 consumer processes...\n";
    for ($i = 1; $i <= 2; $i++) {
        $pids[] = createConsumerProcess($i, $queue);
    }
    
    // 监控所有进程
    echo "\n📊 Monitoring all processes...\n";
    monitorProcesses($pids, $queue);
    
    echo "\n✅ Concurrent demo completed!\n";
    
    // 显示最终统计
    $finalStatus = $queue->getStatus();
    echo "\n📊 Final Statistics:\n";
    echo "延时流长度: {$finalStatus['delayed_stream_length']}\n";
    echo "就绪流长度: {$finalStatus['ready_stream_length']}\n";
    echo "待处理消息: {$finalStatus['pending_count']}\n";
}

// 清理函数
function cleanupQueues(DelayedQueue $queue): void
{
    echo "\n🧹 Cleaning up queues...\n";
    
    // 清空延时流和就绪流（仅用于演示）
    try {
        $redis = $queue->getRedis();
        $redis->del($queue->getDelayedStreamName());
        $redis->del($queue->getReadyStreamName());
        echo "✅ Queues cleaned up\n";
    } catch (Throwable $e) {
        echo "❌ Failed to clean up queues: " . $e->getMessage() . "\n";
    }
}

// 主逻辑
if (isset($argv[1]) && $argv[1] === 'run') {
    // 检查pcntl扩展
    if (!function_exists('pcntl_fork')) {
        echo "❌ This demo requires the pcntl extension\n";
        echo "   Please enable pcntl extension in your PHP configuration\n";
        exit(1);
    }
    
    // 运行并发演示
    runConcurrentDemo($queue);
    
} elseif (isset($argv[1]) && $argv[1] === 'cleanup') {
    cleanupQueues($delayedQueue);
    
} elseif (isset($argv[1]) && $argv[1]) {
    echo "⚠️  Unknown command: {$argv[1]}\n";
    
} else {
    echo "📖 并发延时队列演示用法:\n";
    echo "  php delayed-queue-concurrent.php run      # 运行完整并发演示\n";
    echo "  php delayed-queue-concurrent.php cleanup  # 清理队列数据\n";
    echo "\n🔧 调试选项:\n";
    echo "  --debug                        # 启用调试模式\n";
    echo "  REDIS_STREAM_DEBUG=true        # 环境变量启用调试模式\n";
    echo "\n💡 演示说明:\n";
    echo "  这个演示展示多个生产者、调度器和消费者并发工作的情况\n";
    echo "  - 2个生产者进程：创建延时消息\n";
    echo "  - 1个调度器进程：将到期消息转移到就绪流\n";
    echo "  - 2个消费者进程：处理就绪消息\n";
    echo "  - 1个监控进程：监控所有进程和队列状态\n";
    echo "\n⚠️  注意事项:\n";
    echo "  - 需要pcntl扩展支持\n";
    echo "  - 演示运行约60秒后自动结束\n";
    echo "  - 可以使用cleanup命令清理测试数据\n";
}