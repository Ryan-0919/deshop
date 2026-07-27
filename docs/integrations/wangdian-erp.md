# ShopXO 与旺店通 ERP 对接方案

## 1. 背景与目标

本项目是基于 ShopXO 的自营商城，PC 网站和微信小程序共用同一套商品、订单和库存后台。商城不另设第三方 ERP，旺店通 ERP 位于商城与云仓之间：

```text
ShopXO 商城 -> 旺店通 ERP -> 云仓
ShopXO 商城 <- 旺店通 ERP <- 云仓
```

第一阶段目标是打通以下闭环：

1. 用户付款后，ShopXO 将销售订单推送至旺店通 ERP。
2. 云仓发货并回传旺店通后，ShopXO 从旺店通获取发货状态、快递公司和快递单号。
3. ShopXO 从旺店通获取当前可售库存，并覆盖商城对应 SKU 的库存。
4. 所有操作可重试、可追踪，并且不会重复创建订单或重复发货。

售后退货和换货属于第二阶段，在第一阶段通过测试环境验收后开发。

## 2. 官方接口

| 业务 | 接口 | 方向 | 调度建议 |
| --- | --- | --- | --- |
| 创建或更新订单 | `trade_push.php` | ShopXO -> 旺店通 | 每 5 分钟，单批不超过 50 单 |
| 查询待同步发货信息 | `logistics_sync_query.php` | ShopXO -> 旺店通 | 每 5 分钟，单批不超过 100 条 |
| 回写发货同步结果 | `logistics_sync_ack.php` | ShopXO -> 旺店通 | 每批发货信息处理后立即调用 |
| 查询待同步库存 | `api_goods_stock_change_query.php` | ShopXO -> 旺店通 | 每 3 至 5 分钟，单批 100 至 2000 条 |
| 回写库存同步结果 | `api_goods_stock_change_ack.php` | ShopXO -> 旺店通 | 只回写已经成功更新商城的记录 |

第二阶段接口：

| 业务 | 接口 |
| --- | --- |
| 创建退款/退货单 | `sales_refund_push.php` |
| 查询退换处理结果 | `refund_query.php` |
| 创建换出订单 | `trade_push.php` |

测试环境基地址为 `https://sandbox.wangdian.cn/openapi2/`，正式环境基地址为 `https://api.wangdian.cn/openapi2/`。所有接口使用 HTTPS POST 和 `application/x-www-form-urlencoded`。

## 3. 上线前资料

旺店通或 ERP 购买方需要提供：

- `sid`
- `appkey`
- `appsecret`
- 自有平台店铺编号 `shop_no`
- 测试环境账号和测试店铺
- 正式应用授权及接口权限
- ERP 内使用的云仓编号和韵达物流编码
- 技术联调联系人和测试排期

密钥只放在服务器环境变量中，不写入代码、数据库同步日志或 Git。

## 4. 配置

```dotenv
WANGDIAN_ENABLED=false
WANGDIAN_ENVIRONMENT=sandbox
WANGDIAN_SID=
WANGDIAN_APPKEY=
WANGDIAN_APPSECRET=
WANGDIAN_SHOP_NO=
WANGDIAN_ORDER_SYNC_FROM=
WANGDIAN_SHOPXO_WAREHOUSE_ID=0
WANGDIAN_EXPRESS_MAP={"YUNDA":6}
WANGDIAN_ORDER_BATCH_SIZE=50
WANGDIAN_LOGISTICS_BATCH_SIZE=100
WANGDIAN_INVENTORY_BATCH_SIZE=100
WANGDIAN_CRON_TOKEN=
WANGDIAN_CRON_ALLOWED_IPS=127.0.0.1,::1
```

`WANGDIAN_ORDER_SYNC_FROM` 必须设置为首次启用同步的日期时间或 Unix 时间戳。未配置时不推送订单，防止首次开启后误推历史订单。

生产环境如果启用了 ShopXO 仓库库存，将 `WANGDIAN_SHOPXO_WAREHOUSE_ID` 设置为该云仓在 ShopXO 后台对应的仓库 ID；`0` 表示只同步商品和规格总库存。

`WANGDIAN_EXPRESS_MAP` 的键使用旺店通返回的 ERP 物流编号，值使用 ShopXO 后台的快递公司 ID。韵达在当前标准初始化数据中的 ShopXO ID 为 `6`，上线前仍需以生产数据库为准。

## 5. 订单推送

### 5.1 选择范围

只推送满足全部条件的订单：

