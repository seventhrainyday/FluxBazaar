#!/usr/bin/env bash
# 全流程实测：需先启动 php -S 127.0.0.1:18090 -t ~/workspace/fluxbazaar
set -u
BASE="http://127.0.0.1:18090"
J=/tmp/tj_seller.jar; K=/tmp/tj_buyer.jar
rm -f $J $K ~/workspace/fluxbazaar/data/fluxbazaar.sqlite
PASS=0; FAIL=0
ok()  { if [ "$1" = "$2" ]; then PASS=$((PASS+1)); echo "PASS: $3"; else FAIL=$((FAIL+1)); echo "FAIL: $3 (want [$2] got [$1])"; fi; }
has() { if echo "$2" | grep -qF "$1"; then PASS=$((PASS+1)); echo "PASS: $3"; else FAIL=$((FAIL+1)); echo "FAIL: $3 (missing [$1])"; fi; }
q()   { sqlite3 ~/workspace/fluxbazaar/data/fluxbazaar.sqlite "$1"; }

echo "== 1. 注册卖家(首个=管理员) / 买家 =="
curl -s -c $J -b $J -d "a=register&username=seller1&password=pass1234" "$BASE/?p=register" -o /dev/null
curl -s -c $K -b $K -d "a=register&username=buyer1&password=pass1234" "$BASE/?p=register" -o /dev/null
ok "$(q "SELECT count(*) FROM users;")" "2" "两个用户注册成功"
ok "$(q "SELECT is_admin FROM users WHERE username='seller1';")" "1" "首个用户是管理员"

echo "== 2. 买家充值 50 元 =="
curl -s -c $K -b $K -d "a=recharge&amount=50" "$BASE/?p=buyer" -o /dev/null
ok "$(q "SELECT amount_micro FROM ledger WHERE user_id=2 AND type='recharge';")" "50000000" "充值 50 元到账"

echo "== 3. 卖家上架节点（单端口 20000，0.05 元/GB，限额 100GB） =="
curl -s -c $J -b $J -d "a=node_save&name=香港测试&region=中国香港&ip=1.2.3.4&port=20000&sni=www.microsoft.com&price_gb=0.05&quota_gb=100&note=测试" "$BASE/?p=seller" -o /dev/null -w "%{redirect_url}\n" | grep -q "deploy" && echo "PASS: 上架后跳转部署页" && PASS=$((PASS+1)) || { echo "FAIL: 上架跳转"; FAIL=$((FAIL+1)); }
APIKEY=$(q "SELECT api_key FROM nodes WHERE id=1;")
PUBKEY=$(q "SELECT public_key FROM nodes WHERE id=1;")
[ "${#PUBKEY}" = "43" ] && { echo "PASS: Reality 公钥 43 位 base64url"; PASS=$((PASS+1)); } || { echo "FAIL: 公钥长度 ${#PUBKEY}"; FAIL=$((FAIL+1)); }
TOKEN=$(q "SELECT sub_token FROM users WHERE username='buyer1';")

echo "== 4. 订阅（三种格式） =="
SUB_CLASH=$(curl -s "$BASE/api/sub.php?token=$TOKEN")
has "vless" "$SUB_CLASH" "Clash 订阅含 vless 节点"
has "reality-opts" "$SUB_CLASH" "Clash 订阅含 reality-opts"
SUB_SB=$(curl -s "$BASE/api/sub.php?token=$TOKEN&format=singbox")
echo "$SUB_SB" | python3 -m json.tool >/dev/null 2>&1 && { echo "PASS: Sing-box 订阅是合法 JSON"; PASS=$((PASS+1)); } || { echo "FAIL: Sing-box JSON 非法"; FAIL=$((FAIL+1)); }
SUB_URI=$(curl -s "$BASE/api/sub.php?token=$TOKEN&format=uri")
has "vless://" "$SUB_URI" "URI 订阅含 vless:// 链接"
has "pbk=$PUBKEY" "$SUB_URI" "URI 含平台生成的公钥"

