# 部署与运行

要求 PHP 8.2+、Composer 2、MySQL 8.0、Nginx、PHP-FPM。PHP 扩展为 curl、fileinfo、json、mbstring、PDO、pdo_mysql；安装 PHPUnit 的机器还需其要求的 XML/DOM 扩展。链接解析器需要可执行的 PHP CLI 和启用的 proc_open。

以下路径以 `/srv/jarvis`、PHP-FPM 用户 `www-data` 为例，按实际服务器调整。部署前备份现有数据库；初始化脚本用于首次安装，不是数据库迁移工具。

```sh
cd /srv/jarvis/backend
cp .env.example .env
composer install --no-dev --optimize-autoloader
mysql -u root -p < ../database/init.sql
```

脚本创建 `jarvis_family` 数据库和种子分类、单位。为应用单独创建数据库账号并授予该库所需的 SELECT、INSERT、UPDATE、DELETE 权限；将凭据写入 `.env`。生产设 `APP_ENV=production`，保持 `APP_DEBUG=false` 和 `CALENDAR_TIMEZONE=Asia/Shanghai`。填写微信 AppID/Secret。`PHP_CLI_BINARY` 必须指向真正的 CLI，不能使用 FPM 二进制。

```sh
install -d -m 0750 -o www-data -g www-data /srv/jarvis/backend/var/link-previews
install -d -m 0755 -o www-data -g www-data /srv/jarvis/backend/public/uploads
chown www-data:www-data /srv/jarvis/backend/var
chmod 0750 /srv/jarvis/backend/var
chown root:www-data /srv/jarvis/backend/.env
chmod 0640 /srv/jarvis/backend/.env
```

FPM 仅需写 `public/uploads`、`var/link-previews` 和 `var/wechat-access-token.json` 的父目录；代码不应对 FPM 可写。临时解析图片和 access-token 缓存不得放进 public。Cron 使用相同运行用户，避免缓存锁和文件所有者冲突。CLI 与 FPM 使用同一 `.env`，服务器时钟应同步。

复制 `nginx.conf.example` 到站点配置，替换域名、证书路径、代码路径与 FPM socket。PHP-FPM 设置 `upload_max_filesize=6M`、`post_max_size=8M`；应用自身限制单张图片 5 MiB。Nginx 只执行 front controller，上传目录不执行 PHP。检查配置后按服务器标准流程重载服务：

```sh
nginx -t
curl --fail https://kitchen-api.example.com/api/v1/health
```

为 `www-data` 配置以下 crontab；清理每天一次，过期图片在 API 层立即失效，即使物理清理尚未运行。若存储紧张可提高清理频率。日志进入主机既有的日志轮转系统。

```cron
*/5 * * * * cd /srv/jarvis/backend && /usr/bin/php bin/send-reminders.php >> /srv/jarvis/backend/var/reminders.log 2>&1
17 3 * * * cd /srv/jarvis/backend && /usr/bin/php bin/cleanup-previews.php >> /srv/jarvis/backend/var/preview-cleanup.log 2>&1
```

提醒程序为有界任务，不是常驻服务。库存摘要按北京时间每天 09:00，覆盖当天至未来三天的正库存临期批次。任务提前 24 小时提醒负责人。无一次性授权时记录 `skipped_no_grant`，不影响应用内临期展示。模板参数错误、网络错误等由 runner 的结果/日志排查；勿把用户拒绝授权视为服务故障。

## 微信配置

在微信公众平台设置同一 HTTPS API 域名为 **request 合法域名**和 **uploadFile 合法域名**；图片使用该域名的 `/uploads/`，真机验证下载/展示所需域名配置。合法域名只填写 origin，不含 `/api/v1`。正式环境使用有效可信证书，不能依赖开发者工具的“不校验域名”选项。

申请两种订阅模板并匹配后台实际字段：`WECHAT_TASK_DUE_TEMPLATE_ID`、`WECHAT_EXPIRY_TEMPLATE_ID`；核对 `WECHAT_TASK_DUE_TITLE_KEY`、`WECHAT_TASK_DUE_TIME_KEY`、`WECHAT_EXPIRY_SUMMARY_KEY`。前端 `env.js.subscriptionTemplates.task_due` 和 `.inventory_expiry` 填对应 ID。两种类型分别授权，可只配置一种。偏好开关不代表微信已授权，一次性订阅需用户每次实际点击授权。

外部菜谱若已知小程序 AppID/path 可写在链接记录或 `externalPrograms` 配置中。微信平台的跳转权限按当前账号要求配置并真机测试；跳转失败或没有路由时复制 HTTPS 链接。小程序没有任意网页 web-view 跳转依赖。

## 本地开发

```sh
cd backend
cp .env.example .env
composer install
mysql -u root -p < ../database/init.sql
php -S 127.0.0.1:8080 -t public
```

在开发者工具导入 `miniprogram/`，按其 README 配置 AppID 与 `env.js`。本地 `baseUrl` 可设 `http://127.0.0.1:8080/api/v1`，仅开发工具开启本地域名校验豁免。显式配置 `localLoginCode: 'dev:alice'`、后端 `APP_ENV=local` 以稳定模拟同一用户；切换为 `dev:bob` 并重新编译可测试加入家庭。此设置默认空，正式构建必须为空。后端在非 local 环境拒绝任何 dev: 登录，不可用此功能替代生产 wx.login。

完整部署后的验收见 `../../miniprogram/ACCEPTANCE.md`，接口见 `../API.md`。开发依赖可用时运行 `composer test`；小程序运行 `node --test miniprogram/tests/*.test.js miniprogram/tests/*.test.cjs`（仓库根目录）。
