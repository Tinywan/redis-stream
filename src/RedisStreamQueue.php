<?php

declare(strict_types=1);

namespace Tinywan\RedisStream;

use Tinywan\RedisStream\Exception\RedisStreamException;
use Monolog\Logger;
use Redis;
use Throwable;

/**
 * Redis Stream 队列核心类
 * 
 * 基于 Redis Stream 实现的轻量级消息队列，支持多个生产者和消费者
 * 提供消息持久化、确认机制、重试机制和可靠投递
 * 同时支持延时队列功能，可在同一类中处理即时和延时消息
 */
class RedisStreamQueue
{
    /** @var array 存储单例实例，key为配置标识符 */
    private static array $instances = [];
    
    /** @var RedisConnectionPool Redis 连接池 */
    private RedisConnectionPool $connectionPool;
    
    /** @var Redis Redis 客户端实例 */
    private Redis $redis;

    /** @var Logger Monolog logger instance */
    protected Logger $logger;
    
    /** @var array Redis 配置数组 */
    protected array $redisConfig;
    
    /** @var array 队列配置数组 */
    protected array $queueConfig;
    
    /** @var string 流名称 */
    protected string $streamName;
    
    /** @var string 消费者组名称 */
    protected string $consumerGroup;
    
    /** @var string 消费者名称 */
    protected string $consumerName;
    
    /** @var string 延时流名称（基于主流名称自动生成） */
    protected string $delayedStreamName;

    /**
     * 获取单例实例
     * 
     * @param array $redisConfig Redis 连接配置
     * @param array $queueConfig 队列配置
     * @param Logger|null $logger Monolog logger instance, defaults to file logger if enabled
     * @return static RedisStreamQueue 实例
     * @throws RedisStreamException 当连接失败时抛出异常
     */
    public static function getInstance(
        array $redisConfig = [],
        array $queueConfig = [],
        ?Logger $logger = null
    ): self {
        // 生成配置的唯一标识符
        $instanceKey = self::generateInstanceKey($redisConfig, $queueConfig);
        
        // 如果实例不存在，创建新实例
        if (!isset(self::$instances[$instanceKey])) {
            self::$instances[$instanceKey] = new self($redisConfig, $queueConfig, $logger);
        }
        
        return self::$instances[$instanceKey];
    }
    
    /**
     * 构造函数（私有）
     * 
     * @param array $redisConfig Redis 连接配置
     * @param array $queueConfig 队列配置
     * @param Logger|null $logger Monolog logger instance, defaults to file logger if enabled
     * @throws RedisStreamException 当 Redis 连接失败时抛出异常
     */
    private function __construct(
        array $redisConfig = [],
        array $queueConfig = [],
        ?Logger $logger = null
    ) {
        // 合并默认配置
        $this->redisConfig = array_merge([
            'host' => '127.0.0.1',          // Redis 主机地址
            'port' => 6379,                 // Redis 端口
            'password' => null,             // Redis 密码
            'database' => 0,                // Redis 数据库
            'timeout' => 5,                 // 连接超时时间（秒）
        ], $redisConfig);

        $this->queueConfig = array_merge([
            'stream_name' => 'redis_stream_queue',    // 流名称
            'consumer_group' => 'redis_stream_group', // 消费者组名称
            'consumer_name' => 'consumer_' . getmypid(), // 消费者名称
            'block_timeout' => 5000,        // 阻塞超时时间（毫秒）
            'retry_attempts' => 3,          // 重试次数
            'retry_delay' => 1000,          // 重试延迟（毫秒）
            'debug' => false,               // 是否启用调试模式（启用时记录日志）
            'delayed_queue_suffix' => '_delayed', // 延时流名称后缀
            'scheduler_interval' => 1,      // 调度器间隔时间（秒）
            'max_batch_size' => 100,        // 每次处理最大批次大小
        ], $queueConfig);

        $this->streamName = $this->queueConfig['stream_name'];
        $this->consumerGroup = $this->queueConfig['consumer_group'];
        $this->consumerName = $this->queueConfig['consumer_name'];
        $this->delayedStreamName = $this->streamName . $this->queueConfig['delayed_queue_suffix'];
        $this->logger = $logger ?? MonologFactory::createLogger('redis-stream', $this->queueConfig['debug']);
        
        // 初始化连接池
        $this->connectionPool = RedisConnectionPool::getInstance();

        // 从连接池获取 Redis 连接并确保消费者组存在
        $this->redis = $this->connectionPool->getConnection($this->redisConfig);
        $this->ensureConsumerGroup();
    }

