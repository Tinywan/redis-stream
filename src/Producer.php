<?php

declare(strict_types=1);

namespace Tinywan\RedisStream;

use Tinywan\RedisStream\Exception\RedisStreamException;

/**
 * 消息生产者类
 * 
 * 提供高级的消息发送接口，支持单条消息和批量消息发送
 * 封装了 RedisStreamQueue 的发送操作，提供更便捷的 API
 */
class Producer
{
    /** @var RedisStreamQueue Redis Stream 队列实例 */
    protected RedisStreamQueue $queue;

    /**
     * 构造函数
     * 
     * @param RedisStreamQueue $queue Redis Stream 队列实例
     */
    public function __construct(RedisStreamQueue $queue)
    {
        $this->queue = $queue;
    }

    /**
     * 发送单条消息
     * 
     * 将消息发送到 Redis Stream 队列中，支持立即发送和延迟发送
     * 
     * @param mixed $message 消息内容，可以是字符串、数组或对象
     * @param array $metadata 附加的元数据
     * @param int $delaySeconds 延迟秒数，0表示立即发送
     * @return string 返回生成的消息ID
     * @throws RedisStreamException 当消息发送失败时抛出异常
     */
    public function send($message, array $metadata = [], int $delaySeconds = 0): string
    {
        return $this->queue->send($message, $metadata, $delaySeconds);
    }

    /**
     * 批量发送消息
     * 
     * 支持多种格式的消息批量发送：
     * - 字符串数组：['message1', 'message2']
     * - 结构化数组：[['message' => 'content', 'metadata' => ['key' => 'value'], 'delay' => 30]]
     * 
     * @param array $messages 消息数组，支持 delay 参数控制延迟发送
     * @return array 返回生成的消息ID数组
     * @throws RedisStreamException 当消息发送失败时抛出异常
     */
    public function sendBatch(array $messages): array
    {
        $results = [];
        foreach ($messages as $message) {
            if (is_array($message)) {
                // 处理结构化消息
                $content = $message['message'] ?? '';
                $metadata = $message['metadata'] ?? [];
                $delaySeconds = $message['delay'] ?? 0;
                $results[] = $this->send($content, $metadata, $delaySeconds);
            } else {
                // 处理简单消息
                $results[] = $this->send((string)$message);
            }
        }
        return $results;
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