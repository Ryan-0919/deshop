# 得宝 x DeShop Integration Contract v1

状态：冻结草案（第 1 模块，仅契约，不包含业务实现）

## 1. 边界与所有权

- DeShop 是商品、分类、SKU、价格、库存、地址、订单、支付、物流、退款和售后的唯一主系统。
- 得宝只负责原生展示、账号绑定入口、订单/物流展示和课程权益；不得共享 DeShop 数据库、密码、Cookie 或长期 ShopXO token。
- 用户账号不得按手机号自动合并。绑定必须由用户在双方已登录状态下明确确认，并建立 `debao_user_id <-> deshop_user_id` 映射。
- 订单、地址、支付和售后接口都必须进行资源所有权校验，禁止仅凭外部 ID 读取或修改他人资源。
- 商品、分类、SKU、库存和订单金额以 DeShop 返回值为准；调用方不得自行计算应付金额。

## 2. 版本与表示

- Base URL：`https://shop.denlaw.cn/debao/v1`
- JSON：`Content-Type: application/json; charset=utf-8`
- 金额：整数分（CNY cents），例如 `1299` 表示人民币 12.99 元；禁止浮点金额。
- 时间：ISO 8601 UTC，例如 `2026-08-25T05:00:00Z`。
- ID：不透明字符串；不得依赖数据库自增规律。
- 列表：使用 `limit`（1-100）和 `cursor`，响应返回 `next_cursor`；不得使用页码。
- 成功响应使用 `{ "data": ... }`；列表数据为 `{ "items": [...], "next_cursor": "...", "has_more": true }`。
- 错误响应统一为：`{ "error": { "code": "...", "message": "...", "request_id": "...", "details": {} } }`。

## 3. HMAC-SHA256 鉴权

商品分类、商品、商品详情和 SKU 查询同样要求 HMAC-SHA256 服务端签名，但不要求用户绑定上下文或 `X-Debao-User-Id`。

每个受保护请求都必须带：

```text
X-Debao-Client-Id: <client id>
X-Debao-Key-Id: <key id>
X-Debao-Timestamp: <unix seconds>
X-Debao-Nonce: <random 16+ byte value>
X-Debao-Signature: <base64url(HMAC-SHA256(secret, signing string))>
X-Debao-User-Id: <debao user id>
```

签名串使用换行连接以下 8 行，值均为原始 UTF-8：

```text
client_id\nkey_id\ntimestamp\nnonce\nHTTP_METHOD\n/path/without-host\ncanonical_query\nsha256(body)
```

- `HTTP_METHOD` 使用大写；path 必须 percent-decoded 后再按 RFC 3986 规范化。
- query 按键名、值升序排序，重复键保留并以 `&` 连接；无 query 时为空行。
- body 为空时按空字节串计算 SHA-256；JSON 发送前固定 UTF-8 编码。
- 服务端允许时间戳前后偏差最多 300 秒；超出返回 `AUTH_TIMESTAMP_OUT_OF_RANGE`。
- `nonce` 在服务端按 `client_id + key_id + nonce` 保存至少 10 分钟并拒绝重复使用，重复返回 `AUTH_REPLAY_DETECTED`。
- `event_id` 对 Webhook 必须幂等处理，重复事件返回 2xx 且不得重复改变状态。
- `DEBAO_BINDING_CONFIRMATION_ENABLED` 默认关闭；只有在绑定表、API 路由和商城确认页全部部署后才能开启。关闭时绑定接口返回 HTTP 503 和 `BINDING_CONFIRMATION_UNAVAILABLE`，不得伪造绑定成功。
- 密钥必须带 `key_id`，支持双 key 并行轮换；旧 key 撤销后不再接受签名，但已发出的 Webhook 需按事件 key 验证。
- 签名失败不得泄露哪一部分校验失败，统一返回 `AUTH_INVALID_SIGNATURE`。

## 4. API 目录

### 商品与 SKU（公开读取）

- `GET /categories`：商品分类树或按 `parent_id` 查询。
- `GET /products`：商品列表，支持 `category_id`、`updated_after`、`cursor`、`limit`。
- `GET /products/{product_id}`：商品详情、销售状态、币种、价格（分）、SKU 列表和协议/课程权益元数据。
- `GET /products/{product_id}/skus`：SKU 列表，返回 `sku_id`、`sku_code`、规格值、售价（分）、可售库存和上下架状态。

商品详情可选返回 `image_url`（首图）、`tags`（展示标签数组）、`features`（服务/课程权益元数据对象或数组）和 `metadata`（扩展对象）。产品和 SKU 可选返回 `original_price_cents`（整数分，原价）及 `currency`（ISO 4217，默认 `CNY`）；实际售价仍以 `price_cents` 为准。

### 账号绑定

- `POST /bindings/challenges`：创建一次性绑定挑战。请求指定 `debao_user_id` 和 `method`（`mini_qr` 或 `phone_fallback`）。响应返回短时效 `challenge_id`、二维码/短链和过期时间；二维码不包含长期 token。
- `GET /bindings/challenges/{challenge_id}`：查询挑战状态。只有创建者或已完成绑定的一方可读。
- `POST /bindings/challenges/{challenge_id}/confirm`：用户明确确认绑定，服务端校验双方会话/手机后创建映射。
- `GET /bindings/status`：查询当前得宝用户的绑定状态和脱敏的另一方 ID。
- `DELETE /bindings`：幂等解除绑定，仅解除映射，不删除任一方账号或订单；未绑定或重复调用同样返回 HTTP 204。

