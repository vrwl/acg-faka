### 订单变量列表

- [order.trade_no] 订单号
- [order.amount] 金额
- [order.card_num] 卡密数量
- [order.create_time] 下单时间
- [order.create_ip] 下单IP
- [order.pay_time] 支付时间
- [order.secret] 卡密信息
- [order.password] 查询密码
- [order.contact] 联系方式
- [order.pay_url] 支付URL
- [order.cost] 手续费
- [order.from] 推广人ID
- [order.widget] 控件内容（JSON文本原文提交）
- [order.race] 商品种类

### 商品变量列表

- [commodity.name] 商品名称
- [commodity.description] 商品说明
- [commodity.price] 商品单价
- [commodity.user_price] 会员单价

### 支付方式变量列表

- [pay.name] 支付方式名称

### 会员信息变量表

- [user.username] 用户账号
- [user.email] 用户邮箱
- [user.phone] 用户手机
- [user.qq] 用户QQ
- [user.balance] 用户余额
- [user.coin] 用户硬币

> 更多变量请自行查看数据库字段，只要是数据库字段存在，均支持自动实现变量，包含未知插件的字段。


### POST 模版演示

`name=[commodity.name]&widget=[order.widget]&username=[user.username]`

### GET 模版演示

`http://192.168.9.249:7811/api.php?name=[commodity.name]&widget=[order.widget]&username=[user.username]`

小提示：无论是GET/POST/JSON，API地址里面也支持填写变量

### JSON 模版演示

```json
{
    "name" : "[commodity.name]",
    "widget" : "[order.widget]",
    "username" : "[user.username]"
}
```