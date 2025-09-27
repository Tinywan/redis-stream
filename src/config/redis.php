<?php

/**
 * Redis Configuration Examples
 * 
 * 此文件包含各种使用场景下的 Redis 连接配置示例
 * 
 * 使用方法：
 * 1. 复制所需的配置到您的代码中
 * 2. 根据实际需求调整参数
 * 3. 传入 RedisStreamQueue::getInstance() 方法
 *
 * Redis 配置参数说明
 *
 * host: Redis 服务器的主机地址或域名
 * port: Redis 服务器的端口号，默认为 6379
 * password: Redis 服务器的密码，如果设置了认证则需要提供
 * database: Redis 数据库编号，0-15 共16个数据库
 * timeout: 连接超时时间（秒），超过此时间连接将失败
 */

return [
    /** 默认配置 */
    'default' => [
        'host' => '127.0.0.1',        // Redis 主机地址
        'port' => 6379,               // Redis 端口
        'password' => null,           // Redis 密码
        'database' => 0,               // Redis 数据库
        'timeout' => 5,                // 连接超时时间（秒）
    ],
];
