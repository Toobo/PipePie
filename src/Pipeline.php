<?php namespace Toobo\PipePie;

use SplObjectStorage;
use SplStack;
use LogicException;

/**
 * @author  Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 * @package Toobo\PipePie
 * @license http://opensource.org/licenses/MIT MIT
 */
class Pipeline
{
    const TO_ARRAY  = 1;
    const TO_OBJECT = 2;
    const TO_STRING = 4;
    const TO_INT    = 8;
    const TO_BOOL   = 16;
    const TO_FLOAT  = 32;

    private static $casters_map = [
        self::TO_ARRAY  => 'toArray',
        self::TO_OBJECT => 'toObject',
        self::TO_STRING => 'toString',
        self::TO_INT    => 'toInt',
        self::TO_BOOL   => 'toBool',
        self::TO_FLOAT  => 'toFloat',
    ];

    /**
     * @var \SplObjectStorage
     */
    private $pipeline;

    /**
     * @var mixed
     */
    private $context;

    /**
     * @var callable|int|null
     */
    private $caster;

    /**
     * @var bool
     */
    private $castFirst;

    /**
     * @var bool
     */
    private $locked;

    /**
     * @var bool
     */
    private $working;

    /**
     * @var \SplStack
     */
    private $DTOs;

    /**
     * @param mixed             $context   Something that can be accessed with $dto->context()
     *                                     acting as context for all the callbacks
     * @param int|callable|null $caster    Values returned by any of pipeline callbacks can be
     *                                     casted using a class constants or a custom callback
     * @param bool              $castFirst Should initial value should be casted?
     */
    public function __construct($context = null, $caster = null, $castFirst = false)
    {
        $this->pipeline = new SplObjectStorage();
        if (is_int($caster) && array_key_exists($caster, self::$casters_map)) {
            $caster = [$this, self::$casters_map[$caster]];
        }
        $this->caster = is_callable($caster) ? $caster : null;
        $this->castFirst = $this->toBool($castFirst);
        $this->context = $context;
        $this->DTOs = new SplStack();
        $this->locked = false;
        $this->working = false;
    }

    /**
     * Adds a process callback to pipeline.
     * It is possible to add an array of *additional* arguments to the callback.
     * Argument for added callbacks will be:
     * - 1st argument will always be the result of previous callback in the pipeline;
     * - 2nd argument will be the "initial" data;
     * - 3rd argument will be a DTO instance;
     * - from 4th to unlimited arguments can be set via $args param.
     *
     * @param  callable                $callback
     * @param  array                   $args
     * @return \Toobo\PipePie\Pipeline
     */
    public function pipe(callable $callback, array $args = [])
    {
        if ($this->locked) {
            throw new LogicException("It is not possible to pipe a callback on a locked pipeline.");
        }
        if ($this->working) {
            throw new LogicException("It is not possible to pipe a callback while a Pipeline works.");
        }
        $this->pipeline->attach($this->normalizeCallback($callback), $args);

        return $this;
    }

    /**
     * Performs the pipeline of callbacks to initial data.
     * It is possible to set an starting value, and to specify if that starting value has to be
     * casted (only has effect if a caster is set).
     *
     * @param  mixed              $initial
     * @param  \Toobo\PipePie\DTO $dto
     * @param  mixed              $cursor
     * @return mixed
     */
    public function applyTo($initial, DTO $dto = null, $cursor = null)
    {
        if ($this->working) {
            throw new LogicException("It is not possible run a Pipeline that is already working.");
        }
        if ($this->pipeline->count() === 0) {
            return $initial;
        }
        $this->DTOs->push($this->init($dto, $initial));
        $carry = $this->initialValue($initial, $cursor);
        while ($this->pipeline->valid()) {
            $carry = $this->run($initial, $carry);
            $this->pipeline->next();
        }
        $this->working = false;

        return $carry;
    }

