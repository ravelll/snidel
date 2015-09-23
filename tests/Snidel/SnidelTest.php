<?php
class SnidelTest extends PHPUnit_Framework_TestCase
{
    /**
     * @test
     */
    public function forkProcessAndReceiveValues()
    {
        $snidel = new Snidel();

        $snidel->fork('receivesArgumentsAndReturnsIt', array('foo'));
        $snidel->fork('receivesArgumentsAndReturnsIt', array('bar'));
        $snidel->join();

        $this->assertSame($snidel->get(), array('foo', 'bar'));
    }

    /**
     * @test
     */
    public function omitTheSecondArgumentOfFork()
    {
        $snidel = new Snidel();

        $snidel->fork('returnsFoo');
        $snidel->join();

        $this->assertSame($snidel->get(), array('foo'));
    }

    /**
     * @test
     */
    public function passTheValueOtherThanArray()
    {
        $snidel = new Snidel();

        $snidel->fork('receivesArgumentsAndReturnsIt', 'foo');
        $snidel->join();

        $this->assertSame($snidel->get(), array('foo'));
    }

    /**
     * @test
     */
    public function passMultipleArguments()
    {
        $snidel = new Snidel();

        $snidel->fork('receivesArgumentsAndReturnsIt', array('foo', 'bar'));
        $snidel->join();

        $this->assertSame($snidel->get(), array('foobar'));
    }

    /**
     * @test
     */
    public function maxProcs()
    {
        $maxProcs = 3;
        $snidel = new Snidel($maxProcs);

        $start = time();
        $snidel->fork('sleepsTwoSeconds');
        $snidel->fork('sleepsTwoSeconds');
        $snidel->fork('sleepsTwoSeconds');
        $snidel->fork('sleepsTwoSeconds');
        $snidel->join();
        $elapsed = time() - $start;

        $snidel->get();
        $this->assertTrue(4 <= $elapsed && $elapsed < 6);
    }

    /**
     * @test
     */
    public function runInstanceMethod()
    {
        $snidel = new Snidel();
        $test = new TestClass();

        $snidel->fork(array($test, 'returnsFoo'));
        $snidel->fork(array($test, 'receivesArgumentsAndReturnsIt'), 'bar');
        $snidel->join();

        $this->assertSame($snidel->get(), array('foo', 'bar'));
    }

    /**
     * @test
     * @requires PHP 5.3
     */
    public function runAnonymousFunction()
    {
        $snidel = new Snidel();

        // In order to avoid Parse error in php5.2, `eval` is used.
        eval(<<<__EOS__
\$func = function (\$arg = 'foo') {
    return \$arg;
};
__EOS__
);

        $snidel->fork($func);
        $snidel->fork($func, 'bar');
        $snidel->join();

        $this->assertSame($snidel->get(), array('foo', 'bar'));
    }
}
