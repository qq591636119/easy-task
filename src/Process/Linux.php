<?php
namespace EasyTask\Process;

use EasyTask\Command;
use \Event as Event;
use \EventConfig as EventConfig;
use \EventBase as EventBase;
use \ArrayObject as ArrayObject;
use EasyTask\Helper;

/**
 * Class Linux
 * @package EasyTask\Process
 */
class Linux
{
    /**
     * Task实例
     * @var $task
     */
    private $task;

    /**
     * 进程启动时间
     * @var int
     */
    private $startTime;

    /**
     * 进程命令管理
     * @var ArrayObject
     */
    private $commander;

    /**
     * 进程执行记录
     * @var array
     */
    private $processList = [];

    /**
     * 构造函数
     * @throws
     * @var  $task
     */
    public function __construct($task)
    {
        $this->task = $task;
        $this->startTime = time();
        $this->commander = new Command();
        if (!$task->canEvent && $task->canAsync)
        {
            pcntl_async_signals(true);
        }
    }

    /**
     * 开始运行
     */
    public function start()
    {
        //初始配置
        if ($this->task->daemon)
        {
            $this->daemon();
        }
        if ($this->task->umask)
        {
            umask(0);
        }
        if ($this->task->closeInOut)
        {
            fclose(STDIN);
            fclose(STDOUT);
        }

        //发送命令,关闭重复进程
        $this->commander->send([
            'type' => 'start',
            'msgType' => 2
        ]);

        //分配进程
        $this->allocate();
    }

    /**
     * 运行状态
     */
    public function status()
    {
        //发送查询命令
        $this->commander->send([
            'type' => 'status',
            'msgType' => 2
        ]);

        //等待返回结果
        $this->initWaitExit();
    }

    /**
     * 停止运行
     * @param bool $force 是否强制
     */
    public function stop($force = false)
    {
        //发送关闭命令
        $this->commander->send([
            'type' => 'stop',
            'force' => $force,
            'msgType' => 2
        ]);
    }

    /**
     * 守护进程
     * @throws
     */
    private function daemon()
    {
        $pid = pcntl_fork();
        if ($pid < 0)
        {
            Helper::showError("fork process failed");
        }
        elseif ($pid)
        {
            $this->initWaitExit();
        }
        else
        {
            $sid = posix_setsid();
            if ($sid < 0)
            {
                Helper::showError("set processForInit failed");
            }
        }
    }

    /**
     * 分配进程处理任务
     */
    private function allocate()
    {
        foreach ($this->task->taskList as $item)
        {
            //提取参数
            $alas = $item['alas'];
            $time = $item['time'];
            $date = date('Y-m-d H:i:s');
            $used = $item['used'];
            $alas = "{$this->task->prefix}_{$alas}";

            //根据Worker数分配进程
            for ($i = 0; $i < $used; $i++)
            {
                $pid = pcntl_fork();
                if ($pid == -1)
                {
                    exit();
                }
                elseif ($pid)
                {
                    //记录进程
                    $ppid = posix_getpid();
                    $this->processList[] = ['pid' => $pid, 'task_name' => $alas, 'started' => $date, 'timer' => $time . 's', 'status' => 'active', 'ppid' => $ppid,];

                    //主进程设置非阻塞
                    pcntl_wait($status, WNOHANG);
                }
                else
                {
                    //执行任务
                    $this->invoker($time, $alas, $item);
                }
            }
        }

        $this->daemonWait();
    }

    /**
     * 执行器
     * @param int $time 执行间隔
     * @param string $alas 进程名称
     * @param array $item 执行项目
     */
    private function invoker($time, $alas, $item)
    {
        if ($time == 0)
        {
            $this->invokerByDirect($alas, $item);
        }
        if (!$this->task->canEvent)
        {
            $this->invokeByAlarm($time, $alas, $item);
        }
        else
        {
            $this->invokeByEvent($time, $alas, $item);
        }
    }

    /**
     * 普通执行(执行完成,直接退出)
     * @param string $alas 进程名称
     * @param array $item 执行项目
     */
    private function invokerByDirect($alas, $item)
    {
        //进程标题
        @cli_set_process_title($alas);

        //执行程序
        $this->execUserCode($item);

        //进程退出
        exit();
    }

