# 测试说明

Redis Stream Queue 库包含完整的测试套件，覆盖了所有核心功能和集成场景。

## 测试结构

```
tests/
├── TestCase.php                 # 基础测试类
├── Unit/                       # 单元测试
│   ├── RedisStreamQueueTest.php # 队列核心功能测试
│   ├── ProducerTest.php         # 生产者测试
│   ├── ConsumerTest.php         # 消费者测试
│   └── MessageHandlerInterfaceTest.php # 消息处理器接口测试
└── Integration/                # 集成测试
    └── IntegrationTest.php      # 端到端集成测试
```

## 测试覆盖范围

### 单元测试
- **RedisStreamQueueTest**: 测试队列核心功能
  - 配置格式（统一配置 vs 分离配置）
  - 消息发送和消费
  - ACK/NACK 操作
  - 错误处理
  - 默认配置值

- **ProducerTest**: 测试生产者功能
  - 各种数据类型的消息发送
  - 批量消息发送
  - 消息元数据处理
  - 大消息处理

- **ConsumerTest**: 测试消费者功能
  - 回调函数处理
  - MessageHandlerInterface 集成
  - 内存管理
  - 运行状态控制
  - 错误处理

- **MessageHandlerInterfaceTest**: 测试消息处理器接口
  - 接口实现
  - 消息路由
  - 状态管理
  - 消息验证和转换

### 集成测试
- **IntegrationTest**: 端到端测试
  - 生产者-消费者工作流
  - 批量处理
  - 消息处理器集成
  - 错误处理工作流
  - 重试机制
  - 多消费者场景
  - 消息元数据传播
  - 大消息处理
  - 队列统计
  - 优雅关闭
  - 不同消息格式
  - 流持久性

## 运行测试

### 前置条件
1. PHP 7.4+
2. Redis 服务器运行中
3. 安装依赖：`composer install`

### 运行方式

#### 1. 使用测试运行脚本（推荐）
```bash
# 运行所有测试
php run-tests.php

# 仅运行单元测试
php run-tests.php --unit

# 仅运行集成测试
php run-tests.php --integration

# 生成代码覆盖率报告
php run-tests.php --coverage

# 显示详细输出
php run-tests.php --verbose

# 显示帮助
php run-tests.php --help
```

#### 2. 直接使用 PHPUnit
```bash
# 运行所有测试
./vendor/bin/phpunit

# 运行特定测试套件
./vendor/bin/phpunit --testsuite Unit
./vendor/bin/phpunit --testsuite Integration

# 运行特定测试文件
./vendor/bin/phpunit tests/Unit/RedisStreamQueueTest.php

# 生成覆盖率报告
./vendor/bin/phpunit --coverage-html tests/coverage
```

## 测试环境配置

测试使用独立的环境变量配置：

```bash
# Redis 连接配置
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0
```

## 测试数据

测试使用以下数据：
- Redis Stream 名称：`test_redis_stream`
- 消费者组：`test_consumer_group`
- 消费者名称：自动生成（`consumer_` + 随机ID）

测试完成后会自动清理测试数据。

## 代码覆盖率

运行覆盖率测试后，报告会生成在 `tests/coverage/` 目录中。

## 添加新测试

1. 在相应的测试目录中创建测试类
2. 继承 `Tinywan\RedisStream\Tests\TestCase`
3. 遵循 PHPUnit 命名约定
4. 使用 `setUp()` 和 `tearDown()` 方法管理测试状态

示例：
```php
<?php

namespace Tinywan\RedisStream\Tests\Unit;

use Tinywan\RedisStream\Tests\TestCase;

class NewFeatureTest extends TestCase
{
    public function testNewFeature(): void
    {
        // 测试代码
    }
}
```

## 故障排除

### 常见问题

1. **Redis 连接失败**
   - 确保 Redis 服务器运行中
   - 检查连接配置

2. **权限问题**
   - 确保 Redis 有足够权限执行 Stream 操作
   - 需要 Redis 5.0+ 版本

3. **测试数据冲突**
   - 每个测试使用独立的消费者名称
   - 测试完成后自动清理数据

### 调试测试

使用 `--verbose` 选项查看详细输出：
```bash
php run-tests.php --verbose
```

或启用调试模式：
```bash
./vendor/bin/phpunit --debug
```