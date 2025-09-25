<?php

declare(strict_types=1);

namespace Tinywan\RedisStream\Exception;

use Exception;

/**
 * Redis Stream 异常类
 * 
 * Redis Stream 队列操作的自定义异常类
 * 继承自 PHP 的 Exception 类，用于处理队列相关的错误情况
 * 包括 Redis 连接失败、消息发送失败、消费失败等情况
 */
class RedisStreamException extends Exception
{
}