echo "== 5. 节点同步用户 / 下发 Xray 配置 =="
# 卖家先取一次订阅建行（自用免费验证用）
STOKEN=$(q "SELECT sub_token FROM users WHERE username='seller1';")
curl -s "$BASE/api/sub.php?token=$STOKEN&format=uri" -o /dev/null
SYNC=$(curl -s "$BASE/api/sync.php?node_id=1&api_key=$APIKEY&format=json")
echo "$SYNC" | python3 -c "
import json,sys
u=json.load(sys.stdin)['users']
assert len(u)==2, u
assert {x['port'] for x in u}=={20000}, u  # 单端口：两人同端口
print('users:',u)" && { echo "PASS: sync 返回 2 用户且同端口"; PASS=$((PASS+1)); } || { echo "FAIL: sync 用户数"; FAIL=$((FAIL+1)); }
BUUID=$(q "SELECT uuid FROM node_users WHERE node_id=1 AND user_id=2;")
SUUID=$(q "SELECT uuid FROM node_users WHERE node_id=1 AND user_id=1;")
CFG=$(curl -s "$BASE/api/agent_config.php?node_id=1&api_key=$APIKEY")
echo "$CFG" | python3 -c "
import json,sys
c=json.load(sys.stdin)
assert 'stats' in c and 'api' in c, '缺 stats/api'
vless=[i for i in c['inbounds'] if i.get('protocol')=='vless']
assert len(vless)==1, c['inbounds']
ib=vless[0]
assert ib['port']==20000, ib['port']
assert len(ib['settings']['clients'])==2, ib['settings']
rs=ib['streamSettings']['realitySettings']
assert rs['privateKey'] and rs['shortIds'] and rs['serverNames']==['www.microsoft.com']
assert all(cl['flow']=='xtls-rprx-vision' for cl in ib['settings']['clients'])
api_ib=[i for i in c['inbounds'] if i.get('tag')=='api']
assert api_ib and api_ib[0]['port']==10085
print('xray config ok: 单 inbound,', len(ib['settings']['clients']), '个客户端')
" && { echo "PASS: Xray 配置合法（单端口多用户 + StatsService）"; PASS=$((PASS+1)); } || { echo "FAIL: agent_config"; FAIL=$((FAIL+1)); }
# 机主自用免费
curl -s -X POST -H 'Content-Type: application/json' \
  -d "{\"node_id\":1,\"api_key\":\"$APIKEY\",\"usage\":[{\"uuid\":\"$SUUID\",\"bytes\":104857600}]}" \
  "$BASE/api/report.php" | grep -q '"charged_micro":0' && { echo "PASS: 机主自用不计费"; PASS=$((PASS+1)); } || { echo "FAIL: 机主自用"; FAIL=$((FAIL+1)); }
curl -s "$BASE/api/sync.php?node_id=1&api_key=WRONG" | grep -q forbidden && { echo "PASS: 错误 api_key 被拒绝"; PASS=$((PASS+1)); } || { echo "FAIL: api_key 鉴权"; FAIL=$((FAIL+1)); }

echo "== 6. 上报 100MB 流量 → 计费 =="
# 单价 0.05 元/GB = 50000 微元/GB；MB 单价 = round(50000/1024) = 49 微元/MB；100MB = 4900 微元
curl -s -X POST -H 'Content-Type: application/json' \
  -d "{\"node_id\":1,\"api_key\":\"$APIKEY\",\"usage\":[{\"uuid\":\"$BUUID\",\"bytes\":104857600}]}" \
  "$BASE/api/report.php" | grep -q '"ok":true' && { echo "PASS: 上报接口 ok"; PASS=$((PASS+1)); } || { echo "FAIL: 上报接口"; FAIL=$((FAIL+1)); }
ok "$(q "SELECT amount_micro FROM ledger WHERE user_id=2 AND type='usage';")" "-4900" "买家扣费 4900 微元(100MB)"
ok "$(q "SELECT amount_micro FROM ledger WHERE user_id=1 AND type='earning';")" "4410" "卖家分成 4410 微元(90%)"
ok "$(q "SELECT sold_bytes FROM nodes WHERE id=1;")" "209715200" "节点已售流量累计（含机主自用 100MB）"
ok "$(q "SELECT count(*) FROM ledger WHERE user_id=1 AND available_at>datetime('now');")" "1" "卖家收益处于冻结中"

