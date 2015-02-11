<?php namespace Toobo\PipePie;

use ArrayAccess;
use InvalidArgumentException;
use RuntimeException;
use LogicException;

/**
 * @author  Giuseppe Mazzapica <giuseppe.mazzapica@gmail.com>
 * @package Toobo\PipePie
 * @license http://opensource.org/licenses/MIT MIT
 */
class DTO implements ArrayAccess
{
    /**
     * @var string
     */
    private $hash;

    /**
     * @var mixed
     */
    private $input;

    /**
     * @var float
     */
    private $start;

    /**
     * @var array
     */
    private $storage;

    /**
     * @var mixed
     */
    private $context;

    /**
     * @param \Toobo\PipePie\Pipeline $pipeline
     * @param mixed                   $for
     * @param mixed                   $context
     */
    public function __construct(Pipeline $pipeline, $for, $context)
    {
        $this->hash = spl_object_hash($pipeline);
        $this->input = $for;
        $this->context = $context;
        $this->start = microtime(true);
        $this->storage = [];
    }

    /**
     * @return mixed
     */
    public function context()
    {
        return $this->context;
    }

    /**
     * Used to ensure custom DTO is valid for calling Pipeline.
     *
     * @param  mixed $input
     * @return bool
     */
    public function acceptInput($input)
    {
        return $input === $this->input;
    }

    /**
     * @param  \Toobo\PipePie\Pipeline $pipeline
     * @return array
     */
    public function toArray(Pipeline $pipeline)
    {
        if (spl_object_hash($pipeline) !== $this->hash) {
            throw new RuntimeException('Only related Pipeline can get DTO state as array.');
        }

        return [
            'input'       => $this->input,
            'transported' => $this->storage,
            'started_at'  => $this->start
        ];
    }

    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->storage);
    }

    public function offsetGet($offset)
    {
        return $this->offsetExists($offset) ? $this->storage[$offset] : null;
    }

    public function offsetSet($offset, $value)
    {
        if (is_object($this[$offset])) {
            throw new InvalidArgumentException("Existent objects can't be overwritten.");
        }
        $this->storage[$offset] = $value;
    }

    public function offsetUnset($offset)
    {
        throw new LogicException("Existent data can't be deleted.");
    }
}
