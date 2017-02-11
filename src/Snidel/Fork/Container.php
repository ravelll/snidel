<?php
declare(ticks = 1);
namespace Ackintosh\Snidel\Fork;

use Ackintosh\Snidel\Config;
use Ackintosh\Snidel\Fork\Fork;
use Ackintosh\Snidel\Pcntl;
use Ackintosh\Snidel\Task\Queue as TaskQueue;
use Ackintosh\Snidel\QueueFactory;
use Ackintosh\Snidel\Result\Result;
use Ackintosh\Snidel\Result\Queue as ResultQueue;
use Ackintosh\Snidel\Result\Collection;
use Ackintosh\Snidel\Error;
use Ackintosh\Snidel\Exception\SharedMemoryControlException;
use Ackintosh\Snidel\Worker;
use Ackintosh\Snidel\ActiveWorkerSet;

class Container
{
    /** @var int */
    private $ownerPid;

    /** @var int */
    private $masterPid;

    /** @var \Ackintosh\Snidel\Result\Result[] */
    private $results = array();

    /** @var \Ackintosh\Snidel\Pcntl */
    private $pcntl;

    /** @var \Ackintosh\Snidel\Error */
    private $error;

    /** @var \Ackintosh\Snidel\Task\QueueInterface */
    private $taskQueue;

    /** @var \Ackintosh\Snidel\Result\QueueInterface */
    private $resultQueue;

    /** @var \Ackintosh\Snidel\Log */
    private $log;

    /** @var array */
    private $signals = array(
        SIGTERM,
        SIGINT,
    );

    /** @var \Ackintosh\Snidel\Config */
    private $config;

    /** @var \Ackintosh\Snidel\QueueFactory  */
    private $queueFactory;

    /** @var  int */
    private $receivedSignal;

    /**
     * @param   int     $ownerPid
     */
    public function __construct($ownerPid, $log, Config $config)
    {
        $this->ownerPid         = $ownerPid;
        $this->log              = $log;
        $this->config           = $config;
        $this->pcntl            = new Pcntl();
        $this->error            = new Error();
        $this->queueFactory     = new QueueFactory($config);
    }

    /**
     * @param   \Ackintosh\Snidel\Task
     * @return  void
     * @throws  \RuntimeException
     */
    public function enqueue($task)
    {
        try {
            $this->taskQueue->enqueue($task);
        } catch (\RuntimeException $e) {
            throw $e;
        }
    }

    /**
     * @return  int
     */
    public function queuedCount()
    {
        if (is_null($this->taskQueue)) {
            return 0;
        }

        return $this->taskQueue->queuedCount();
    }

    /**
     * @return  \Ackintosh\Snidel\Fork\Fork
     */
    private function dequeue()
    {
        return $this->resultQueue->dequeue();
    }

    /**
     * @return  int
     */
    public function dequeuedCount()
    {
        if (is_null($this->resultQueue)) {
            return 0;
        }

        return $this->resultQueue->dequeuedCount();
    }

    /**
     * fork process
     *
     * @return  \Ackintosh\Snidel\Fork\Fork
     * @throws  \RuntimeException
     */
    private function fork()
    {
        $pid = $this->pcntl->fork();
        if ($pid === -1) {
            throw new \RuntimeException('could not fork a new process');
        }

        $pid = ($pid === 0) ? getmypid() : $pid;

        return new Fork($pid);
    }

    /**
     * fork master process
     *
     * @return  int     $masterPid
     */
    public function forkMaster()
    {
        try {
            $fork = $this->fork();
        } catch (\RuntimeException $e) {
            throw $e;
        }

        $this->masterPid = $fork->getPid();
        $this->log->setMasterPid($this->masterPid);

        if (getmypid() === $this->ownerPid) {
            // owner
            $this->log->info('pid: ' . getmypid());
            $this->taskQueue    = $this->queueFactory->createTaskQueue();
            $this->resultQueue  = $this->queueFactory->createResultQueue();

            return $this->masterPid;
        } else {
            // master
            $activeWorkerSet = new ActiveWorkerSet();
            $this->log->info('pid: ' . $this->masterPid);

            $log = $this->log;
            foreach ($this->signals as $sig) {
                $this->pcntl->signal($sig, function ($sig) use ($log, $activeWorkerSet) {
                    $this->receivedSignal = $sig;
                    $log->info('received signal: ' . $sig);

                    if ($activeWorkerSet->count() === 0) {
                        $log->info('no worker is active.');
                    } else {
                        $log->info('------> sending signal to workers. signal: ' . $sig);
                        $activeWorkerSet->terminate($sig);
                        $log->info('<------ sent signal');
                    }
                    exit;
                });
            }

            $concurrency = (int)$this->config->get('concurrency');
            for ($i = 0; $i < $concurrency; $i++) {
                $activeWorkerSet->add($this->forkWorker());
            }
            $status = null;
            while (($workerPid = $this->pcntl->waitpid(-1, $status, WNOHANG)) !== -1) {
                if ($workerPid === true || $workerPid === 0) {
                    usleep(100000);
                    continue;
                }
                $activeWorkerSet->delete($workerPid);
                $activeWorkerSet->add($this->forkWorker());
                $status = null;
            }
            exit;
        }
    }

