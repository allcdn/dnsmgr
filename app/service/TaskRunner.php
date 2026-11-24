<?php

namespace app\service;

use app\lib\NewDb;
use app\lib\DnsHelper;
use app\utils\CheckUtils;
use app\utils\MsgNotice;

/**
 * 容灾监控任务执行
 */
class TaskRunner
{
    private $conn;

    private function db()
    {
        if (!$this->conn) {
            $this->conn = NewDb::pool();
        }
        return $this->conn;
    }

    private function closeDb(bool $broken = false)
    {
        if ($this->conn) {
            NewDb::release($this->conn, $broken);
            $this->conn = null;
        }
    }

    public function execute($row)
    {
        try {
            return $this->handle($row);
        } catch (\Throwable $e) {
            $this->closeDb(true);
            throw $e;
        } finally {
            $this->closeDb();
        }
    }

    private function handle($row)
    {
        if ($row['type'] == 3) { //条件开启解析
            $remain = $this->db()->name('dmtask')->where(['did' => $row['did'], 'rr' => $row['rr'], 'type' => 1, 'status' => 0])->count();
            $status = $remain <= $row['cycle'] ? 1 : 0;
            $action = $status == 1 ? 2 : 1;
        } else {
            if ($row['checktype'] == 2) {
                $result = CheckUtils::curl($row['checkurl'], $row['timeout'], $row['main_value'], $row['proxy'] == 1);
            } elseif ($row['checktype'] == 1) {
                $result = CheckUtils::tcp($row['main_value'], $row['checkurl'], $row['tcpport'], $row['timeout']);
            } else {
                $result = CheckUtils::ping($row['main_value'], $row['checkurl']);
            }

            $status = $result['status'] ? 0 : 1;
            $action = $status == 1 ? 1 : 2;
        }

        if ($status == $row['status']) {
            if ($row['errcount'] > 0) {
                $this->db()->name('dmtask')->where('id', $row['id'])->update(['errcount' => 0]);
            }
            return;
        }
        if ($row['type'] != 3 && $row['errcount'] + 1 < $row['cycle']) {
            $this->db()->name('dmtask')->where('id', $row['id'])->inc('errcount')->update();
            return;
        }

        $drow = $this->db()->name('domain')->alias('A')->join('account B', 'A.aid = B.id')->where('A.id', $row['did'])->field('A.*,B.type,B.config')->find();
        if (!$drow) {
            echo '域名不存在（ID：'.$row['did'].'）'."\n";
            return;
        }
        $row['domain'] = $row['rr'] . '.' . $drow['name'];
        if ($row['type'] != 0) {
            $dns = DnsHelper::getModel2($drow);
            if (!$dns) throw new \RuntimeException('DNS模块不存在');
            if ($row['type'] == 2) {
                $operation = '修改解析';
                $value = $action == 1 ? $row['backup_value'] : $row['main_value'];
                $recordinfo = json_decode($row['recordinfo'] ?? '', true);
                if (!isset($recordinfo['Line'], $recordinfo['TTL'])) {
                    throw new \RuntimeException('解析记录信息不完整，无法执行切换');
                }
                // 只覆盖备用记录；回切时使用策略保存的主记录代理状态和 TTL。
                if ($drow['type'] == 'cloudflare' && $row['cdn'] == 1 && $action == 1) {
                    $recordinfo['Line'] = '1';
                    $recordinfo['TTL'] = 1; // Cloudflare Auto
                }
                $res = $dns->updateDomainRecord($row['recordid'], $row['rr'], getDnsType($value), $value, $recordinfo['Line'], $recordinfo['TTL']);
            } elseif ($row['type'] == 1 || $row['type'] == 3) {
                $operation = $action == 1 ? '暂停解析' : '启用解析';
                $res = $dns->setDomainRecordStatus($row['recordid'], $action == 1 ? '0' : '1');
            } else {
                throw new \RuntimeException('未知容灾切换类型');
            }
            if (!$res) {
                $this->db()->name('log')->insert(['uid' => 0, 'domain' => $drow['name'], 'action' => $operation.'失败', 'data' => $dns->getError(), 'addtime' => date('Y-m-d H:i:s')]);
                return;
            }
        }

        // DNS 操作成功后才提交状态；失败时保留确认次数，让下次同向探测重试。
        $this->db()->name('dmtask')->where('id', $row['id'])->update(['status' => $status, 'errcount' => 0, 'switchtime' => time()]);
        $this->db()->name('dmlog')->insert([
            'taskid' => $row['id'],
            'action' => $action,
            'errmsg' => isset($result) ? ($result['status'] ? null : $result['errmsg']) : null,
            'date' => date('Y-m-d H:i:s')
        ]);
        $this->closeDb();

        if ($row['type'] != 3) {
            MsgNotice::send($action, $row, $result);
        }
    }
}