手机后备流程必须使用一次性验证码、用途绑定和速率限制；手机号只用于验证，不作为自动合并条件。

### 地址

- `GET /addresses`：当前已绑定 DeShop 用户的地址列表。
- `POST /addresses`：新增地址。
- `PATCH /addresses/{address_id}`：修改自有地址。
- `DELETE /addresses/{address_id}`：删除自有地址。
- `POST /addresses/{address_id}/default`：设置默认地址。

地址响应中的手机号、详细地址属于隐私字段；仅在绑定用户会话和必要业务场景返回，日志不得记录完整值。

### 一次性 SSO / checkout session

- `POST /sessions/sso`：得宝服务端创建一次性 SSO session。session 默认 5 分钟有效、仅能使用一次、绑定 `debao_user_id` 和目标动作；不得返回长期 DeShop token。
- `POST /sessions/checkout`：创建一次性结算 session，服务端传入 `sku_id`、数量、优惠上下文和可选地址 ID。DeShop 返回 `checkout_session_id`、服务端计算的金额快照和过期时间。
- `GET /sessions/{session_id}`：查询 session 状态；消费后只返回最小必要信息。

### 订单、支付、物流

- `GET /orders`：当前绑定用户订单列表，支持 `status`、`cursor`、`limit`。
- `GET /orders/{order_id}`：订单详情、明细、金额分、协议/课程权益状态和物流摘要。
- `GET /orders/{order_id}/logistics`：订单物流和包裹列表；虚拟订单返回权益交付信息，不伪造运单。
- `POST /orders/{order_id}/pay`：创建支付意图，必须服务端重新校验订单所有权、金额、合同签署状态和库存；响应支付参数，不接受调用方金额。

支付、发货、取消和退款的跨系统通知使用 Webhook，不允许得宝直接改变 DeShop 订单状态。

### Webhook

Module 5 response contract: SSO and checkout requests use the snake_case fields `debao_user_id`, `redirect_uri`, `state`, `items`, `sku_id`, `quantity`, and optional `address_id`. Checkout responses return `session_id`, `status`, `expires_at`, `one_time`, `total_cents`, and `items`; `currency` and `payment_required` are optional. Payment intent responses return `order_id`, `amount_cents`, `payment_provider`, and `payment_intent_id`. Amounts are integer cents and are always calculated by DeShop.

Webhook 接收端由得宝提供并在注册时配置；DeShop 发送以下事件：

- `payment.succeeded`
- `order.shipped`
- `order.cancelled`
- `refund.created`
- `refund.succeeded`

事件统一结构：`event_id`、`event_type`、`occurred_at`、`order_id`、`resource_version`、`data`。接收方必须先验签，再按 `event_id` 幂等落库；处理成功返回 HTTP 204。临时失败可返回 5xx 触发重试，永久失败返回 2xx 并记录人工告警。

## 5. 隐私与安全

- 身份证号、完整手机号、完整地址、支付参数和签署材料属于敏感字段，默认不出现在列表、日志、错误和分析事件中。
- 订单详情只返回当前绑定用户拥有的订单；Webhook 只包含完成业务所需的最小字段。
- 所有客户端输入必须按 schema 校验；金额、库存、签署状态和订单状态由 DeShop 服务端决定。
- 公开商品接口不得暴露供应商密钥、仓储内部 ID、用户内部 ID 或支付凭证。

## 6. 后续实现边界（本模块不执行迁移）

建议后续新增独立模块，不改原生 ShopXO API：

- `app/debao/`：签名验证、绑定挑战、session、资源 DTO、Webhook 分发。
- `app/api/controller/Debao.php` 或独立路由组：只负责协议层适配。
- `config/debao.php`：client、key、时钟偏差、nonce TTL、Webhook 重试配置。
- `app/model/DebaoBinding.php`、`DebaoSession.php`、`DebaoWebhookEvent.php`、`DebaoKey.php`。
- 迁移草案：`debao_bindings`（双方用户 ID、状态、版本、时间）；`debao_sessions`（一次性 session、动作、过期、消费时间）；`debao_nonces`（client/key/nonce、过期）；`debao_webhook_events`（event_id 唯一、状态、重试）；`debao_keys`（key_id、哈希密钥、状态、有效期）。
- 所有表均使用不可猜测外部 ID、唯一约束和创建/更新时间；不保存长期 ShopXO token、Cookie 或明文密钥。

## 7. 非目标

第 1 模块契约不实现商品同步、支付下单、电子签、数据库迁移、旺店通改造或得宝端代码。模块 3 已在 DeShop 侧提供独立绑定适配层、迁移模板和要求用户登录后明确确认的商城页面；生产部署必须显式开启确认功能，未开启时接口返回可恢复的 503 错误。手机后备方式仍需独立短信提供方，未配置时必须 fail closed。
