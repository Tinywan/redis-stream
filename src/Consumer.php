<?php

declare(strict_types=1);

namespace Tinywan\RedisStream;

use Tinywan\RedisStream\Exception\RedisStreamException;

/**
 * 消息消费者类
 * 
 * 提供高级的消息消费接口，支持单次消费和持续运行模式
 * 包含内存管理、错误处理和自动重试机制
 */
class Consumer
{
    /** @var RedisStreamQueue Redis Stream 队列实例 */
    protected RedisStreamQueue $queue;
    
    /** @var callable|null 消息处理回调函数 */
    protected $callback = null;
    
    /** @var bool 消费者运行状态标志 */
    protected bool $running = false;
    
    /** @var int 内存限制（字节），默认 128MB */
    protected $memoryLimit = 128 * 1024 * 1024; // 128MB

    /**
     * 构造函数
     * 
     * @param RedisStreamQueue $queue Redis Stream 队列实例
     * @param callable|MessageHandlerInterface|null $handler 消息处理器，可以是回调函数或实现了 MessageHandlerInterface 的对象
     */
    public function __construct(RedisStreamQueue $queue, $handler = null)
    {
        $this->queue = $queue;
        
        if ($handler instanceof MessageHandlerInterface) {
            // 如果是 MessageHandlerInterface 实例，转换为回调函数
            $this->callback = function($message) use ($handler) {
                return $handler->handle($message);
            };
        } else {
            // 否则作为普通回调函数
            $this->callback = $handler;
        }
    }

    /**
     * 设置消息处理器
     * 
     * @param callable|MessageHandlerInterface $handler 消息处理器，可以是回调函数或实现了 MessageHandlerInterface 的对象
     * @return self 返回当前实例，支持链式调用
     */
    public function setCallback($handler): self
    {
        if ($handler instanceof MessageHandlerInterface) {
            // 如果是 MessageHandlerInterface 实例，转换为回调函数
            $this->callback = function($message) use ($handler) {
                return $handler->handle($message);
            };
        } else {
            // 否则作为普通回调函数
            $this->callback = $handler;
        }
        
        return $this;
    }

    /**
     * 单次消费消息
     *
     * 从队列中消费一条消息，支持自定义回调函数
     * 如果回调函数返回 true 或 null，则自动确认消息
     * 如果回调函数抛出异常，则返回 false，消息会被重试
     *
     * @param callable|null $callback 消息处理回调函数，如果为 null 则使用构造函数设置的回调
     * @return array|null 返回消息数据，如果没有消息则返回 null
     * @throws RedisStreamException
     */
    public function consume(?callable $callback = null): ?array
    {
        $callback = $callback ?? $this->callback;
        
        // 如果没有回调函数，直接返回消息
        if ($callback === null) {
            return $this->queue->consume();
        }
        
        // 如果有回调函数，处理消息并返回结果
        return $this->queue->consume(function($message) use ($callback) {
            try {
                $result = $callback($message);
                // 如果回调返回 true 或 null，则确认消息
                return $result === true || $result === null;
            } catch (\Throwable $e) {
                // 记录错误信息
                $this->queue->getLogger()->error('Consumer callback error', [
                    'message_id' => $message['id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
                // 返回 false，触发重试机制
                return false;
            }
        });
    }

    /**
     * 持续运行消费者
     * 
     * 进入无限循环，持续从队列中消费消息
     * 包含错误处理、内存管理和优雅停止功能
     * 
     * @param callable|null $callback 消息处理回调函数，如果为 null 则使用构造函数设置的回调
     * @return void
     */
    public function run(?callable $callback = null): void
    {
        $this->running = true;
        $callback = $callback ?? $this->callback;
        
        // 记录消费者启动日志
        $this->queue->getLogger()->info('Consumer started', [
            'consumer' => $this->queue->getConsumerName(),
            'group' => $this->queue->getConsumerGroup(),
            'stream' => $this->queue->getStreamName()
        ]);

        // 主循环
        while ($this->running) {
            try {
                // 消费消息
                $message = $this->consume($callback);
                
                // 如果没有消息，短暂休眠
                if ($message === null) {
                    usleep(100000); // 100ms sleep when no message
                    continue;
                }

                // 检查内存限制
                $this->checkMemoryLimit();
                
            } catch (\Throwable $e) {
                // 记录运行时错误
                $this->queue->getLogger()->error('Consumer runtime error', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // 如果仍在运行状态，等待1秒后重试
                if ($this->running) {
                    sleep(1); // Wait before retrying
                }
            }
        }

        // 记录消费者停止日志
        $this->queue->getLogger()->info('Consumer stopped');
    }

    /**
     * 停止消费者
     * 
     * 设置运行状态为 false，让消费者优雅停止
     * 
     * @return void
     */
    public function stop(): void
    {
        $this->running = false;
        $this->queue->getLogger()->info('Consumer stop signal received');
    }

    /**
     * 设置内存限制
     * 
     * 当内存使用超过限制时，消费者会自动停止
     * 
     * @param int $limit 内存限制（字节）
     * @return self 返回当前实例，支持链式调用
     */
    public function setMemoryLimit(int $limit): self
    {
        $this->memoryLimit = $limit;
        return $this;
    }

    /**
     * 检查内存限制
     * 
     * 如果当前内存使用超过限制，则停止消费者
     * 
     * @return void
     */
    protected function checkMemoryLimit(): void
    {
        $memoryUsage = memory_get_usage(true);
        if ($memoryUsage > $this->memoryLimit) {
            $this->queue->getLogger()->warning('Memory limit reached', [
                'usage' => $memoryUsage,
                'limit' => $this->memoryLimit
            ]);
            $this->stop();
        }
    }

    /**
     * 检查消费者是否正在运行
     * 
     * @return bool 如果正在运行返回 true，否则返回 false
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * 获取队列实例
     * 
     * @return RedisStreamQueue Redis Stream 队列实例
     */
    public function getQueue(): RedisStreamQueue
    {
        return $this->queue;
    }
}