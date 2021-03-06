<?php
namespace Ackintosh\Snidel;

use Bernard\Driver\FlatFileDriver;

class Config
{
    /** @var array */
    private $params;

    /**
     * @param array $params
     */
    public function __construct($params = [])
    {
        $default = [
            'concurrency'   => 5,
            'logger'        => null,
            'driver' => null,
        ];

        $this->params = array_merge($default, $params);
        $this->params['ownerPid'] = getmypid();
        $this->params['id'] = spl_object_hash($this);
        if (!$this->params['driver']) {
            $this->params['driver'] = new FlatFileDriver(
                sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->params['id']
            );
        }
    }

    /**
     * @param   string  $name
     * @return  mixed
     */
    public function get($name)
    {
        return $this->params[$name];
    }
}