    /**
     * 通过闹钟信号执行
     * @param int $time 执行间隔
     * @param string $alas 进程名称
     * @param array $item 执行项目
     */
    private function invokeByAlarm($time, $alas, $item)
    {
        //进程标题
        @cli_set_process_title($alas);

        //安装信号管理
        pcntl_signal(SIGALRM, function () use ($time, $item) {
            pcntl_alarm($time);
            $this->execUserCode($item);
        }, false);

        //发送闹钟信号
        pcntl_alarm($time);

        //挂起进程(同步调用信号,异步CPU休息)
        while (true)
        {
            //CPU休息
            sleep(1);

            //同步模式(调用信号处理)
            if (!$this->task->canAsync) pcntl_signal_dispatch();
        }
    }

    /**
     * 通过Event事件执行
     * @param int $time 执行间隔
     * @param string $alas 进程名称
     * @param array $item 执行项目
     */
    private function invokeByEvent($time, $alas, $item)
    {
        //进程标题
        @cli_set_process_title($alas);

        //创建Event事件
        $eventConfig = new EventConfig();
        $eventBase = new EventBase($eventConfig);
        $event = new Event($eventBase, -1, Event::TIMEOUT | Event::PERSIST,$this->execUserCode($item));

        //添加事件
        $event->add($time);

        //事件循环
        $eventBase->loop();
    }

    /**
     * 执行用户代码
     * @param array $item 执行项目
     */
    private function execUserCode($item)
    {
        if ($item['type'] == 1)
        {
            $func = $item['func'];
            $func();
        }
        elseif ($item['type'] == 2)
        {
            call_user_func([$item['class'], $item['func']]);
        }
        elseif ($item['type'] == 3)
        {
            $object = new $item['class']();
            call_user_func([$object, $item['func']]);
        }
        else
        {
            @pclose(@popen($item['command'], 'r'));
        }
    }

    /**
     * init进程等待结束退出
     */
    private function initWaitExit()
    {
        $i = 5;
        while ($i--)
        {
            //CPU休息1秒
            sleep(1);

            //接收汇报
            $this->WaitCommandForExecute(1, function ($report) {
                if ($report['type'] == 'status')
                {
                    Helper::showTable($report['status']);
                }
                if ($report['type'] == 'allocate')
                {
                    Helper::showTable($report['allocate']);
                }
            });
        }
        exit();
    }

    /**
     * 守护进程常驻
     */
    private function daemonWait()
    {
        //守护进程设置进程名
        @cli_set_process_title($this->task->prefix);

        //任务汇报Init进程
        $this->commander->send([
            'type' => 'allocate',
            'msgType' => 1,
            'allocate' => $this->processList,
        ]);

        //监听Kill命令
        pcntl_signal(SIGTERM, function () {
            posix_kill(0, SIGTERM);
            exit();
        });

        //挂起进程
        while (true)
        {
            //CPU休息1秒
            sleep(1);

            //接收命令
            $this->waitCommandForExecute(2, function ($command) {
                //监听启动命令
                if ($command['type'] == 'start')
                {
                    if ($command['time'] > $this->startTime)
                    {
                        posix_kill(0, SIGTERM) && exit();
                    }
                }

                //监听查询命令
                if ($command['type'] == 'status')
                {
                    $this->processStatus();
                    $this->commander->send([
                        'type' => 'status',
                        'msgType' => 1,
                        'status' => $this->processList,
                    ]);
                }

                //监听停止命令
                if ($command['type'] == 'stop')
                {
                    $command['force'] ? posix_kill(0, SIGKILL) : posix_kill(0, SIGTERM) && exit();
                }

            });

            //调用信号处理
            if (!$this->task->canAsync) pcntl_signal_dispatch();
        }
    }

    /**
     * 查看进程状态
     */
    private function processStatus()
    {
        foreach ($this->processList as $key => $item)
        {
            //提取参数
            $pid = $item['pid'];

            //检查进程状态
            $rel = pcntl_waitpid($pid, $status, WNOHANG);
            if ($rel == -1 || $rel > 0)
            {
                $this->processList[$key]['status'] = 'stop';
            }
        }
    }

    /**
     * 根据命令执行对应操作
     * @param int $msgType 消息类型
     * @param \Closure $func 执行函数
     */
    private function waitCommandForExecute($msgType, $func)
    {
        $command = '';
        $this->commander->receive($msgType, $command);
        if (!$command || (!empty($command['time']) && (time() - $command['time']) > 5))
        {
            return;
        }
        $func($command);
    }
}