<?php

declare(strict_types=1);

//namespace danog\MadelineProto\Loop\Generic;

use danog\MadelineProto\API;
use danog\MadelineProto\Generic\GenericLoop;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Loop\Impl\ResumableSignalLoop;

abstract class AbstractLoop extends ResumableSignalLoop
{
    const STOP     = -1;
    const PAUSE    = null;
    const CONTINUE = 0;

    protected API              $mp;
    protected BaseEventHandler $eh;
    protected array    $robotConfig;
    protected UserDate $userDate;

    function __construct(API $mp, BaseEventHandler $eh)
    {
        parent::__construct($mp);
        $this->mp = $mp;
        $this->eh = $eh;
        $this->robotConfig = $GLOBALS['robotConfig'];
        $this->userDate = new \UserDate($robotConfig['zone'] ?? 'America/Los_Angeles');
        $this->eh->logger("AbstractLoop constructor for '{$this}' is invoked", Logger::ERROR);
    }

    function __sleep(): array
    {
        return [];
    }

    public function onStart(): \Generator
    {
        $this->start();
        if (method_exists($this, 'onStart')) {
            yield $this->onStart();
        }
        return;
    }

    public function loop(): \Generator
    {
        $state = $this->eh->getLoopState((string)$this);
        $this->eh->logger("AbstractLoop loop invoked for '{$this}' with " . ($state ? 'ON' : 'OFF') . " state!", Logger::ERROR);
        while (true) {
            $state   = $this->eh->getLoopState((string)$this);
            $timeout = yield $this->pluggedLoop($state);
            if ($timeout === self::PAUSE) {
                //$this->eh->logger->logger("Pausing {$this} loop!", Logger::ERROR);
            } elseif ($timeout > 0) {
                //$this->eh->logger->logger("Pausing {$this} loop for {$timeout} seconds!", Logger::ERROR);
            }
            if ($timeout === self::STOP || yield $this->waitSignal($this->pause($timeout))) {
                //$this->eh->logger("The {$this} loop plugin exited!", Logger::ERROR);
                return;
            }
        }
    }

    public function __toString(): string
    {
        $className = get_class($this);
        $loopName  = substr($className, 0, strlen($className) - 4);
        return strtolower($loopName);
    }

    public function __invoke(array $update, array $vars): \Generator
    {
        // Handle $update.  Use $vars if necessary
        // .....
        return false; // return true if $update is handled.
        yield;
    }

    /**
     * Log a message.
     *
     * @param mixed  $param Message to log
     * @param int    $level Logging level
     * @param string $file  File that originated the message
     *
     * @return void
     */
    protected function logger($param, int $level = Logger::NOTICE, string $file = ''): void
    {
        $this->eh->getLogger()->logger($param, $level, $file);
    }

    static function secondsToNexMinute(float $now = null): int
    {
        $now = $now ?? \microtime(true);
        $now  = (int) ($now * 1000000);
        $next = (int)ceil($now / 60000000) * 60000000;
        $diff = ($next - $now);
        $secs = (int)round($diff / 1000000);
        return $secs > 0 ? $secs : 60;
    }

    /**
     * pluggedLoop.
     *
     * The return value of the pluggedLoop can be:
     *    A number           - the loop will be paused for the specified number of seconds
     *    GenericLoop::STOP  - The loop will stop
     *    GenericLoop::PAUSE - The loop will pause forever (or until the `resume` method is called on 
     *                         the loop object from outside the loop)
     *    GenericLoop::CONTINUE - Return this if you want to rerun the loop without waiting.
     *
     * @param \danog\MadelineProto\API $API      Instance of MadelineProto
     * @param callable                 $callback Callback to run
     * @param string                   $name     Fetcher name
     */
    abstract protected function pluggedLoop(bool $state): \Generator;
}