echo "== 7. 余额不足 → 自动熔断 =="
# 买家当前余额 50000000-4900=49995100，全部扣掉 → 余额 0
q "INSERT INTO ledger(user_id,type,amount_micro,note) VALUES(2,'usage',-49995100,'掏空余额');"
curl -s -X POST -H 'Content-Type: application/json' \
  -d "{\"node_id\":1,\"api_key\":\"$APIKEY\",\"usage\":[{\"uuid\":\"$BUUID\",\"bytes\":104857600}]}" \
  "$BASE/api/report.php" | grep -q "suspended" && { echo "PASS: 余额不足触发熔断"; PASS=$((PASS+1)); } || { echo "FAIL: 熔断未触发"; FAIL=$((FAIL+1)); }
ok "$(q "SELECT status FROM node_users WHERE node_id=1 AND user_id=2;")" "suspended" "买家在该节点被停用"
curl -s "$BASE/api/sync.php?node_id=1&api_key=$APIKEY&format=json" | python3 -c "
import json,sys; u=json.load(sys.stdin)['users']
assert len(u)==1 and u[0]['port']==20000, u; print('熔断后同步只剩卖家自用')" && { echo "PASS: 同步不再下发被熔断用户"; PASS=$((PASS+1)); } || { echo "FAIL: 熔断同步"; FAIL=$((FAIL+1)); }