    /**
     * fork worker process
     *
     * @return  \Ackintosh\Snidel\Worker
     * @throws  \RuntimeException
     */
    private function forkWorker()
    {
        try {
            $fork = $this->fork();
        } catch (\RuntimeException $e) {
            $this->log->error($e->getMessage());
            throw $e;
        }

        $worker = new Worker($fork);

        if (getmypid() === $this->masterPid) {
            // master
            $this->log->info('forked worker. pid: ' . $worker->getPid());
            return $worker;
        } else {
            // worker
            // @codeCoverageIgnoreStart
            $this->log->info('has forked. pid: ' . getmypid());

            foreach ($this->signals as $sig) {
                $this->pcntl->signal($sig, function ($sig) {
                    $this->receivedSignal = $sig;
                    exit;
                }, false);
            }

            $worker->setTaskQueue($this->queueFactory->createTaskQueue());
            $worker->setResultQueue($this->queueFactory->createResultQueue());

            register_shutdown_function(function () use ($worker) {
                if ($worker->isFailedToEnqueueResult() && $this->receivedSignal === null) {
                    $worker->error();
                }
            });

            $this->log->info('----> started the function.');
            try {
                $worker->run();
            } catch (\RuntimeException $e) {
                $this->log->error($e->getMessage());
                exit;
            }
            $this->log->info('<---- completed the function.');

            $this->log->info('queued the result and exit.');
            exit;
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @return  bool
     */
    public function existsMaster()
    {
        return $this->masterPid !== null;
    }

    /**
     * send signal to master process
     *
     * @return  void
     */
    public function sendSignalToMaster($sig = SIGTERM)
    {
        $this->log->info('----> sending signal to master. signal: ' . $sig);
        posix_kill($this->masterPid, $sig);
        $this->log->info('<---- sent signal.');

        $this->log->info('----> waiting for master shutdown.');
        $status = null;
        $this->pcntl->waitpid($this->masterPid, $status);
        $this->log->info('<---- master shutdown. status: ' . $status);
        $this->masterPid = null;
    }

    /**
     *
     * @param   string  $tag
     * @return  bool
     */
    public function hasTag($tag)
    {
        foreach ($this->results as $result) {
            if ($result->getTask()->getTag() === $tag) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return void
     */
    public function wait()
    {
        for (; $this->queuedCount() > $this->dequeuedCount();) {
            $result = $this->dequeue();
            $pid = $result->getFork()->getPid();
            $this->results[$pid] = $result;

            if ($result->isFailure()) {
                $this->error[$pid] = $result;
            }
        }
    }

    public function getCollection($tag = null)
    {
        if ($tag === null) {
            $collection = new Collection($this->results);
            $this->results = array();

            return $collection;
        }

        return $this->getCollectionWithTag($tag);
    }

    /**
     * return results
     *
     * @param   string  $tag
     * @return  \Ackintosh\Snidel\Result\Collection
     */
    private function getCollectionWithTag($tag)
    {
        $results = array();
        foreach ($this->results as $r) {
            if ($r->getTask()->getTag() !== $tag) {
                continue;
            }

            $results[] = $r;
            unset($this->results[$r->getFork()->getPid()]);
        }

        return new Collection($results);
    }

    /**
     * @return  bool
     */
    public function hasError()
    {
        return $this->error->exists();
    }

    /**
     * @return  \Ackintosh\Snidel\Error
     */
    public function getError()
    {
        return $this->error;
    }

    public function __destruct()
    {
        unset($this->taskQueue);
        unset($this->resultQueue);
    }
}