- 快递配送订单，即 `order_model = 0`
- 已支付，即 `pay_status = 1`
- 待发货，即 `status = 2`
- `pay_time` 不早于 `WANGDIAN_ORDER_SYNC_FROM`
- 尚未成功同步，或者已到失败重试时间

支付回调不直接请求旺店通。订单由定时任务异步推送，避免旺店通超时影响用户支付结果。

### 5.2 主要字段映射

| 旺店通字段 | ShopXO 来源 | 规则 |
| --- | --- | --- |
| `tid` | `order.order_no` | 在同一个 `sid` 下保持唯一 |
| `trade_status` | 订单状态 | 已付款待发货固定为 `30` |
| `pay_status` | `order.pay_status` | 已支付固定为 `2` |
| `delivery_term` | 支付状态 | 款到发货固定为 `1` |
| `trade_time` | `order.add_time` | `Y-m-d H:i:s` |
| `pay_time` | `order.pay_time` | `Y-m-d H:i:s` |
| `receiver_*` | `order_address` | 省、市、区使用中文名称 |
| `buyer_message` | `order.user_note` | 移除不受支持的控制字符 |
| `post_amount` | `order.increase_price` | 第一阶段按订单增加金额处理 |
| `paid` | `order.pay_price` / `total_price` | 优先使用实际支付金额 |
| `oid` | 订单号 + 明细 ID | 在同一个 `sid` 下保持唯一 |
| `goods_id` | `order_detail.goods_id` | ShopXO SPU ID |
| `spec_id` | `order_detail.spec_coding` | 使用稳定且唯一的 SKU 编码 |
| `spec_no` | `order_detail.spec_coding` | 必填，必须与 ERP 商家编码一致 |
| `goods_no` | `order_detail.coding` | ShopXO SPU 编码 |
| `num` | `order_detail.buy_number` | 购买数量 |
| `price` | `order_detail.price` | 商品标价 |
| `share_discount` | 订单优惠金额 | 按明细金额比例分摊，最后一行修正尾差 |

### 5.3 SKU 前置条件

每个可销售规格必须有唯一的 `spec_coding`，并与旺店通 ERP 中的商家编码一致。缺少或重复 SKU 编码时：

- 不调用旺店通订单接口；
- 将订单记录为同步失败；
- 日志明确指出具体商品和明细；
- 修正 SKU 后由定时任务重试。

不允许使用随机编码或订单明细 ID 替代仓库 SKU。

## 6. 发货同步

业务上是旺店通向商城回传发货信息，技术实现是 ShopXO 定时调用 `logistics_sync_query.php` 拉取，再调用 `logistics_sync_ack.php` 确认。

处理顺序：

1. 按 `tid` 查找 ShopXO 订单。
2. 根据 `logistics_code_erp` 映射 ShopXO 快递公司。
3. 使用 ShopXO 原有发货服务写入快递单号并把订单更新为已发货。
4. 同步成功后向旺店通回写 `status = 0`。
5. 查不到订单、快递映射或本地写入失败时回写 `status = 1` 和简短原因。

重复收到相同快递单号时按成功处理，不重复写入。第一阶段按单订单单物流单号验收，不开启拆单发货参数；拆单、多包裹在双方确认业务规则后扩展。

## 7. 库存同步

旺店通返回的 `sync_stock` 是当前可售库存，不是增减数量。ShopXO 必须使用该值覆盖 SKU 库存。

SKU 匹配优先级：

1. `spec_no` 精确匹配 `goods_spec_base.coding`；
2. 同时校验 `goods_id`，避免不同商品误用相同 SKU；
3. 未找到或匹配到多条时拒绝更新，并保留旺店通待同步记录。

成功更新规格库存后，重新汇总并更新商品总库存。库存事务先锁定商品父记录，再锁定参与汇总的规格记录；多仓模式按相同顺序锁定仓库商品及仓库规格，避免并发更新不同 SKU 时丢失汇总值。只有本地事务提交成功的记录才进入 `api_goods_stock_change_ack.php`。

如果生产环境启用了 ShopXO 多仓库存，需要在联调前确认指定 ShopXO 仓库 ID，并扩展为同时更新该仓库的规格库存。第一阶段默认旺店通可售库存是商城的唯一库存来源。

## 8. 幂等、重试与日志

新增通用同步日志表，以 `business_type + business_key` 唯一标识一次业务：

