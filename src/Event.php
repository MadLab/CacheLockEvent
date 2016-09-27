<?php

namespace madlab\MultiServerScheduling;

use Illuminate\Console\Scheduling\Event as NativeEvent;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;

class Event extends NativeEvent
{
    /**
     * Hash we will use to identify our server and lock the process.
     * @var string
     */
    protected $serverId;

    /**
     * Hash of the command mutex we will use to uniquely identify the command.
     * @var string
     */
    protected $key;

    /**
     * Create a new event instance.
     *
     * @param  string  $command
     * @return void
     */
    public function __construct($command)
    {
        parent::__construct($command);
        $this->serverId = str_random(32);
    }

    /**
     * Run the given event, then clear our lock.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public function run(Container $container)
    {
        parent::run($container);
        $this->clearCache();
    }

    /**
     * Prevents this command from executing across multiple servers attempting
     * at the same time.
     * @return $this
     */
    public function withoutOverlappingCache()
    {
        return $this->skip(function () {
            return $this->skipCache();
        });
    }

    /**
     * Attempt to lock this command.
     * @return bool true if we want to skip
     */
    public function skipCache()
    {
        $this->key = md5($this->expression.$this->command);


        // Check to see if the mutex is found in the cache
        if(Cache::has($this->key)){
            //Someone else has the lock.
            return true;
        }

        // Attempt to acquire the lock
        $eventInfo = collect([]);
        $eventInfo->put('command',$this->command);
        $eventInfo->put('expression',$this->expression);
        $eventInfo->put('lock',$this->serverId);
        $eventInfo->put('startDate',\Carbon\Carbon::now());

        // If the mutex already exists in the cache, this 'add' will return false.
        // If it succeeds, we set a max execution time of 1 hour. At that point, we
        // consider the task 'abandoned' and the lock will be released.
        if(Cache::add($this->key, $eventInfo, \Carbon\Carbon::now()->addHour(1))){
            //we got the lock
            return false;
        }

        // Someone else has the lock
        return true;
    }

    /**
     * Delete our locks.
     * @return void 
     */
    public function clearCache()
    {
        // The task finished, so Set the lock to expire in 10 seconds
        if ($this->serverId) {
            $eventInfo = Cache::pull($this->key);
            Cache::put($this->key, $eventInfo, \Carbon\Carbon::now()->addSeconds(10));
        }
    }
}
