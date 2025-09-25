<?php

declare(strict_types=1);

namespace Tinywan\RedisStream;

/**
 * 消息处理器接口
 * 
 * 定义了消息处理的标准接口，实现该接口的类可以作为消息处理器
 * 用于消费者中的回调函数，提供更灵活的消息处理方式
 */
interface MessageHandlerInterface
{
    /**
     * 处理消息
     * 
     * 实现此方法来定义具体的消息处理逻辑
     * 
     * @param mixed $message 要处理的消息数据
     * @return mixed 处理结果，返回 true 或 null 会自动确认消息
     *                返回 false 会触发重试机制
     *                抛出异常也会触发重试机制
     */
    public function handle($message);
}