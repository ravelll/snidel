<?php
namespace Ackintosh\Snidel;

use Ackintosh\Snidel\Fork;
use Ackintosh\Snidel\Result\Formatter as ResultFormatter;
use Ackintosh\Snidel\SharedMemory;
use Ackintosh\Snidel\Exception\SharedMemoryControlException;

class Data
{
    /** @var int */
    private $pid;

    /** @var \Ackintosh\Snidel\SharedMemory */
    private $shm;

    /**
     * @param   int     $pid
     */
    public function __construct($pid)
    {
        $this->pid = $pid;
        $this->shm = new SharedMemory($pid);
    }

    /**
     * write data
     *
     * @param   \Ackintosh\Snidel\Result\Result     $result
     * @return  void
     * @throws  \Ackintosh\Snidel\Exception\SharedMemoryControlException
     */
    public function write($result)
    {
        $serialized = ResultFormatter::serialize($result);
        try {
            $this->shm->open(strlen($serialized));
        } catch (SharedMemoryControlException $e) {
            throw $e;
        }

        try {
            $this->shm->write($serialized);
        } catch (SharedMemoryControlException $e) {
            throw $e;
        }

        $this->shm->close();
    }

    /**
     * read data and delete shared memory
     *
     * @return  mixed
     * @throws  \Ackintosh\Snidel\Exception\SharedMemoryControlException
     */
    public function readAndDelete()
    {
        try {
            $data = $this->read();
            $this->delete();
        } catch (SharedMemoryControlException $e) {
            throw $e;
        }

        return $data;
    }

    /**
     * read data
     *
     * @return  \Ackintosh\Snidel\Result\Result
     * @throws  \Ackintosh\Snidel\Exception\SharedMemoryControlException
     */
    public function read()
    {
        try {
            $this->shm->open();
            $serialized = $this->shm->read();
        } catch (SharedMemoryControlException $e) {
            throw $e;
        }

        $this->shm->close();

        return ResultFormatter::unserialize($serialized);
    }

    /**
     * delete shared memory
     *
     * @return  void
     * @throws  \Ackintosh\Snidel\Exception\SharedMemoryControlException
     */
    public function delete()
    {
        try {
            $this->shm->open();
        } catch (SharedMemoryControlException $e) {
            throw $e;
        }

        try {
            $this->shm->delete();
        } catch (SharedMemoryControlException $e) {
            throw $e;
        }

        $this->shm->close($removeTmpFile = true);
    }

    /**
     * delete shared memory if exists
     *
     * @return  void
     * @throws  \Ackintosh\Snidel\Exception\SharedMemoryControlException
     */
    public function deleteIfExists()
    {
        if (!$this->shm->exists()) {
            return;
        }

        try {
            $this->delete();
        } catch (SharedMemoryControlException $e) {
            throw $e;
        }
    }
}