echo "== 8. 一键脚本直链 =="
curl -s "$BASE/?p=deploy_raw&id=1&key=$APIKEY" | grep -qi "flux-agent" && { echo "PASS: deploy_raw 返回脚本"; PASS=$((PASS+1)); } || { echo "FAIL: deploy_raw"; FAIL=$((FAIL+1)); }
# 部署脚本内嵌的 python 守护进程语法检查
curl -s "$BASE/?p=deploy_raw&id=1&key=$APIKEY" | python3 -c "
import sys,re
s=sys.stdin.read()
m=re.search(r\"<<'PYEOF'\n(.*?)\nPYEOF\", s, re.S)
open('/tmp/agent_check.py','w').write(m.group(1))
" && python3 -m py_compile /tmp/agent_check.py && { echo "PASS: 守护进程 python 语法合法"; PASS=$((PASS+1)); } || { echo "FAIL: 守护进程语法"; FAIL=$((FAIL+1)); }
# 部署脚本 bash 语法检查
curl -s "$BASE/?p=deploy_raw&id=1&key=$APIKEY" > /tmp/deploy_check.sh && bash -n /tmp/deploy_check.sh && { echo "PASS: 部署脚本 bash 语法合法"; PASS=$((PASS+1)); } || { echo "FAIL: 部署脚本语法"; FAIL=$((FAIL+1)); }
curl -s -o /dev/null -w "%{http_code}" "$BASE/?p=deploy_raw&id=1&key=WRONG" | grep -q 403 && { echo "PASS: deploy_raw 错误 key 403"; PASS=$((PASS+1)); } || { echo "FAIL: deploy_raw 鉴权"; FAIL=$((FAIL+1)); }

echo "== 9. 提现申请与审核 =="
# 4410 微元 < 10 元最低线，申请应被拒绝
curl -s -c $J -b $J -d "a=payout_request&amount=10" "$BASE/?p=earnings" -o /dev/null
ok "$(q "SELECT count(*) FROM payouts;")" "0" "不足 10 元无法申请提现"
# 补一笔 20 元可用收益，完整走一遍申请→审核
q "INSERT INTO ledger(user_id,type,amount_micro,note) VALUES(1,'earning',20000000,'测试收益');"
curl -s -c $J -b $J -d "a=payout_request&amount=10" "$BASE/?p=earnings" -o /dev/null
ok "$(q "SELECT status FROM payouts WHERE seller_id=1;")" "pending" "提现申请待审核"
PID=$(q "SELECT id FROM payouts WHERE seller_id=1;")
curl -s -c $J -b $J -d "a=payout_decide&id=$PID&decide=approve" "$BASE/?p=admin_payouts" -o /dev/null
ok "$(q "SELECT status FROM payouts WHERE id=$PID;")" "done" "管理员批准提现"
ok "$(q "SELECT amount_micro FROM ledger WHERE user_id=1 AND type='payout';")" "-10000000" "提现打款记账 10 元"

echo "== 10. sing-box 内核节点 =="
curl -s -c $J -b $J -d "a=node_save&name=新加坡singbox&region=新加坡&ip=5.6.7.8&port=20001&sni=www.microsoft.com&price_gb=0.05&quota_gb=0&core=singbox&note=sb" "$BASE/?p=seller" -o /dev/null
SBKEY=$(q "SELECT api_key FROM nodes WHERE id=2;")
ok "$(q "SELECT core FROM nodes WHERE id=2;")" "singbox" "节点内核为 singbox"
# 买卖双方各取一次订阅建行（买家余额为 0 也会建行，只是订阅过滤付费节点）
curl -s "$BASE/api/sub.php?token=$TOKEN&format=uri" -o /dev/null
curl -s "$BASE/api/sub.php?token=$STOKEN&format=uri" -o /dev/null
CFG2=$(curl -s "$BASE/api/agent_config.php?node_id=2&api_key=$SBKEY")
echo "$CFG2" | python3 -c "
import json,sys
c=json.load(sys.stdin)
va=c['experimental']['v2ray_api']
assert va['listen']=='127.0.0.1:10085' and va['stats']['enabled'] is True
assert len(va['stats']['users'])==2, va['stats']
ib=[i for i in c['inbounds'] if i['type']=='vless'][0]
assert ib['listen_port']==20001
assert all(u.get('name') for u in ib['users']), 'name 必填'
assert all(u['name']==u['uuid'] for u in ib['users'])
assert ib['tls']['reality']['private_key'] and ib['tls']['reality']['short_id']
print('sing-box config ok')
" && { echo "PASS: sing-box 配置合法（v2ray_api + 用户 name）"; PASS=$((PASS+1)); } || { echo "FAIL: sing-box 配置"; FAIL=$((FAIL+1)); }
# sing-box 部署脚本：含 gRPC 桩，agent 与桩语法合法
curl -s "$BASE/?p=deploy_raw&id=2&key=$SBKEY" > /tmp/deploy_sb.sh
grep -q "stats_pb2_grpc" /tmp/deploy_sb.sh && { echo "PASS: sing-box 脚本含 gRPC 桩"; PASS=$((PASS+1)); } || { echo "FAIL: gRPC 桩"; FAIL=$((FAIL+1)); }
python3 -c "
import re
s=open('/tmp/deploy_sb.sh').read()
m=re.search(r\"<<'PYEOF'\n(.*?)\nPYEOF\", s, re.S)
open('/tmp/agent_sb.py','w').write(m.group(1))
p1=re.search(r\"stats_pb2\.py <<'PBEOF'\n(.*?)\nPBEOF\", s, re.S)
open('/tmp/pb1.py','w').write(p1.group(1))
p2=re.search(r\"stats_pb2_grpc\.py <<'PBEOF'\n(.*?)\nPBEOF\", s, re.S)
open('/tmp/pb2.py','w').write(p2.group(1))
" && python3 -m py_compile /tmp/agent_sb.py /tmp/pb1.py /tmp/pb2.py && { echo "PASS: sing-box agent + 桩语法合法"; PASS=$((PASS+1)); } || { echo "FAIL: sing-box agent 语法"; FAIL=$((FAIL+1)); }
bash -n /tmp/deploy_sb.sh && { echo "PASS: sing-box 部署脚本 bash 语法合法"; PASS=$((PASS+1)); } || { echo "FAIL: sing-box 脚本语法"; FAIL=$((FAIL+1)); }

echo ""
echo "结果：$PASS 通过，$FAIL 失败"
[ "$FAIL" = "0" ]
exit 0
