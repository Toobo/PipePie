<?php namespace Toobo\PipePie\Tests;

use PHPUnit_Framework_TestCase;
use Toobo\PipePie\DTO;
use Toobo\PipePie\Pipeline;

class PipelineTest extends PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \LogicException
     */
    public function testPipeFailsIfWorking()
    {
        $pipeline = new Pipeline();
        $cb = function () use ($pipeline) {
            $pipeline->pipe(function () {
                return true;
            });
        };
        $pipeline->pipe($cb);
        $pipeline->applyTo('x');
    }

    /**
     * @expectedException \LogicException
     */
    public function testPipeFailsIfLocked()
    {
        $pipeline = new Pipeline();
        $pipeline->pipe(function () {
            return true;
        });
        $cb = function () use ($pipeline) {
            return true;
        };
        $pipeline->applyTo('x');
        $pipeline->pipe($cb);
    }

    public function testApplyDoNothingIfNoCallbacks()
    {
        $pipeline = new Pipeline();
        assertSame('foo', $pipeline->applyTo('foo'));
    }

    /**
     * @expectedException \LogicException
     */
    public function testApplyFailsIfWorking()
    {
        $pipeline = new Pipeline();
        $cb = function () use ($pipeline) {
            $pipeline->applyTo('x');
        };
        $pipeline->pipe($cb);
        $pipeline->applyTo('x');
    }

    public function testPipeAndApply()
    {
        $pipeline = new Pipeline();
        $pipeline->pipe(function ($carry) {
            return $carry.'X';
        });
        $pipeline->pipe(function ($carry) {
            return $carry.'Y';
        });
        assertSame('ZXY', $pipeline->applyTo('Z'));
    }

    public function testApplyCasterArray()
    {
        $pipeline = new Pipeline(null, Pipeline::TO_ARRAY);
        $pipeline->pipe(function ($carry) {
            $object = (object) $carry;
            $object->foo = 'foo';

            return $object;
        });
        $pipeline->pipe(function ($carry) {
            $object = (object) $carry;
            $object->bar = 'bar';

            return $object;
        });
        $ini = ['baz' => 'baz'];
        $expected = ['baz' => 'baz', 'foo' => 'foo', 'bar' => 'bar'];
        assertSame($expected, $pipeline->applyTo($ini));
    }

    public function testApplyCasterObject()
    {
        $pipeline = new Pipeline(null, Pipeline::TO_OBJECT);
        $pipeline->pipe(function ($carry) {
            return $carry;
        })->pipe(function (\stdClass $carry) {
            return $carry;
        });
        assertEquals((object) ['baz' => 'baz'], $pipeline->applyTo(['baz' => 'baz']));
    }

    public function testApplyCasterString()
    {
        $pipeline = (new Pipeline(null, Pipeline::TO_STRING))
            ->pipe(function ($carry) {
                return $carry;
            })->pipe(function ($carry) {
                return is_string($carry) ? 'OK' : 'NO';
            });
        assertSame('OK', $pipeline->applyTo(1));
    }

    public function testApplyCasterInt()
    {
        $pipeline = (new Pipeline(null, Pipeline::TO_INT))
            ->pipe(function ($carry) {
                return $carry;
            })->pipe(function ($carry) {
                return is_int($carry) ? $carry * 2 : 0;
            });
        assertSame(4, $pipeline->applyTo('2'));
    }

    public function testApplyCasterBool()
    {
        $pipeline = (new Pipeline(null, Pipeline::TO_BOOL))
            ->pipe(function ($carry) {
                return $carry;
            })->pipe(function ($carry) {
                return is_bool($carry) ? true : false;
            });
        assertSame(true, $pipeline->applyTo('1'));
    }

    public function testApplyCasterFloat()
    {
        $pipeline = (new Pipeline(null, Pipeline::TO_FLOAT))
            ->pipe(function ($carry) {
                return $carry;
            })->pipe(function ($carry) {
                return is_float($carry) ? 1.05 : 0.0;
            });
        assertSame(1.05, $pipeline->applyTo('1'));
    }

    public function testApplyCustomCaster()
    {
        $caster = function ($data) {
            if (! is_array($data)) {
                $data = (array) $data;
            }

            return array_values(array_unique(array_filter($data, 'is_int')));
        };
        $pipeline = new Pipeline(null, $caster);
        $pipeline->pipe(function (array $carry) {
            return array_merge($carry, ['a', 1, 'b', 2, false, 3]);
        })->pipe(function (array $carry) {
            return array_merge($carry, ['foo', 4, 'bar', 3, false, 2, 1]);
        });
        assertSame([0, 1, 2, 3, 4], $pipeline->applyTo(['foo' => 0, 'bar' => 'baz']));
    }

    public function testApplyCasterFirstItem()
    {
        $pipeline = new Pipeline(null, Pipeline::TO_OBJECT, true);
        $pipeline->pipe(function (\stdClass $carry) {
            return $carry;
        });
        assertEquals((object) ['baz' => 'baz'], $pipeline->applyTo(['baz' => 'baz']));
    }

    public function testApplyMoreArgs()
    {
        $pipeline = new Pipeline();
        $pipeline->pipe(function ($carry, $init, $dto, $foo, $bar) {
            return $carry.$init.$foo.$bar;
        }, ['foo ', 'bar']);
        assertSame('baz baz foo bar', $pipeline->applyTo('baz '));
    }

    public function testApplyCustomDTO()
    {
        $pipeline = new Pipeline();
        $dto = new DTO($pipeline, 'bar', null);
        $dto['foo'] = 'foo';
        $pipeline->pipe(function ($carry, $initial, DTO $dto) {
            if ($dto['foo'] === 'foo') {
                return $carry.' foo';
            }

            return $carry;
        });
        assertSame('bar foo', $pipeline->applyTo('bar', $dto));
    }

    /**
     * @expectedException \LogicException
     */
    public function testApplyCustomDTOFailsIfWrongInput()
    {
        $pipeline = new Pipeline();
        $pipeline->pipe(function () {
            return true;
        });
        $dto = new DTO($pipeline, 'foo', null); // DTO is initialized with 'foo'
        $pipeline->applyTo('bar', $dto); // bar is different from 'foo'
    }

    public function testApplyCustomCursor()
    {
        $pipeline = new Pipeline();
        $pipeline->pipe(function ($carry, $initial) {
            $carry[] = $initial;

            return $carry;
        });
        assertSame(['foo', 'bar', 'baz'], $pipeline->applyTo('baz', null, ['foo', 'bar']));
    }

    public function testNestedPipelines()
    {
        $inner_1 = (new Pipeline())
            ->pipe(function ($carry, $initial) {
                $carry[] = $initial;

                return $carry;
            });
        $inner_2 = (new Pipeline())
            ->pipe(function ($carry, $initial) {
                $carry[] = $initial;

                return $carry;
            });
        $outer = (new Pipeline())
            ->pipe($inner_1)
            ->pipe(function ($carry, $initial) {
                $carry[] = $initial;

                return $carry;
            })
            ->pipe($inner_2);

        $expected = ['x', ['x'], ['x'], ['x']];
        assertSame($expected, $outer->applyTo(['x']));
    }

    public function testDTOisSharedInNestedPipelines()
    {
        $cb = function ($carry, $initial, $dto) {
            return $carry.$dto['id'];
        };
        $pipeline = (new Pipeline())->pipe((new Pipeline())->pipe($cb))->pipe($cb);
        $dto = new DTO($pipeline, 'bar', null);
        $id = uniqid();
        $dto['id'] = $id;
        assertSame('bar'.$id.$id, $pipeline->applyTo('bar', $dto));
    }

    /**
     * @expectedException \LogicException
     */
    public function testInfoFailsIfWorking()
    {
        $pipeline = new Pipeline();
        $cb = function () use ($pipeline) {
            $pipeline->info();
        };
        $pipeline->pipe($cb);
        $pipeline->applyTo('x');
    }

    public function testInfo()
    {
        $pipeline = new Pipeline('I am the context');
        $pipeline->pipe(function ($carry, $initial, DTO $dto) {
            $dto['called'] = 1;

            return $carry.$initial.$dto->context();
        });
        assertSame('foo foo I am the context', $pipeline->applyTo('foo '));
        assertSame('bar bar I am the context', $pipeline->applyTo('bar '));
        $information = $pipeline->info();
        $assertion = function ($i, $info) {
            $input = $i === 0 ? 'bar ' : 'foo ';
            assertSame($input, $info['input']);
            assertSame(['called' => 1], $info['transported']);
            assertInternalType('float', $info['started_at']);
            assertLessThanOrEqual(microtime(true), $info['started_at']);
        };
        foreach ($information as $i => $info) {
            if ($i === 'context') {
                assertSame('I am the context', $info);
            } else {
                $assertion($i, $info);
            }
        }
    }
}
