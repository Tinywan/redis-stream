# GitHub Pages 配置说明

本文档说明如何配置 GitHub Actions 自动生成项目主页。

## 前置条件

1. 仓库必须是公开的或者具有 GitHub Actions 权限
2. 需要启用 GitHub Pages
3. 需要配置 GitHub Actions 权限

## 配置步骤

### 1. 启用 GitHub Pages

1. 进入仓库的 **Settings** 页面
2. 在左侧菜单中找到 **Pages**
3. 在 **Source** 部分选择 **GitHub Actions**
4. 保存设置

### 2. 配置 GitHub Actions 权限

1. 进入仓库的 **Settings** 页面
2. 在左侧菜单中找到 **Actions** -> **General**
3. 在 **Workflow permissions** 部分：
   - 选择 **Read and write permissions**
   - 勾选 **Allow GitHub Actions to create and approve pull requests**
4. 保存设置

### 3. 启用 Pages 权限

1. 进入仓库的 **Settings** 页面
2. 在左侧菜单中找到 **Actions** -> **General**
3. 向下滚动找到 **Fork pull request workflows from outside collaborators**
4. 选择 **Require approval for all outside collaborators**
5. 在同一个页面，找到 **Enable Pages access**
6. 确保相关选项已启用

## 文件结构

```
.github/
├── workflows/
│   └── generate-main-page.yml    # GitHub Actions 工作流配置
├── scripts/
│   └── generate-main-page.php     # 主页生成脚本
└── styles/
    └── main.css                  # 主页样式文件
```

## 功能特性

### 自动生成的内容
- 项目基本信息和描述
- 特性展示
- 最新测试结果
- 安装说明
- 快速开始示例
- 响应式设计
- 交互效果和动画

### 触发条件
- 推送到 main 分支
- 创建 Pull Request
- 手动触发
- 定时任务（每天早上8点）

### 生成的页面包含
- Hero 区域展示项目信息
- 统计数据卡片
- 核心特性网格
- 测试结果展示
- 安装和快速开始指南
- 项目页脚信息

## 自定义配置

### 修改项目信息
编辑 `.github/scripts/generate-main-page.php` 中的 `$projectInfo` 数组：

```php
$projectInfo = [
    'name' => 'Your Project Name',
    'description' => 'Your project description',
    'version' => '1.0.0',
    'author' => 'Your Name',
    'license' => 'MIT',
    'homepage' => 'https://github.com/username/repo',
    'documentation' => 'https://github.com/username/repo#readme',
    'packagist' => 'https://packagist.org/packages/username/repo'
];
```

### 修改样式
编辑 `.github/styles/main.css` 文件来自定义页面样式。

### 修改定时任务
编辑 `.github/workflows/generate-main-page.yml` 中的 `schedule` 部分：

```yaml
schedule:
  # 每天早上8点运行（北京时间）
  - cron: '0 0 * * *'
```

## 故障排除

### 常见问题

1. **权限错误**
   - 确保已启用 GitHub Actions 写权限
   - 确保已启用 Pages 权限

2. **构建失败**
   - 检查 PHP 版本和依赖
   - 检查 Redis 服务是否正常启动
   - 查看构建日志

3. **页面不更新**
   - 检查工作流是否成功运行
   - 清除浏览器缓存
   - 检查 Pages 部署状态

### 查看部署状态

1. 进入仓库的 **Actions** 页面
2. 点击 **Generate Main Page** 工作流
3. 查看最新的运行记录和日志

### 访问生成的页面

页面生成后会自动部署到 GitHub Pages，访问地址为：
```
https://<username>.github.io/<repository-name>/
```

## 技术栈

- **GitHub Actions**: CI/CD 自动化
- **GitHub Pages**: 静态页面托管
- **PHP**: 页面生成脚本
- **CSS3**: 样式和动画
- **JavaScript**: 交互效果
- **Prism.js**: 代码高亮
- **Font Awesome**: 图标

## 维护说明

- 工作流会自动在每次推送时运行
- 定时任务确保页面内容保持最新
- 测试结果会自动从最新报告文件中读取
- 如果需要修改页面内容，编辑相应的 PHP 脚本文件