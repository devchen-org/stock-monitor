# 股票工具

一个基于 PHP + SQLite + 原生 JavaScript 的单用户股票管理工具，适合本地自用或通过一台公网服务器反向代理后远程访问。

## 功能概览

- 当前持仓管理
  - 新增、编辑、删除持仓
  - 支持整表导入持仓
  - 支持按代码、名称、数量、成本价、现价、涨跌幅、收益率等排序
- 自选股票管理
  - 添加、删除自选股
  - 自动刷新行情
- 做 T 记录
  - 记录买入 / 卖出
  - 同向记录自动合并均价与数量
  - 反向记录自动闭合并计算收益
  - 支持未完成记录按当前价试算
  - 支持为未完成记录逐行设置正收益 / 负收益 webhook 提醒
- 持仓试算
  - 基于当前持仓、现价、买卖方向和 N 手，试算新数量、新总成本、新成本价、新收益率
- 系统设置
  - 自动刷新频率
  - 是否仅交易时间刷新
  - 默认 N 手
  - 持仓涨跌幅浏览器通知阈值
- 登录与安全
  - 单密码登录
  - 首次使用强制设置密码
  - 支持强制修改密码
  - 登录状态默认保持 30 天

## 运行环境

- PHP 8.2+
- SQLite
- 现代浏览器
- Docker / Docker Compose（可选）

## 本地启动

### 1. 使用 PHP 内置服务器

在项目根目录执行：

```bash
php -S 127.0.0.1:8090 -t public
```

打开浏览器访问：

- [http://127.0.0.1:8090/index.php](http://127.0.0.1:8090/index.php)

### 2. 使用 Docker Compose

项目默认已针对中国大陆网络环境做了镜像源优化：

- PHP 基础镜像默认使用 DaoCloud 源
- Dockerfile 中 apt 源默认替换为清华镜像

启动：

```bash
docker compose up -d --build
```

访问：

- [http://127.0.0.1:8090/index.php](http://127.0.0.1:8090/index.php)

如果你希望临时改用官方 PHP 镜像：

```bash
PHP_IMAGE=php:8.2-cli docker compose up -d --build
```

常用命令：

```bash
# 启动

docker compose up -d

# 重建并启动

docker compose up -d --build

# 查看日志

docker compose logs -f

# 停止并删除容器

docker compose down
```

当前 `docker-compose.yml` 暴露端口如下：

- `8090:8090`

因此默认访问地址为：

- `http://127.0.0.1:8090`

## 首次使用

1. 首次进入系统时，需要先设置登录密码
2. 设置完成后，使用该密码登录
3. 如果启用了“强制修改密码”，登录后必须先修改密码才能继续使用
4. Session Cookie 会按 HTTPS / 反代头自动判断是否启用 `Secure`

## 浏览器通知说明

设置页支持配置：

- 涨幅提醒（%）
- 跌幅提醒（%）
- 消息通知渠道（企业微信 / 飞书）
- Webhook 地址

当当前持仓某只股票达到阈值后：

- 页面会触发浏览器通知
- 如果已配置 webhook，也会随着持仓轮询持续推送机器人消息

使用前请注意：

- 需要手动点击“启用浏览器通知”并授权
- 通知依赖浏览器权限
- 在 HTTPS 环境下体验更稳定；本地 `localhost/127.0.0.1` 一般也可使用
- 如果授权时已有股票已达到阈值，系统会立即按当前状态补发一次提醒
- Webhook 推送使用与浏览器通知相同的涨幅 / 跌幅阈值
- Webhook 推送会在每次轮询命中阈值时继续发送，不做跨轮询去重

### Webhook 渠道配置

设置页支持以下渠道：

- 企业微信机器人
- 飞书机器人

配置方式：

1. 在设置页选择消息通知渠道
2. 填写对应机器人 Webhook 地址
3. 点击“测试 webhook”确认机器人可以收到消息
4. 保存后，系统会在持仓轮询时按当前涨跌幅阈值持续推送

## 持仓导入格式

“导入持仓”使用纯文本格式，一行一个股票，格式如下：

```txt
代码,名称,数量,成本价
600000,浦发银行,1000,10.52
000001,平安银行,500,12.34
```

注意：

- 使用英文逗号 `,`
- 每行必须正好 4 列
- 股票代码不能重复
- 保存导入时会整体替换当前持仓

## 数据存储

SQLite 数据文件位于：

- `storage/data.db`

如果使用 Docker Compose，`storage/` 目录会挂载到容器内，因此重建容器后数据仍会保留。

## 旧交易数据迁移到持仓表

如果你是从旧版本升级，可以执行独立迁移脚本，将交易流水推导出的持仓写入 `positions` 表：

```bash
php scripts/migrate_positions.php
```

脚本特性：

- 如果 `positions` 表已有数据，会自动跳过
- 如果旧交易流水无法生成持仓，也会安全退出

## 远程访问推荐方案

如果你希望通过公网域名访问，推荐使用：

- 公网服务器安装 Caddy
- 公网服务器运行 frps
- 项目所在内网机器运行 frpc
- 域名指向公网服务器
- 由公网 Caddy 终止 HTTPS
- frp 只转发项目的 HTTP 服务

例如：

- 域名：`s.chenyangx.com`
- 项目本地服务：`127.0.0.1:8090`
- frp 映射到公网服务器本地：`127.0.0.1:18090`
- Caddy 反代 `127.0.0.1:18090`

示例 Caddyfile：

```caddy
s.chenyangx.com {
    reverse_proxy 127.0.0.1:18090 {
        header_up Host {host}
        header_up X-Forwarded-Proto https
        header_up X-Forwarded-For {remote_host}
    }
}
```

这样浏览器访问的是公网 Caddy 的正式 HTTPS 证书，项目内部仍然保持简单的 HTTP 服务。

## 项目结构

```txt
app/            启动逻辑、路由、数据库初始化、仓储、服务、辅助函数
public/         页面入口、样式、前端脚本、静态资源
storage/        SQLite 数据文件与运行时数据
database/       初始化 SQL
scripts/        迁移脚本与辅助脚本
docker/         Docker 相关配置
```

## 技术栈

- PHP 8.2
- SQLite
- Vanilla JavaScript
- CSS
- Docker Compose
- Caddy（可选，用于公网 HTTPS 入口）

## 排错建议

### 页面打不开

检查：

```bash
php -S 127.0.0.1:8090 -t public
```

或：

```bash
docker compose ps
docker compose logs -f
```

### 浏览器通知不弹出

检查：

- 是否点击过“启用浏览器通知”
- 浏览器是否允许通知权限
- 当前是否确实有持仓达到涨跌幅阈值
- 页面是否处于可正常执行脚本的环境

### Docker 启动失败

检查本机是否已安装：

- Docker
- Docker Compose

并确认 8090 端口未被占用。

## 许可

仅供个人学习与自用。股票行情与收益计算结果仅供参考。