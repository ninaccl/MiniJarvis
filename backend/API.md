# 小程序 REST 合约

所有路径前缀 `/api/v1`。除 health 和 auth 外均用 `Authorization: Bearer <access_token>`；家庭资源由服务端验证成员关系。成功为 `{success:true,data:...}`，列表可能另有 `meta:{page,page_size,total,total_pages}`；失败为 `{success:false,error:{code,message,fields?}}`。当前前端客户端返回 data；每页取 20 条，以不足 20 条判定结束，整页末尾允许再请求一次空页。

数值数量以最多四位小数字符串传递，必须为正且适配 DECIMAL(14,4)。单位代码：g、kg、ml、l、piece、pack、box、bunch、tbsp、tsp。日期为 `YYYY-MM-DD`（北京时间），时间点传 ISO-8601 并带 Z 或时区，响应时间为 UTC。客户端仅消费 REST，不导入 PHP 或数据库代码。

| 方法与路径 | 输入 | data 结果 |
| --- | --- | --- |
| GET /health | 无 | `{status:'ok'}` |
| POST /auth/wechat | `{code,nickname?,avatar_url?}` | `{access_token,user,...}` |
| POST /households | `{name}` | 家庭及一次性明文 invite_code |
| POST /households/join | `{invite_code}` | 加入结果 |
| GET /households/current | 无 | `{household:{id,name,role,...},members:[{user_id,nickname,role,...}]}` |
| POST /households/invite/reset | `{}` | 新 invite_code，仅 owner |
| DELETE /households/members/{userId} | 无 | 删除结果，仅 owner，可移除非 owner |
| GET /categories | 无 | `[{id,name}]` |
| GET /recipes | q、category_id、page、page_size | 菜谱数组；搜索 title/description |
| POST /recipes | 下方完整菜谱 | 创建后完整菜谱 |
| GET /recipes/{id} | 无 | 完整菜谱、ingredients、links |
| PATCH /recipes/{id} | 下方完整菜谱，非局部补丁 | 原子替换后完整菜谱 |
| DELETE /recipes/{id} | 无 | `{deleted:true}`，软删除 |
| POST /uploads/images | multipart `file`，JPEG/PNG/WebP，≤5 MiB | `{url,mime_type,size}`，url 为 `/uploads/...` |
| POST /link-previews | `{url}` | `{available,title?,image_url?,token?,normalized_url?,reason?}` |
| GET /link-previews/{token}/image | Bearer，仅创建者，24h有效 | 图片字节，不是 JSON |
| POST /link-previews/{token}/adopt | `{}` | `{cover_url}`，只能采用一次 |
| GET /inventory | q、status=all/active/expiring/expired | 批次数组 |
| POST /inventory/batches | `{ingredient_name,quantity,unit_code,expiry_date?,note?}` | 批次；可用 ingredient_id 替代 ingredient_name |
| PATCH /inventory/batches/{id} | `{expiry_date?,note?}`，null 清除 | 更新后的批次 |
| POST /inventory/batches/{id}/movements | `{operation:'add'/'consume'/'set',quantity,unit_code,note?}` | 更新后的批次 |
| GET /inventory/movements | page、page_size | 流水数组，含 operation/base_delta/base_unit_code/ingredient_name/batch_id/occurred_at（UTC ISO-8601） |
| GET /recipes/matches | count=1..10，默认2 | 推荐数组：recipe、score、fully_matched、matched_ingredients、missing_ingredients |
| GET /meal-plan | from、to，必填日期 | `[{id,date,meal,recipe_id,recipe_title,servings,...}]` |
| POST /meal-plan/entries | `{date,meal,recipes:[{recipe_id,servings}]}` | 同一餐批量新增的条目数组 |
| PATCH /meal-plan/entries/{id} | **仅** `{servings}` | 更新条目 |
| DELETE /meal-plan/entries/{id} | 无 | `{deleted:true}` |
| POST /shopping-lists | `{selections:[{date,meals:[...]}]}` | 清单及 items，先扣可用库存 |
| GET /shopping-lists | page、page_size | 清单数组，含 name/status/item_count/checked_count |
| GET /shopping-lists/{id} | 无 | 清单及 items |
| PATCH /shopping-lists/{id}/items/{itemId} | `{checked:true/false}` | 单个更新后 item |
| PATCH /shopping-lists/{id} | `{status:'active'/'completed'}` | 清单头；不含 items，客户端重新读取详情 |
| POST /shopping-lists/{id}/items/{itemId}/stock | `{quantity,unit_code,expiry_date?,note?}` | `{item,batch}`；重复入库 409 SHOPPING_ITEM_ALREADY_STOCKED |
| GET /tasks | status=all/pending/completed、assignee_id 可选 | **扁平数组**；前端请求 all 后筛选，保留父任务上下文 |
| POST /tasks | `{title,parent_id?,assignee_user_id?,due_at?}` | 新任务，最多一级子任务 |
| GET /tasks/{id} | 无 | 任务和 children |
| PATCH /tasks/{id} | title、parent_id、assignee_user_id、due_at、status 的子集 | 更新后的任务 |
| DELETE /tasks/{id} | 无 | null；删除父任务会级联删除子任务 |
| GET /notifications/preferences | 无 | `{task_due,inventory_expiry}` |
| PATCH /notifications/preferences | 偏好的布尔值子集 | 更新后偏好 |
| POST /notifications/subscription-grants | `{template_type:'task_due'/'inventory_expiry',result:'accept'/'reject'}` | 授权记录结果 |

完整菜谱写入示意（字段值为合约示例，不是页面内置数据）：

```json
{"title":"番茄炒蛋","category_id":1,"default_servings":2,"cover_url":null,"description":"家常快手菜","instructions":"洗切食材，炒熟后调味。","ingredients":[{"name":"番茄","quantity":"300","unit_code":"g","note":null},{"name":"盐","quantity":null,"unit_code":null,"note":"按口味"}],"links":[{"platform":"other","url":"https://example.com/recipe","miniapp_app_id":null,"miniapp_path":null}]}
```

菜谱 title 1–100字、description ≤2000、instructions ≤10000、default_servings 1–100；ingredients 1–50行，去空白并忽略 ASCII 大小写后不能重名；links 0–5条，platform 为 douyin/bilibili/xiaohongshu/other。每行食材只能提供 name 或 ingredient_id 之一；“适量”用 quantity/unit_code 都为 null。cover_url 只能为已上传或采用的 `/uploads/` 地址。解析失败不会阻止用户手工填写或保存有效 HTTPS 链接。

库存响应使用 `base_quantity/base_unit_code` 表示当前规范余量；`display_quantity/display_unit_code` 是建立批次时的输入，不是当前余量。`expiry_date` 是 API 字段名，数据库列名 expires_on 不暴露给页面。set 也要求正数量；清空库存通过 consume 当前全部余量完成。数量不足为 409 INSUFFICIENT_STOCK，单位不兼容为 422 UNIT_INCOMPATIBLE。

餐次固定为 breakfast/lunch/dinner。采购 selections 支持不连续日期，每日任意非空餐次组合；客户端剔除完全未选的日期，不把选择扩展成连续区间。采购 item 用 `ingredient_name/quantity/unit_code/checked/stocked_at`；quantity 为 null 表示适量，入库时仍需实际正数量。勾选、完成清单、实际入库互不隐式替代。

任务 status 为 pending/completed。完成父任务会完成全部子任务；全部子任务完成会完成父任务；重新打开子任务会重新打开父任务；重新打开父任务不会强制重开已完成子任务。前端对已完成父任务不提供直接添加子任务，先重新打开后再添加。