- 订单：`order_push + order_id:revision_hash`。`revision_hash` 对订单、收货地址和订单明细的当前数据生成稳定 SHA-256；内容未变化时不会重复推送，地址修改、明细增删或其他订单内容更新后会使用新幂等键调用 `trade_push.php` 更新 ERP 订单。
- 发货：`logistics + rec_id`
- 库存：`inventory + rec_id:stock_change_count`
- 售后：`aftersale_push + aftersale_id:revision_hash`。退款金额、数量、原因、说明或退货物流发生变化时会生成新版本并调用 `sales_refund_push.php` 更新 ERP 售后单。

订单任务先使用订单、地址和明细的更新时间筛选候选，只加载可能变化的数据；同时每 24 小时重新计算一次完整内容哈希，兼容未正确维护更新时间的历史数据，避免每个定时任务都重新读取全部待发货队列。

日志保存状态、尝试次数、下次重试时间、脱敏请求、响应和最后错误。手机号、地址以及名称包含 `secret`、`password`、`token`、`sign` 的敏感字段会在入库前统一遮蔽，`appsecret` 永远不进入日志。

重试采用递增等待时间，最长等待 1 小时。网络异常、HTTP 非 2xx、无效 JSON、非法响应码和旺店通 `code != 0` 都视为失败；只有整数 `0` 或规范字符串 `"0"` 表示成功。单条数据问题不能阻塞同批其他数据。同步日志使用唯一键原子建行并加锁认领，处理租约为 1 小时；完成更新同时校验本次认领时间，旧任务不能覆盖后来任务的结果。售后批量响应未提供计数且没有逐条结果时，会按幂等键逐条重试确认，不直接把整批标记成功。

## 9. 安全要求

- 强制 HTTPS，不允许正式环境降级为 HTTP。
- `appsecret` 只用于本地签名，不作为请求参数发送。
- 签名参数按键名升序排列，并使用旺店通长度编码规则后计算 32 位小写 MD5。
- 日志不记录完整手机号、地址或密钥。
- 集成默认关闭，配置完整且显式设置 `WANGDIAN_ENABLED=true` 后才允许调用外部接口。

## 10. 调度与部署

新增三个 ShopXO 定时任务入口：

- 订单推送：`/api/crontab/wangdianorderpush`，每 5 分钟
- 发货同步：`/api/crontab/wangdianlogisticssync`，每 5 分钟
- 库存同步：`/api/crontab/wangdianinventorysync`，每 3 至 5 分钟

定时任务响应示例：

```text
enabled:1, success:3, fail:0, skipped:0
```

这些入口默认只允许 `127.0.0.1` 和 `::1` 访问。远程调度器必须满足以下任一条件：

- 来源 IP 已配置在 `WANGDIAN_CRON_ALLOWED_IPS`；
- 请求头 `X-Wangdian-Cron-Token` 与 `WANGDIAN_CRON_TOKEN` 完全一致。

生产环境必须配置可信调度器 IP 或高强度随机 token，并建议继续在 Nginx 层限制来源 IP。例如：

```bash
curl -H "X-Wangdian-Cron-Token: ${WANGDIAN_CRON_TOKEN}" \
  https://shop.example.com/api/crontab/wangdianorderpush
```

部署顺序：

1. 在 ShopXO SQL 控制台执行数据库迁移，创建同步日志表；脚本使用 `{PREFIX}` 占位符，会自动适配当前数据库表前缀。若直接通过 MySQL 客户端执行，需要先将 `{PREFIX}` 替换为实际表前缀。
2. 配置测试环境参数，保持正式环境关闭。
3. 为测试商品补齐唯一 SKU 编码，并在旺店通建立对应商家编码。
4. 设置 `WANGDIAN_ORDER_SYNC_FROM`。
5. 开启测试环境，完成订单、发货和库存闭环验收。
6. 获取正式应用授权，切换正式接口并重新完成一笔小额订单验收。

## 11. 第一阶段验收标准

- 同一订单重复执行任务不会在旺店通创建重复订单。
- 缺失 SKU 的订单不会被推送，并能在日志中看到可操作的失败原因。
- 旺店通发货后 5 分钟内，ShopXO 订单变为已发货并显示正确的韵达单号。
- 重复发货记录不会产生重复快递信息。
- 旺店通库存变化后 5 分钟内，商城对应 SKU 和商品总库存更新。
- 库存未成功落库时不会向旺店通确认成功。
- 旺店通短暂不可用不会影响商城支付，并能自动重试。
- 所有密钥仅来自环境变量，Git 和日志中不存在明文密钥。

## 12. 售后退款与退货

第二阶段已接入以下官方接口：