    /**
     * Allows to use current Pipeline as a callback to be piped into a "parent" Pipeline.
     *
     * @param  mixed              $carry
     * @param  mixed              $initial
     * @param  \Toobo\PipePie\DTO $dto
     * @return mixed
     */
    public function __invoke($carry, $initial, DTO $dto = null)
    {
        return $this->applyTo($initial, $dto, $carry);
    }

    /**
     * Returns the array of logs.
     *
     * @return array
     */
    public function info()
    {
        if ($this->working) {
            throw new LogicException("It is not possible get info on a working Pipeline.");
        }
        $info = ['context' => $this->context];
        while ($this->DTOs->count()) {
            $info[] = $this->DTOs->pop()->toArray($this);
        }

        return $info;
    }

    /**
     * Ensures that callbacks are objects so they can be stored in SplObjectStorage.
     *
     * @param  callable $callback
     * @return object   If an object as __invoke() method is a callable.
     */
    private function normalizeCallback(callable $callback)
    {
        if (! is_object($callback)) {
            return function () use ($callback) {
                return call_user_func_array($callback, func_get_args());
            };
        }

        return $callback;
    }

    /**
     * @param  \Toobo\PipePie\DTO|null $dto
     * @param  mixed                   $initial
     * @return \Toobo\PipePie\DTO
     */
    private function init(DTO $dto = null, $initial = null)
    {
        if (is_null($dto)) {
            $dto = new DTO($this, $initial, $this->context);
        } elseif (! $dto->acceptInput($initial)) {
            throw new LogicException('Custom DTO need to be instantiated with proper initial value.');
        }
        $this->working = true;
        $this->locked = true;
        $this->pipeline->rewind();

        return $dto;
    }

    /**
     * Setup initial value for Pipeline based on caster settings.
     *
     * @param  mixed $initial
     * @param  mixed $cursor
     * @return mixed
     */
    private function initialValue($initial, $cursor)
    {
        if (! is_null($cursor)) {
            return $this->maybeCast($cursor);
        }

        return $this->castFirst ? $this->maybeCast($initial) : $initial;
    }

    /**
     * Run current callback in the pipeline.
     *
     * @param $initial
     * @param $carry
     * @return mixed
     */
    private function run($initial, $carry)
    {
        /** @var callable $callback */
        $callback = $this->pipeline->current();
        /** @var array $args */
        $args = $this->pipeline->getInfo();
        array_unshift($args, $this->DTOs->top());
        array_unshift($args, $initial);
        array_unshift($args, $carry);

        return $this->maybeCast(call_user_func_array($callback, $args));
    }

    /**
     * Uses "caster" callback to cast data to a specific type.
     *
     * @param  mixed $data
     * @return mixed
     * @uses \Toobo\PipePie\Pipeline::toArray()
     * @uses \Toobo\PipePie\Pipeline::toObject()
     * @uses \Toobo\PipePie\Pipeline::toString()
     * @uses \Toobo\PipePie\Pipeline::toInt()
     * @uses \Toobo\PipePie\Pipeline::toBool()
     * @uses \Toobo\PipePie\Pipeline::toFloat()
     */
    private function maybeCast($data)
    {
        return is_callable($this->caster) ? call_user_func($this->caster, $data) : $data;
    }

    /**
     * @param  mixed $data
     * @return array
     */
    private function toArray($data)
    {
        return (array) $data;
    }

    /**
     * @param  mixed  $data
     * @return object
     */
    private function toObject($data)
    {
        return (object) $data;
    }

    /**
     * @param  mixed  $data
     * @return string
     */
    private function toString($data)
    {
        return strval($data);
    }

    /**
     * @param  mixed $data
     * @return int
     */
    private function toInt($data)
    {
        return intval($data);
    }

    /**
     * @param  mixed $data
     * @return bool
     */
    private function toBool($data)
    {
        return ! empty($data);
    }

    /**
     * @param  mixed $data
     * @return float
     */
    private function toFloat($data)
    {
        return floatval($data);
    }
}