    /**
     * 确保消费者组存在
     * 
     * 检查流和消费者组是否存在，如果不存在则创建
     * 
     * @return void
     * @throws RedisStreamException 当消费者组创建失败时抛出异常
     */
    protected function ensureConsumerGroup(): void
    {
        try {
            // 检查流是否存在
            $streamExists = $this->redis->exists($this->streamName);
            
            if (!$streamExists) {
                // 如果流不存在，创建流和消费者组
                $this->redis->xGroup('CREATE', $this->streamName, $this->consumerGroup, '0', true);
                $this->logger->info('Consumer group created with stream', [
                    'stream' => $this->streamName,
                    'group' => $this->consumerGroup
                ]);
            } else {
                // 如果流存在但消费者组不存在，创建消费者组
                try {
                    $this->redis->xGroup('CREATE', $this->streamName, $this->consumerGroup, '0', true);
                    $this->logger->info('Consumer group created for existing stream', [
                        'stream' => $this->streamName,
                        'group' => $this->consumerGroup
                    ]);
                } catch (Throwable $e) {
                    // 如果不是消费者组已存在的错误，则抛出异常
                    if (strpos($e->getMessage(), 'BUSYGROUP') === false) {
                        $this->logger->error('Failed to create consumer group for existing stream', ['error' => $e->getMessage()]);
                        throw new RedisStreamException('Failed to create consumer group: ' . $e->getMessage(), 0, $e);
                    }
                }
            }
        } catch (Throwable $e) {
            // 如果不是消费者组已存在的错误，则抛出异常
            if (strpos($e->getMessage(), 'BUSYGROUP') === false) {
                $this->logger->error('Failed to create consumer group', ['error' => $e->getMessage()]);
                throw new RedisStreamException('Failed to create consumer group: ' . $e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * 发送消息到队列
     * 
     * 将消息添加到 Redis Stream 中，自动添加时间戳、重试次数等元数据
     * 支持延时发送和指定时间发送
     * 
     * @param mixed $message 消息内容，可以是字符串、数组或对象
     * @param array $metadata 附加的元数据，会合并到消息中
     * @param int $delayOrTimestamp 延时秒数或指定时间戳：
     *                              - 0 表示立即执行
     *                              - 正整数且小于当前时间戳 表示延时秒数（支持任意时长）
     *                              - 大于当前时间戳 表示指定时间戳
     * @return string 返回生成的消息ID
     * @throws RedisStreamException 当消息发送失败时抛出异常
     */
    public function send($message, array $metadata = [], int $delayOrTimestamp = 0): string
    {
        try {
            $currentTime = time();
            
            // 判断参数类型：延时秒数还是指定时间戳
            if ($delayOrTimestamp <= 0) {
                // 立即执行
                $delaySeconds = 0;
            } elseif ($delayOrTimestamp < $currentTime) {
                // 延时秒数（支持任意时长，1年、10年都可以）
                $delaySeconds = $delayOrTimestamp;
            } else {
                // 指定时间戳（大于当前时间戳）
                $delaySeconds = max(0, $delayOrTimestamp - $currentTime);
            }

            if ($delaySeconds < 0) {
                throw new RedisStreamException('Delay time must be greater than or equal to 0');
            }

            // 根据延时参数决定发送到哪个流
            if ($delaySeconds > 0) {
                return $this->sendToDelayedStream($message, $delaySeconds, $metadata);
            } else {
                return $this->sendToMainStream($message, $metadata);
            }
        } catch (Throwable $e) {
            $this->logger->error('Failed to send message', ['error' => $e->getMessage()]);
            throw new RedisStreamException('Failed to send message: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 发送消息到主队列（立即执行）
     * 
     * @param mixed $message 消息内容
     * @param array $metadata 附加元数据
     * @return string 消息ID
     */
    private function sendToMainStream($message, array $metadata = []): string
    {
        // 确保消费者组存在
        $this->ensureConsumerGroup();
        
        // 构建消息数据
        $data = array_merge([
            'message' => is_string($message) ? $message : json_encode($message),
            'timestamp' => time(),
            'attempts' => 0,
            'status' => 'pending'
        ], $metadata);

        // 添加到主队列
        $messageId = $this->redis->xAdd($this->streamName, '*', $data);
        
        $this->logger->info('Message sent to main stream', [
            'message_id' => $messageId,
            'stream' => $this->streamName
        ]);

        return $messageId;
    }

    /**
     * 发送消息到延时队列
     * 
     * @param mixed $message 消息内容
     * @param int $delaySeconds 延时秒数
     * @param array $metadata 附加元数据
     * @return string 消息ID
     */
    private function sendToDelayedStream($message, int $delaySeconds, array $metadata = []): string
    {
        $executeTime = time() + $delaySeconds;
        
        // 构建延时消息数据
        $data = array_merge([
            'message' => is_string($message) ? $message : json_encode($message),
            'execute_time' => $executeTime,
            'delay_seconds' => $delaySeconds,
            'created_at' => time(),
            'attempts' => 0,
            'status' => 'delayed'
        ], $metadata);

        // 添加到延时流
        $messageId = $this->redis->xAdd($this->delayedStreamName, '*', $data);
        
        $this->logger->info('Delayed message sent', [
            'message_id' => $messageId,
            'delay_seconds' => $delaySeconds,
            'execute_time' => date('Y-m-d H:i:s', $executeTime),
            'stream' => $this->delayedStreamName
        ]);

        return $messageId;
    }

    /**
     * 从队列中消费消息
     * 
     * 使用消费者组从 Redis Stream 中读取消息，支持回调函数处理
     * 如果回调函数返回 true，则自动确认消息
     * 
     * @param callable|null $callback 消息处理回调函数，接收消息数据作为参数
     * @return array|null 返回消息数据，如果没有消息则返回 null
     * @throws RedisStreamException 当消息消费失败时抛出异常
     */
    public function consume(?callable $callback = null): ?array
    {
        try {
            // 从消费者组中读取消息
            $messages = $this->redis->xReadGroup(
                $this->consumerGroup,          // 消费者组
                $this->consumerName,           // 消费者名称
                [$this->streamName => '>'],    // 从新消息开始读取
                1,                             // 每次读取一条消息
                $this->queueConfig['block_timeout'] // 阻塞超时时间
            );

            // 如果没有消息，返回 null
            if (empty($messages)) {
                return null;
            }

            // 处理消息数据
            $messageData = $this->processMessage($messages[$this->streamName]);
            
            // 如果提供了回调函数，执行处理逻辑
            if ($callback !== null) {
                $result = $callback($messageData);
                // 如果回调返回 true，自动确认消息
                if ($result === true) {
                    $this->ack($messageData['id']);
                }
            }

            return $messageData;
        } catch (Throwable $e) {
            $this->logger->error('Failed to consume message', ['error' => $e->getMessage()]);
            throw new RedisStreamException('Failed to consume message: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 处理原始消息数据
     * 
     * 从 Redis 原始消息中提取数据，增加消息ID和重试计数
     * 
     * @param array $rawMessages 从 Redis 读取的原始消息数组
     * @return array 处理后的消息数据，包含 id、attempts 等字段
     * @throws RedisStreamException 当没有找到有效消息时抛出异常
     */
    protected function processMessage(array $rawMessages): array
    {
        foreach ($rawMessages as $messageId => $data) {
            // 将消息ID添加到数据中
            $data['id'] = $messageId;
            // 增加重试计数
            $data['attempts'] = isset($data['attempts']) ? (int)$data['attempts'] + 1 : 1;
            
            $this->logger->info('Processing message', [
                'message_id' => $messageId,
                'attempts' => $data['attempts']
            ]);

            return $data;
        }
        
        throw new RedisStreamException('No valid message found');
    }

    /**
     * 确认消息处理完成
     * 
     * 从消费者组的待处理消息列表中移除指定消息
     * 
     * @param string $messageId 要确认的消息ID
     * @return bool 确认成功返回 true，失败返回 false
     * @throws RedisStreamException 当确认操作失败时抛出异常
     */
    public function ack(string $messageId): bool
    {
        try {
            // 从消费者组中确认消息
            $result = $this->redis->xAck($this->streamName, $this->consumerGroup, [$messageId]);
            
            $this->logger->info('Message acknowledged', [
                'message_id' => $messageId,
                'stream' => $this->streamName
            ]);

            return $result > 0;
        } catch (Throwable $e) {
            $this->logger->error('Failed to acknowledge message', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            throw new RedisStreamException('Failed to acknowledge message: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 拒绝消息处理
     * 
     * 处理失败的消息，可以选择重试或直接删除
     * 如果重试次数未超过限制，重新加入队列；否则删除消息
     * 
     * @param string $messageId 要拒绝的消息ID
     * @param bool $retry 是否重试，默认为 true
     * @return bool 处理成功返回 true
     * @throws RedisStreamException 当拒绝操作失败时抛出异常
     */
    public function nack(string $messageId, bool $retry = true): bool
    {
        try {
            if ($retry) {
                // 首先检查消息是否在 pending 状态
                $pendingInfo = $this->redis->xPending($this->streamName, $this->consumerGroup, '-', '+', 1, $messageId);
                
                if (!empty($pendingInfo)) {
                    // 消息在 pending 状态，需要先确认它确实存在
                    $pendingMessage = $pendingInfo[0];
                    if ($pendingMessage[0] === $messageId) {
                        // 从 pending 状态中移除消息并重新加入队列
                        $this->redis->xAck($this->streamName, $this->consumerGroup, [$messageId]);
                        
                        // 获取消息内容（如果消息还在 stream 中）
                        $message = $this->redis->xRange($this->streamName, $messageId, $messageId);
                        
                        if (isset($message[$messageId])) {
                            $data = $message[$messageId];
                            $data['attempts'] = isset($data['attempts']) ? (int)$data['attempts'] + 1 : 1;
                            
                            // 如果重试次数未超过限制，重新加入队列
                            if ($data['attempts'] <= $this->queueConfig['retry_attempts']) {
                                // 删除原消息
                                $this->redis->xDel($this->streamName, [$messageId]);
                                // 重新添加到队列
                                $this->redis->xAdd($this->streamName, '*', $data);
                                
                                $this->logger->info('Pending message retry enqueued', [
                                    'message_id' => $messageId,
                                    'attempts' => $data['attempts'],
                                    'pending_time' => $pendingMessage[4] // 最后传递时间
                                ]);
                                
                                return true;
                            }
                        } else {
                            // 消息已不在 stream 中，但仍在 pending 中
                            $this->logger->warning('Pending message not found in stream', [
                                'message_id' => $messageId,
                                'pending_info' => $pendingMessage
                            ]);
                        }
                    }
                } else {
                    // 消息不在 pending 状态，直接处理
                    $message = $this->redis->xRange($this->streamName, $messageId, $messageId);
                    
                    if (isset($message[$messageId])) {
                        $data = $message[$messageId];
                        $data['attempts'] = isset($data['attempts']) ? (int)$data['attempts'] + 1 : 1;
                        
                        // 如果重试次数未超过限制，重新加入队列
                        if ($data['attempts'] <= $this->queueConfig['retry_attempts']) {
                            // 删除原消息
                            $this->redis->xDel($this->streamName, [$messageId]);
                            // 重新添加到队列
                            $this->redis->xAdd($this->streamName, '*', $data);
                            
                            $this->logger->info('Message retry enqueued', [
                                'message_id' => $messageId,
                                'attempts' => $data['attempts']
                            ]);
                            
                            return true;
                        }
                    }
                }
            }

            // 删除消息（不重试或重试次数超限）
            $this->redis->xDel($this->streamName, [$messageId]);
            
            $this->logger->info('Message removed (failed)', [
                'message_id' => $messageId,
                'retry' => $retry
            ]);

            return true;
        } catch (Throwable $e) {
            $this->logger->error('Failed to NACK message', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            throw new RedisStreamException('Failed to NACK message: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 获取待处理消息数量
     * 
     * 返回消费者组中待处理的消息数量
     * 
     * @return int 待处理消息数量，出错时返回 0
     */
    public function getPendingCount(): int
    {
        try {
            $pending = $this->redis->xPending($this->streamName, $this->consumerGroup);
            return $pending[0] ?? 0;
        } catch (Throwable $e) {
            $this->logger->error('Failed to get pending count', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 获取流长度
     * 
     * 返回 Redis Stream 中的消息总数
     * 
     * @return int 流中的消息数量，出错时返回 0
     */
    public function getStreamLength(): int
    {
        try {
            return $this->redis->xLen($this->streamName);
        } catch (Throwable $e) {
            $this->logger->error('Failed to get stream length', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 获取 Redis 客户端实例
     * 
     * @return Redis Redis 客户端实例
     */
    public function getRedis(): Redis
    {
        return $this->redis;
    }

    /**
     * 获取流名称
     * 
     * @return string 流名称
     */
    public function getStreamName(): string
    {
        return $this->streamName;
    }

    /**
     * 获取消费者组名称
     * 
     * @return string 消费者组名称
     */
    public function getConsumerGroup(): string
    {
        return $this->consumerGroup;
    }

    /**
     * 获取消费者名称
     * 
     * @return string 消费者名称
     */
    public function getConsumerName(): string
    {
        return $this->consumerName;
    }

    /**
     * 获取日志记录器
     * 
     * @return Logger Monolog logger instance
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }

    /**
     * 获取 Redis 配置
     * 
     * @return array Redis 连接配置
     */
    public function getRedisConfig(): array
    {
        return $this->redisConfig;
    }

    /**
     * 获取队列配置
     * 
     * @return array 队列配置
     */
    public function getQueueConfig(): array
    {
        return $this->queueConfig;
    }

    /**
     * 获取完整配置
     * 
     * @return array 完整配置数组
     */
    public function getConfig(): array
    {
        return [
            'redis' => $this->redisConfig,
            'queue' => $this->queueConfig
        ];
    }
    
    /**
     * 获取连接池状态
     * 
     * @return array 连接池状态信息
     */
    public function getConnectionPoolStatus(): array
    {
        return $this->connectionPool->getPoolStatus();
    }
    
    /**
     * 获取当前连接信息
     * 
     * @return array|null 连接信息，如果不存在返回 null
     */
    public function getConnectionInfo(): ?array
    {
        return $this->connectionPool->getConnectionInfo($this->redisConfig);
    }
    
    /**
     * 清理当前配置的所有单例实例
     * 
     * @return int 清理的实例数量
     */
    public static function clearInstances(): int
    {
        $count = count(self::$instances);
        self::$instances = [];
        return $count;
    }
    
    /**
     * 获取所有单例实例的状态
     * 
     * @return array 实例状态信息
     */
    public static function getInstancesStatus(): array
    {
        $status = [
            'total_instances' => count(self::$instances),
            'instances' => [],
        ];
        
        foreach (self::$instances as $key => $instance) {
            $status['instances'][$key] = [
                'redis_config' => $instance->getRedisConfig(),
                'queue_config' => $instance->getQueueConfig(),
                'stream_name' => $instance->getStreamName(),
                'consumer_group' => $instance->getConsumerGroup(),
                'consumer_name' => $instance->getConsumerName(),
                'connection_info' => $instance->getConnectionInfo(),
            ];
        }
        
        return $status;
    }
    
    /**
     * 生成实例的唯一标识符
     * 
     * @param array $redisConfig Redis 配置
     * @param array $queueConfig 队列配置
     * @return string 实例标识符
     */
    private static function generateInstanceKey(array $redisConfig, array $queueConfig): string
    {
        // 使用Redis配置和流名称生成key，确保相同Redis相同流的队列使用同一个实例
        $keyConfig = [
            'redis' => [
                'host' => $redisConfig['host'] ?? '127.0.0.1',
                'port' => $redisConfig['port'] ?? 6379,
                'password' => $redisConfig['password'] ?? null,
                'database' => $redisConfig['database'] ?? 0,
            ],
            'stream_name' => $queueConfig['stream_name'] ?? 'redis_stream_queue',
        ];
        
        return md5(json_encode($keyConfig));
    }
    
    /**
     * 防止克隆单例
     */
    private function __clone()
    {
    }

    /**
     * 防止反序列化单例
     * @throws RedisStreamException
     */
    public function __wakeup()
    {
        throw new RedisStreamException('Cannot serialize singleton');
    }

    // =========================================================================
    // 延时队列功能方法
    // =========================================================================

    /**
     * 确保延时流存在
     * 
     * @return void
     * @throws RedisStreamException 当创建失败时抛出异常
     */
    protected function ensureDelayedStream(): void
    {
        if ($this->delayedStreamName === null) {
            throw new RedisStreamException('Delayed queue is not enabled');
        }

        try {
            // 检查延时流是否存在
            $delayedStreamExists = $this->redis->exists($this->delayedStreamName);
            if (!$delayedStreamExists) {
                $this->logger->info('Delayed stream created', [
                    'stream' => $this->delayedStreamName
                ]);
            }
        } catch (Throwable $e) {
            $this->logger->error('Failed to ensure delayed stream', ['error' => $e->getMessage()]);
            throw new RedisStreamException('Failed to ensure delayed stream: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 检查是否启用了延时队列功能
     * 
     * @return bool 始终返回 true，因为延时功能已内置
     */
    public function isDelayedQueueEnabled(): bool
    {
        return true;
    }

    /**
     * 发送定时消息（在指定时间执行）
     * 
     * @param mixed $message 消息内容
     * @param int $timestamp 执行时间戳
     * @param array $metadata 附加元数据
     * @return string 返回生成的消息ID
     * @throws RedisStreamException 当发送失败时抛出异常
     */
    public function sendAt($message, int $timestamp, array $metadata = []): string
    {
        // 直接调用统一的 send 方法，传入时间戳
        // 新的 send() 方法会自动识别时间戳参数
        return $this->send($message, $metadata, $timestamp);
    }

    /**
     * 运行延时消息调度器
     * 
     * 将到期的延时消息转移到主队列中
     * 
     * @param int $maxMessages 最大处理消息数量，0 表示不限制
     * @return int 转移的消息数量
     * @throws RedisStreamException 当调度失败时抛出异常
     */
    public function runDelayedScheduler(int $maxMessages = 0): int
    {

        try {
            $currentTime = time();
            $transferredCount = 0;
            $maxBatchSize = $this->queueConfig['max_batch_size'];
            
            $this->logger->info('Starting delayed message scheduler', [
                'current_time' => date('Y-m-d H:i:s', $currentTime),
                'max_messages' => $maxMessages,
                'max_batch_size' => $maxBatchSize
            ]);

            // 读取延时消息
            $messages = $this->redis->xRange($this->delayedStreamName, '-', '+', $maxBatchSize);
            
            foreach ($messages as $messageId => $data) {
                // 检查是否达到最大处理数量
                if ($maxMessages > 0 && $transferredCount >= $maxMessages) {
                    break;
                }

                // 检查是否到期
                if (isset($data['execute_time']) && (int)$data['execute_time'] <= $currentTime) {
                    // 从延时流删除
                    $this->redis->xDel($this->delayedStreamName, [$messageId]);
                    
                    // 添加到主队列
                    $data['status'] = 'ready';
                    $data['transferred_at'] = $currentTime;
                    unset($data['execute_time']);
                    unset($data['delay_seconds']);
                    
                    $newMessageId = $this->redis->xAdd($this->streamName, '*', $data);
                    
                    $transferredCount++;
                    
                    $this->logger->info('Delayed message transferred to main queue', [
                        'original_id' => $messageId,
                        'new_id' => $newMessageId,
                        'delay_seconds' => $data['delay_seconds'] ?? 'unknown'
                    ]);
                }
            }

            $this->logger->info('Scheduler completed', [
                'transferred_count' => $transferredCount,
                'processed_messages' => count($messages)
            ]);

            return $transferredCount;
        } catch (Throwable $e) {
            $this->logger->error('Failed to run delayed scheduler', ['error' => $e->getMessage()]);
            throw new RedisStreamException('Failed to run delayed scheduler: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 获取延时队列状态
     * 
     * @return array 延时队列状态信息
     */
    public function getDelayedStatus(): array
    {

        try {
            return [
                'delayed_stream_length' => $this->getDelayedStreamLength(),
                'main_stream_length' => $this->getStreamLength(),
                'pending_count' => $this->getPendingCount(),
                'upcoming_count' => $this->getUpcomingMessageCount(),
                'delayed_stream' => $this->delayedStreamName,
                'main_stream' => $this->streamName,
                'consumer_group' => $this->consumerGroup,
                'consumer_name' => $this->consumerName,
                'enabled' => true,
            ];
        } catch (Throwable $e) {
            $this->logger->error('Failed to get delayed status', ['error' => $e->getMessage()]);
            throw new RedisStreamException('Failed to get delayed status: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 获取延时流长度
     * 
     * @return int 延时流中的消息数量
     */
    public function getDelayedStreamLength(): int
    {

        try {
            return $this->redis->xLen($this->delayedStreamName);
        } catch (Throwable $e) {
            $this->logger->error('Failed to get delayed stream length', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 获取即将到期的消息数量
     * 
     * @param int $withinSeconds 未来多少秒内到期
     * @return int 即将到期的消息数量
     */
    public function getUpcomingMessageCount(int $withinSeconds = 60): int
    {

        try {
            $currentTime = time();
            $futureTime = $currentTime + $withinSeconds;
            $count = 0;
            
            // 读取延时消息
            $messages = $this->redis->xRange($this->delayedStreamName, '-', '+', 1000);
            
            foreach ($messages as $messageId => $data) {
                if (isset($data['execute_time']) && (int)$data['execute_time'] <= $futureTime) {
                    $count++;
                }
            }
            
            return $count;
        } catch (Throwable $e) {
            $this->logger->error('Failed to get upcoming message count', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 获取延时流名称
     * 
     * @return string 延时流名称
     */
    public function getDelayedStreamName(): string
    {
        return $this->delayedStreamName;
    }

    /**
     * 启动延时队列调度器（阻塞模式）
     * 
     * @param int $runtime 运行时间（秒），0 表示一直运行
     * @param callable|null $onTick 每次调度后的回调函数
     * @return void
     * @throws RedisStreamException 当启动失败时抛出异常
     */
    public function startDelayedScheduler(int $runtime = 0, ?callable $onTick = null): void
    {
        $this->logger->info('Starting delayed scheduler in blocking mode', [
            'runtime' => $runtime > 0 ? "${runtime}s" : 'unlimited',
            'interval' => $this->queueConfig['scheduler_interval']
        ]);

        $startTime = time();
        $lastScheduledAt = 0;

        try {
            while (true) {
                // 检查运行时间限制
                if ($runtime > 0 && (time() - $startTime) >= $runtime) {
                    $this->logger->info('Scheduler runtime completed');
                    break;
                }

                // 检查是否到了调度时间
                $currentTime = time();
                if ($currentTime - $lastScheduledAt >= $this->queueConfig['scheduler_interval']) {
                    $transferred = $this->runDelayedScheduler();
                    $lastScheduledAt = $currentTime;

                    // 执行回调函数
                    if ($onTick !== null) {
                        $onTick($transferred, $this->getDelayedStatus());
                    }
                }

                // 短暂休眠避免CPU占用过高
                usleep(100000); // 100ms
            }
        } catch (Throwable $e) {
            $this->logger->error('Scheduler crashed', ['error' => $e->getMessage()]);
            throw new RedisStreamException('Scheduler crashed: ' . $e->getMessage(), 0, $e);
        }
    }
}