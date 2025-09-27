<?php

declare(strict_types=1);

namespace Tinywan\RedisStream;

use Tinywan\RedisStream\Exception\RedisStreamException;
use Monolog\Logger;
use Throwable;

/**
 * 延时消息调度器
 * 
 * 负责定期检查延时流中的消息，将到期的消息转移到就绪流
 * 支持多进程和优雅停机
 */
class DelayedScheduler
{
    /** @var DelayedQueue 延时队列实例 */
    private DelayedQueue $delayedQueue;
    
    /** @var Logger 日志记录器 */
    private Logger $logger;
    
    /** @var bool 是否正在运行 */
    private bool $running = false;
    
    /** @var int 调度间隔（秒） */
    private int $interval;
    
    /** @var int 每次处理的最大消息数量 */
    private int $maxMessagesPerBatch;
    
    /** @var int 内存限制（字节） */
    private int $memoryLimit;
    
    /** @var int 上次调度时间 */
    private int $lastScheduledAt = 0;
    
    /** @var int 总调度次数 */
    private int $totalScheduled = 0;
    
    /** @var int 总转移消息数量 */
    private int $totalTransferred = 0;
    
    /** @var int 启动时间 */
    private int $startTime;

    /**
     * 构造函数
     * 
     * @param DelayedQueue $delayedQueue 延时队列实例
     * @param int $interval 调度间隔（秒）
     * @param int $maxMessagesPerBatch 每次处理的最大消息数量
     * @param int $memoryLimit 内存限制（字节）
     */
    public function __construct(
        DelayedQueue $delayedQueue,
        int $interval = 1,
        int $maxMessagesPerBatch = 100,
        int $memoryLimit = 128 * 1024 * 1024 // 128MB
    ) {
        $this->delayedQueue = $delayedQueue;
        $this->logger = $delayedQueue->getLogger();
        $this->interval = $interval;
        $this->maxMessagesPerBatch = $maxMessagesPerBatch;
        $this->memoryLimit = $memoryLimit;
        $this->startTime = time();
    }

    /**
     * 启动调度器
     * 
     * @return void
     * @throws RedisStreamException 当启动失败时抛出异常
     */
    public function start(): void
    {
        if ($this->running) {
            $this->logger->warning('Scheduler is already running');
            return;
        }

        $this->running = true;
        $this->startTime = time();
        
        $this->logger->info('Delayed scheduler started', [
            'interval' => $this->interval,
            'max_messages_per_batch' => $this->maxMessagesPerBatch,
            'memory_limit' => $this->formatBytes($this->memoryLimit),
            'pid' => getmypid()
        ]);

        // 注册信号处理器
        $this->registerSignalHandlers();

        try {
            while ($this->running) {
                $this->tick();
            }
        } catch (Throwable $e) {
            $this->logger->error('Scheduler crashed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'total_scheduled' => $this->totalScheduled,
                'total_transferred' => $this->totalTransferred
            ]);
            throw new RedisStreamException('Scheduler crashed: ' . $e->getMessage(), 0, $e);
        }

