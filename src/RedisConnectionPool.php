<?php

declare(strict_types=1);

namespace Tinywan\RedisStream;

use Redis;
use Tinywan\RedisStream\Exception\RedisStreamException;

/**
 * Redis 连接池管理器
 * 
 * 提供单例模式的 Redis 连接管理，确保同一配置的连接被复用
 */
class RedisConnectionPool
{
    /** @var self 单例实例 */
    private static self $instance;
    
    /** @var array 存储不同配置的 Redis 连接 */
    private array $connections = [];
    
    /** @var array 连接配置信息 */
    private array $configurations = [];
    
    /**
     * 私有构造函数，防止直接实例化
     */
    private function __construct()
    {
    }
    
    /**
     * 获取单例实例
     */
    public static function getInstance(): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * 获取或创建 Redis 连接
     * 
     * @param array $config Redis 配置
     * @return Redis Redis 客户端实例
     * @throws RedisStreamException 当连接失败时抛出异常
     */
    public function getConnection(array $config): Redis
    {
        // 生成配置的唯一标识符
        $configKey = $this->generateConfigKey($config);
        
        // 如果连接已存在且仍然活跃，直接返回
        if (isset($this->connections[$configKey]) && $this->isConnectionAlive($this->connections[$configKey])) {
            return $this->connections[$configKey];
        }
        
        // 创建新连接
        $redis = $this->createConnection($config);
        
        // 存储连接和配置
        $this->connections[$configKey] = $redis;
        $this->configurations[$configKey] = $config;
        
        return $redis;
    }
    
    /**
     * 获取指定配置的连接信息
     * 
     * @param array $config Redis 配置
     * @return array|null 连接信息，如果不存在返回 null
     */
    public function getConnectionInfo(array $config): ?array
    {
        $configKey = $this->generateConfigKey($config);
        
        if (!isset($this->connections[$configKey])) {
            return null;
        }
        
        return [
            'config' => $this->configurations[$configKey],
            'is_alive' => $this->isConnectionAlive($this->connections[$configKey]),
            'created_at' => time(), // 连接创建时间
        ];
    }
    
    /**
     * 移除指定配置的连接
     * 
     * @param array $config Redis 配置
     * @return bool 移除成功返回 true
     */
    public function removeConnection(array $config): bool
    {
        $configKey = $this->generateConfigKey($config);
        
        if (isset($this->connections[$configKey])) {
            try {
                $this->connections[$configKey]->close();
            } catch (\Throwable $e) {
                // 忽略关闭错误
            }
            unset($this->connections[$configKey]);
            unset($this->configurations[$configKey]);
            return true;
        }
        
        return false;
    }
    
    /**
     * 清理所有连接
     * 
     * @return int 清理的连接数量
     */
    public function clearAllConnections(): int
    {
        $count = count($this->connections);
        
        foreach ($this->connections as $connection) {
            try {
                $connection->close();
            } catch (\Throwable $e) {
                // 忽略关闭错误
            }
        }
        
        $this->connections = [];
        $this->configurations = [];
        
        return $count;
    }
    
    /**
     * 获取当前连接池状态
     * 
     * @return array 连接池状态信息
     */
    public function getPoolStatus(): array
    {
        $status = [
            'total_connections' => count($this->connections),
            'connections' => [],
        ];
        
        foreach ($this->connections as $configKey => $connection) {
            $status['connections'][$configKey] = [
                'config' => $this->configurations[$configKey],
                'is_alive' => $this->isConnectionAlive($connection),
                'connected_at' => time(), // 连接创建时间
            ];
        }
        
        return $status;
    }
    
    /**
     * 检查连接是否仍然活跃
     * 
     * @param Redis $redis Redis 客户端实例
     * @return bool 连接活跃返回 true
     */
    private function isConnectionAlive(Redis $redis): bool
    {
        try {
            return $redis->ping() === '+PONG';
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * 创建新的 Redis 连接
     * 
     * @param array $config Redis 配置
     * @return Redis Redis 客户端实例
     * @throws RedisStreamException 当连接失败时抛出异常
     */
    private function createConnection(array $config): Redis
    {
        try {
            $redis = new Redis();
            
            // 合并默认配置
            $config = array_merge([
                'host' => '127.0.0.1',
                'port' => 6379,
                'password' => null,
                'database' => 0,
                'timeout' => 5,
            ], $config);
            
            // 建立连接
            $redis->connect($config['host'], $config['port'], $config['timeout']);
            
            // 如果配置了密码，进行身份验证
            if ($config['password']) {
                $redis->auth($config['password']);
            }
            
            // 选择数据库
            $redis->select($config['database']);
            
            return $redis;
        } catch (\Throwable $e) {
            throw new RedisStreamException('Redis connection failed: ' . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * 生成配置的唯一标识符
     * 
     * @param array $config Redis 配置
     * @return string 配置标识符
     */
    private function generateConfigKey(array $config): string
    {
        // 只使用配置中影响连接的参数生成key
        $keyConfig = [
            'host' => $config['host'] ?? '127.0.0.1',
            'port' => $config['port'] ?? 6379,
            'password' => $config['password'] ?? null,
            'database' => $config['database'] ?? 0,
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
     */
    public function __wakeup()
    {
        throw new RedisStreamException('Cannot unserialize singleton');
    }
}