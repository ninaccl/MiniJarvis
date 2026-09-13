# 家庭厨房 Mini Program

这是一个不依赖 UI 框架的原生微信小程序。菜谱、餐食日历、库存、采购、家庭待办均通过 `/api/v1` REST API 获取和保存数据；不要从小程序导入后端代码。

## 配置与运行

1. 复制 `project.config.example.json` 为微信开发者工具需要的 `project.config.json`，填写小程序 AppID。
2. 复制 `env.example.js` 为 `env.js`，设置 HTTPS API 域名、按类型填写 `subscriptionTemplates.task_due` 和 `subscriptionTemplates.inventory_expiry`（不要提交此文件）。域名须在微信公众平台 request 和 uploadFile 合法域名中登记。
3. 在微信开发者工具中导入 `miniprogram/` 目录并编译。

`app.js` 启动时调用 `wx.login`，请求 `POST /auth/wechat`，持久化 Bearer token、最小用户信息和家庭信息。没有家庭成员关系时，会进入创建/邀请码加入页。API 客户端对 401 只执行一次共享的重新登录和原请求重试；再次 401 或重新登录失败会清掉本地会话。

## 本地校验

```sh
node scripts/generate-tab-icons.cjs
node --test tests/*.test.js tests/*.test.cjs
```

图标由 `scripts/generate-tab-icons.cjs` 生成，并已作为十个本地 PNG 资源写入 `assets/tab/`。在改动图标生成器后，重新运行生成命令并保留生成的 PNG。

五个底部 tab 提供完整业务入口，菜谱详情和编辑使用独立页面，其他表单使用底部 sheet。`components/` 仅包含展示组件，不发起 API 请求。加载失败可重试，写入失败保留表单。

本地后端为 `APP_ENV=local` 时，可显式设置 `env.js.localLoginCode='dev:alice'` 和 `baseUrl='http://127.0.0.1:8080/api/v1'`。开发者工具仅本地测试可关闭合法域名校验；切换 dev:bob 后重新编译可模拟第二个用户。默认 localLoginCode 为空，正式构建必须为空，使用真实 wx.login。

完整部署说明见 [backend/deploy/README.md](../backend/deploy/README.md)，字段合约见 [backend/API.md](../backend/API.md)。开发者工具和真机验收见 [ACCEPTANCE.md](ACCEPTANCE.md)。结构测试不能代替 WXML 编译、键盘/安全区、真实微信授权及消息送达验证。