        $this->logger->info('Scheduler stopped gracefully', [
            'total_scheduled' => $this->totalScheduled,
            'total_transferred' => $this->totalTransferred,
            'uptime_seconds' => time() - $this->startTime
        ]);
    }

    /**
     * 停止调度器
     * 
     * @return void
     */
    public function stop(): void
    {
        $this->logger->info('Stopping scheduler...');
        $this->running = false;
    }

    /**
     * 执行一次调度循环
     * 
     * @return void
     */
    public function tick(): void
    {
        // 检查内存使用
        if ($this->isMemoryLimitExceeded()) {
            $this->logger->warning('Memory limit exceeded, stopping scheduler');
            $this->stop();
            return;
        }

        $currentTime = time();
        
        // 检查是否到了调度时间
        if ($currentTime - $this->lastScheduledAt >= $this->interval) {
            $this->schedule();
            $this->lastScheduledAt = $currentTime;
        }

        // 休眠一小段时间以避免CPU占用过高
        usleep(100000); // 100ms
    }

    /**
     * 执行调度逻辑
     * 
     * @return void
     */
    protected function schedule(): void
    {
        $startTime = microtime(true);
        
        try {
            $transferred = $this->delayedQueue->runScheduler($this->maxMessagesPerBatch);
            $this->totalScheduled++;
            $this->totalTransferred += $transferred;
            
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);
            
            $this->logger->info('Schedule cycle completed', [
                'cycle' => $this->totalScheduled,
                'transferred' => $transferred,
                'duration_ms' => $duration,
                'total_transferred' => $this->totalTransferred,
                'memory_usage' => $this->formatBytes(memory_get_usage(true))
            ]);
            
        } catch (Throwable $e) {
            $this->logger->error('Schedule cycle failed', [
                'cycle' => $this->totalScheduled,
                'error' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
        }
    }

    /**
     * 注册信号处理器
     * 
     * @return void
     */
    protected function registerSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGHUP, [$this, 'handleSignal']);
            
            $this->logger->info('Signal handlers registered');
        } else {
            $this->logger->warning('pcntl extension not available, signal handling disabled');
        }
    }

    /**
     * 处理系统信号
     * 
     * @param int $signal 信号编号
     * @return void
     */
    public function handleSignal(int $signal): void
    {
        switch ($signal) {
            case SIGTERM:
            case SIGINT:
                $this->logger->info('Received shutdown signal, stopping gracefully');
                $this->stop();
                break;
            case SIGHUP:
                $this->logger->info('Received HUP signal, reloading configuration');
                // 可以在这里添加配置重载逻辑
                break;
        }
    }

    /**
     * 检查内存限制是否超出
     * 
     * @return bool
     */
    protected function isMemoryLimitExceeded(): bool
    {
        return memory_get_usage(true) >= $this->memoryLimit;
    }

    /**
     * 格式化字节数
     * 
     * @param int $bytes 字节数
     * @return string 格式化后的字符串
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * 获取调度器状态
     * 
     * @return array
     */
    public function getStatus(): array
    {
        $queueStatus = $this->delayedQueue->getStatus();
        
        return [
            'running' => $this->running,
            'interval' => $this->interval,
            'max_messages_per_batch' => $this->maxMessagesPerBatch,
            'memory_limit' => $this->formatBytes($this->memoryLimit),
            'memory_usage' => $this->formatBytes(memory_get_usage(true)),
            'memory_usage_percentage' => round((memory_get_usage(true) / $this->memoryLimit) * 100, 2),
            'total_scheduled' => $this->totalScheduled,
            'total_transferred' => $this->totalTransferred,
            'last_scheduled_at' => $this->lastScheduledAt > 0 ? date('Y-m-d H:i:s', $this->lastScheduledAt) : 'never',
            'uptime_seconds' => time() - $this->startTime,
            'pid' => getmypid(),
            'queue_status' => $queueStatus
        ];
    }

    /**
     * 设置调度间隔
     * 
     * @param int $interval 调度间隔（秒）
     * @return void
     */
    public function setInterval(int $interval): void
    {
        $this->interval = max(1, $interval);
        $this->logger->info('Scheduler interval updated', ['interval' => $this->interval]);
    }

    /**
     * 设置每批次最大消息数量
     * 
     * @param int $maxMessages 最大消息数量
     * @return void
     */
    public function setMaxMessagesPerBatch(int $maxMessages): void
    {
        $this->maxMessagesPerBatch = max(1, $maxMessages);
        $this->logger->info('Max messages per batch updated', ['max_messages' => $this->maxMessagesPerBatch]);
    }

    /**
     * 设置内存限制
     * 
     * @param int $memoryLimit 内存限制（字节）
     * @return void
     */
    public function setMemoryLimit(int $memoryLimit): void
    {
        $this->memoryLimit = max(64 * 1024 * 1024, $memoryLimit); // 最小64MB
        $this->logger->info('Memory limit updated', ['memory_limit' => $this->formatBytes($this->memoryLimit)]);
    }

    /**
     * 获取日志记录器
     * 
     * @return Logger
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }

    /**
     * 获取延时队列实例
     * 
     * @return DelayedQueue
     */
    public function getDelayedQueue(): DelayedQueue
    {
        return $this->delayedQueue;
    }
}