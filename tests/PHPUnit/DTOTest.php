<?php namespace Toobo\PipePie\Tests;

use PHPUnit_Framework_TestCase;
use Toobo\PipePie\DTO;
use Toobo\PipePie\Pipeline;
use ArrayObject;

class DTOTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var \Toobo\PipePie\Pipeline
     */
    private $pipeline;

    private function getDTO()
    {
        $for = ['foo' => 'foo'];
        $context = ['context' => 'context'];
        $this->pipeline = new Pipeline();

        return new DTO($this->pipeline, $for, $context);
    }

    public function testInterface()
    {
        $dto = $this->getDTO();
        assertInstanceOf('ArrayAccess', $dto);
    }

    public function testToArray()
    {
        $dto = $this->getDTO();
        $dto['hello'] = 'World';
        $info = $dto->toArray($this->pipeline);
        assertSame(['foo' => 'foo'], $info['input']);
        assertSame(['hello' => 'World'], $info['transported']);
        assertSame(['context' => 'context'], $dto->context());
        assertInternalType('float', $info['started_at']);
        assertLessThanOrEqual(microtime(true), $info['started_at']);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testToArrayFailsIfWrongPipeline()
    {
        $dto = $this->getDTO();
        $dto->toArray(new Pipeline());
    }

    public function testGetSetExists()
    {
        $dto = $this->getDTO();
        $dto['bar'] = 'Bar';
        assertSame('Bar', $dto['bar']);
        assertNull($dto['foo']);
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testSetFailsIfOverwrite()
    {
        $dto = $this->getDTO();
        $dto['ao'] = new ArrayObject();
        $dto['ao'] = new ArrayObject();
    }

    /**
     * @expectedException \LogicException
     */
    public function testUnsetFails()
    {
        $dto = $this->getDTO();
        $dto['foo'] = 'Foo';
        unset($dto['foo']);
    }
}
