<?php

namespace wen202402\common\helper;



class SwooleHelper{
    public static function startProcess($cmd,$jobs){
        $procs = ['queue' => null, 'cron'  => null,];
        $pids  = ['queue' => null, 'cron'  => null,];
        $startJob = function(string $name) use ($cmd, $jobs, &$procs, &$pids) {
            $args = $jobs[$name]['args'];
            $proc = new \Swoole\Process(function(\Swoole\Process $p) use ($cmd, $args) {$p->exec($cmd, $args);});
            $proc->useQueue();
            $procs[$name] = $proc;
            $pid = $proc->start();
            $pids[$name] = $pid;
            error_log(ucfirst($name) . " 已启动，PID=" . $pid . "\n");
        };


        foreach (array_keys($jobs) as $name) $startJob($name);
        foreach (array_keys($jobs) as $name) {
            if ($procs[$name] === null) {
                $startJob($name);
                continue;
            }


            $pid = $pids[$name];
            if (!$pid) {
                $startJob($name);
                continue;
            }

            $alive = false;
            if (function_exists('posix_kill')) $alive = (posix_kill((int)$pid, 0) === true);


            if (!$alive) {
                error_log(ucfirst($name) . " 已退出，准备重启\n");
                try {  $procs[$name]->kill(); } catch (\Throwable $e) { }

                $procs[$name] = null;
                $pids[$name]  = null;
                $startJob($name);
            }
        }


    }




}