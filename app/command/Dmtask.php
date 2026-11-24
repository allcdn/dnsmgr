<?php

declare(strict_types=1);

namespace app\command;

use Exception;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use think\facade\Config;
use app\service\TaskRunner;

class Dmtask extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('dmtask')
            ->setDescription('容灾切换任务');
    }

    protected function execute(Input $input, Output $output)
    {
        $res = Db::name('config')->cache('configs', 0)->column('value', 'key');
        Config::set($res, 'sys');

        config_set('run_error', '');
        if (!extension_loaded('swoole')) {
            $output->writeln('[Error] 未安装Swoole扩展');
            config_set('run_error', '未安装Swoole扩展');
            return;
        }
        try {
            $output->writeln('进程启动成功.');
            $this->runtask();
        } catch (Exception $e) {
            $output->writeln('[Error] ' . $e->getMessage());
            config_set('run_error', $e->getMessage());
        }
    }

    private function runtask()
    {
        \Co::set(['hook_flags' => SWOOLE_HOOK_ALL]);
        \Co\run(function () {
            $date = date("Ymd");
            $count = config_get('run_count', null, true) ?? 0;
            $running = [];
            while (true) {
                sleep(1);
                if ($date != date("Ymd")) {
                    $count = 0;
                    $date = date("Ymd");
                }

                $query = Db::name('dmtask')->where('checknexttime', '<=', time())->where('active', 1);
                // DNS 请求可能超过检测间隔；不要用旧状态并发执行同一条策略。
                if ($running) $query->whereNotIn('id', array_keys($running));
                foreach ($query->order('id', 'ASC')->select() as $row) {
                    \go(function () use ($row, &$running) {
                        $running[$row['id']] = true;
                        try {
                            (new TaskRunner())->execute($row);
                        } catch (\Swoole\ExitException $e) {
                            echo $e->getStatus() . "\n";
                        } catch (Exception $e) {
                            echo $e->__toString() . "\n";
                        } finally {
                            unset($running[$row['id']]);
                        }
                    });
                    Db::name('dmtask')->where('id', $row['id'])->update([
                        'checktime' => time(),
                        'checknexttime' => time() + $row['frequency']
                    ]);
                    $count++;
                }

                config_set('run_time', date("Y-m-d H:i:s"));
                config_set('run_count', $count);
            }
        });
    }
}