| 业务 | 接口 | 方向 | 单批限制 |
| --- | --- | --- | --- |
| 创建或更新退款/退货单 | `sales_refund_push.php` | ShopXO -> 旺店通 | 10 单 |
| 查询 ERP 退换单处理状态 | `refund_query.php` | ShopXO -> 旺店通 | 每页 40 单 |

ShopXO 售后单号 `id` 映射为稳定的旺店通平台退款单号 `SHOPXO-AS-{id}`，同一编号也作为当前微信支付插件的退款请求号。这样同一售后在“支付平台退款成功、商城本地事务失败”后重试时会复用原请求号，避免重复退款。订单明细编号继续使用订单推送阶段的 `{order_no}-{order_detail_id}`。只有原订单已经成功推送至旺店通的售后单才会进入售后推送任务。

类型映射：

- 未发货“仅退款”映射为旺店通 `type=1`；
- 已发货“仅退款”映射为旺店通 `type=2`；
- “退货退款”映射为旺店通 `type=3`。

状态映射：

| ShopXO 状态 | 旺店通状态 |
| --- | --- |
| 待确认、仅退款待审核 | `wait_seller_agree` |
| 待退货 | `goods_returning` |
| 已填写退货物流、待审核 | `goods_receiving` |
| 已完成 | `success` |
| 已拒绝 | `seller_refuse` |
| 已取消 | `closed` |

新增定时任务：

- 售后推送：`/api/crontab/wangdianaftersalepush`，建议每 5 分钟；
- 售后状态同步：`/api/crontab/wangdianaftersalesync`，建议每 5 分钟。

新增配置：

```dotenv
WANGDIAN_AFTERSALE_BATCH_SIZE=10
WANGDIAN_AFTERSALE_QUERY_LOOKBACK=604800
WANGDIAN_AFTERSALE_AUTO_PROCESS=false
WANGDIAN_AFTERSALE_REFUNDMENT=0
```

`WANGDIAN_AFTERSALE_QUERY_LOOKBACK` 单位为秒，默认查询 ERP 最近 7 天内修改过的退换单，最大 30 天。旺店通每次状态变化都会更新 `modified`，因此长期退货在发生新状态时仍会被获取。

`WANGDIAN_AFTERSALE_AUTO_PROCESS` 默认关闭。关闭时任务会验证 ERP 退换单与 ShopXO 售后单的关联并记录状态，但不会确认、拒绝、取消或执行退款。关联验证要求存在成功的售后出站推送日志，并且 ERP 返回的 `tid`、唯一明细 `oid`、售后类型和申请退款金额与 ShopXO 售后单完全一致。ERP 标记完成时还必须校验 `actual_refund_amount`，以及明细 `refund_num`：退货退款使用售后申请数量，仅退款使用原订单明细购买数量。任一项不一致都不会执行本地操作。联调验证状态映射后才能在生产环境设为 `true`。开启后：

- ERP 取消映射为 ShopXO 售后取消；
- ERP 拒绝在 ShopXO 当前状态允许时映射为售后拒绝；
- ERP 同意退货时确认 ShopXO 退货申请；
- ERP 完成且 ShopXO 已处于待审核状态时，调用 ShopXO 原有 `AftersaleAudit()` 完成退款。

`WANGDIAN_AFTERSALE_REFUNDMENT` 使用 ShopXO 原有退款方式：`0` 原路退回、`1` 退到钱包、`2` 手工处理。生产环境自动处理建议使用 `0`，上线前必须用测试订单完成一次 ERP 收货到微信退款的完整验证。

自动处理关闭时使用 `observe` 幂等键，开启后使用 `apply` 幂等键，因此观察过的 ERP 状态不会阻止后续正式处理。单条售后失败不影响同批其他记录，并继续沿用通用指数退避重试日志。

旺店通返回未识别的 `process_status` 时，该条同步会标记为失败并等待重试，不会作为成功的空操作跳过。应先核对旺店通状态定义并补充明确映射，再继续自动处理。

`refund_query.php` 会严格校验接口返回的 `total_count` 并持续翻页，直到当前时间窗口全部读取完成，不使用固定页数上限，避免高峰期后续记录长期无法同步。计数缺失、格式非法、翻页过程中改变或重复返回同一页时，本轮任务失败并等待重试，不会把不完整页面当作同步完成。

## 13. 尚未实现

- 换货及换出订单：当前 ShopXO 只有“仅退款”和“退货退款”，没有独立换货类型、换出 SKU 和收货信息模型；需要先扩展产品和售后数据模型，不能直接复用退货退款。
- 拆单、多包裹发货；
- 后台同步状态和手工重试页面；
- 日志归档与告警。
