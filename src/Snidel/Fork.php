<?php
namespace Ackintosh\Snidel;

use Ackintosh\Snidel\DataRepository;
use Ackintosh\Snidel\Pcntl;
use Ackintosh\Snidel\Exception\SharedMemoryControlException;

class Fork
{
    /** @var int */
    private $pid;

    /** @var \Ackintosh\Snidel\Pcntl */
    private $pcntl;

    /** @var \Ackintosh\Snidel\DataRepository */
    private $dataRepository;

    /** @var int */
    private $status;

    /** @var callable */
    private $callable;

    /** @var array */
    private $args;

    /** @var \Ackintosh\Snidel\Result */
    private $result;

    /**
     * @param   int     $pid
     */
    public function __construct($pid)
    {
        $this->pid              = $pid;
        $this->pcntl            = new Pcntl();
        $this->dataRepository   = new DataRepository();
    }

    /**
     * set exit status
     *
     * @param   int     $status
     * @return  void
     */
    public function setStatus($status)
    {
        $this->status = $status;
    }

    /**
     * return pid
     *
     * @return  int
     */
    public function getPid()
    {
        return $this->pid;
    }

    /**
     * return exit status
     *
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * set callable
     *
     * @param   callable    $callable
     * @return  void
     */
    public function setCallable($callable)
    {
        $this->callable = $callable instanceof \Closure ? '*Closure*' : $callable;
    }

    public function getCallable()
    {
        return $this->callable;
    }

    /**
     * set arguments
     *
     * @param   array   $args
     * @return  void
     */
    public function setArgs($args)
    {
        $this->args = $args;
    }

    public function getArgs()
    {
        return $this->args;
    }
    /**
     * @return bool
     */
    public function isSuccessful()
    {
        return $this->pcntl->wifexited($this->status) && $this->pcntl->wexitstatus($this->status) === 0;
    }

    /**
     * load result
     *
     * @return void
     * @throws \Ackintosh\Snidel\Exception\SharedMemoryControlException
     */
    public function loadResult()
    {
        try {
            $this->result = $this->dataRepository->load($this->pid)->readAndDelete();
        } catch (SharedMemoryControlException $e) {
            throw $e;
        }
    }

    /**
     * return result
     *
     * @return \Ackintosh\Snidel\Result
     */
    public function getResult()
    {
        return $this->result;
    }
}