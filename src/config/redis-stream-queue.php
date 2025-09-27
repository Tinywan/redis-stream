<?php
/**
 * Redis Stream Queue Configuration Examples
 *
 * 此文件包含各种使用场景下的队列配置示例
 *
 * 使用方法：
 * 1. 复制所需的配置到您的代码中
 * 2. 根据实际需求调整参数
 * 3. 传入 RedisStreamQueue::getInstance() 方法
 *
 * 配置参数说明
 * stream_name: Redis Stream 的名称，用于标识不同的消息流
 * consumer_group: 消费者组名称，支持多个消费者协同处理同一个流
 * consumer_name: 消费者名称，通常包含进程ID以便区分不同的消费者实例
 * block_timeout: 阻塞超时时间（毫秒），消费者在没有消息时的等待时间
 * retry_attempts: 消息处理失败时的最大重试次数
 * retry_delay: 重试延迟时间（毫秒），每次重试之间的间隔时间
 * debug: 是否启用调试模式，启用时会记录详细的日志信息到文件
 */

return [
    /** 默认配置 */
    'default' => [
        'stream_name' => 'default_queue',           // 流名称
        'consumer_group' => 'default_group',        // 消费者组名称
        'consumer_name' => 'default_consumer_' . getmypid(), // 消费者名称
        'block_timeout' => 5000,                  // 阻塞超时时间（毫秒）
        'retry_attempts' => 3,                    // 重试次数
        'retry_delay' => 1000,                    // 重试延迟（毫秒）
        'debug' => false,                        // 是否启用调试模式
    ],
    /** 自定义自己配置 */
    'custom' => [],
    
    /** 延时队列配置 */
    'delayed_queue' => [
        'delayed_stream_name' => 'delayed_tasks',           // 延时流名称
        'ready_stream_name' => 'ready_tasks',               // 就绪流名称
        'consumer_group' => 'delayed_group',               // 消费者组名称
        'consumer_name' => 'delayed_consumer_' . getmypid(), // 消费者名称
        'block_timeout' => 5000,                           // 阻塞超时时间（毫秒）
        'retry_attempts' => 3,                             // 重试次数
        'retry_delay' => 1000,                             // 重试延迟（毫秒）
        'debug' => false,                                   // 是否启用调试模式
        'scheduler_interval' => 1,                         // 调度器间隔时间（秒）
        'max_batch_size' => 100,                           // 每次处理最大批次大小
    ],
    
    /** 高优先级延时队列配置 */
    'high_priority_delayed' => [
        'delayed_stream_name' => 'high_priority_delayed',  // 延时流名称
        'ready_stream_name' => 'high_priority_ready',       // 就绪流名称
        'consumer_group' => 'high_priority_group',         // 消费者组名称
        'consumer_name' => 'high_priority_consumer_' . getmypid(), // 消费者名称
        'block_timeout' => 1000,                           // 更短的阻塞超时
        'retry_attempts' => 5,                             // 更多重试次数
        'retry_delay' => 500,                              // 更短的重试延迟
        'debug' => false,                                  // 是否启用调试模式
        'scheduler_interval' => 0.5,                       // 更频繁的调度
        'max_batch_size' => 50,                            // 较小的批次大小
    ],
    
    /** 低优先级延时队列配置 */
    'low_priority_delayed' => [
        'delayed_stream_name' => 'low_priority_delayed',   // 延时流名称
        'ready_stream_name' => 'low_priority_ready',       // 就绪流名称
        'consumer_group' => 'low_priority_group',          // 消费者组名称
        'consumer_name' => 'low_priority_consumer_' . getmypid(), // 消费者名称
        'block_timeout' => 10000,                          // 更长的阻塞超时
        'retry_attempts' => 2,                             // 较少的重试次数
        'retry_delay' => 5000,                             // 更长的重试延迟
        'debug' => false,                                  // 是否启用调试模式
        'scheduler_interval' => 5,                         // 较少频繁的调度
        'max_batch_size' => 200,                           // 较大的批次大小
    ],

];

