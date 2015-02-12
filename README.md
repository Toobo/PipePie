# PipePie

![travis-ci](https://travis-ci.org/Toobo/PipePie.svg?branch=master)

## Introduction

PipePie is a generic tool to apply a set of callbacks to some input data to obtain some output data.

```php
use Toobo\PipePie\Pipeline;

$pipeline = (new Pipeline())
    ->pipe(function($carry) {
       return $carry.'B';
    })->pipe(function($carry) {
       return $carry.'C';
    });
    
echo $pipeline->applyTo('A'); // 'ABC'
```

So:

 - **`Pipeline::pipe()`** is used to add callbacks to the pipeline
 - **`Pipeline::applyTo()`** is used to apply the callbacks pipeline to some data
 
Every callback in the pipeline receives as 1st argument the value returned by previous callback.
The 1st callback receives the input data.
 
Note that callbacks may be anything that evaluates as [`callable`](http://php.net/manual/en/language.types.callable.php) in PHP, so not only closures.

E.g. is possible to use *invokable objects* (i.e objects that have an `__invoke()` method) to improve code **reusability**.

## Initial Value Access

The second argument that callbacks in the pipeline receive, is always the initial value,
i.e. the value that was passed to `Pipeline::applyTo()`

```php
use Toobo\PipePie\Pipeline;

$pipeline = (new Pipeline())
    ->pipe(function($carry, $initial) {
       return $initial % 2 === 0 ? $carry * 2 : $carry;
    })->pipe(function($carry, $initial) {
       return $initial % 2 === 0 ? $carry * 2 : $carry;
    });
    
echo $pipeline->applyTo(2); // 8
echo $pipeline->applyTo(1); // 1
```

In this way, every callback in the pipeline can do its work with being aware of the initial value.

## DTO

DTO stands for ["Data Transportation Object"](http://en.wikipedia.org/wiki/Data_transfer_object).

PipePie uses this kind of object to make possible to pass data across callbacks.

In fact, 3rd argument that callbacks receive is an instance of `Toobo\PipePie\DTO` class that is a very simple object
that implements [`ArrayAccess` interface](http://php.net/manual/en/class.arrayaccess.php).

Once this object instance is the same for all the callbacks in the pipeline,
is possible to use it to pass data from a callback to next ones.

```php
use Toobo\PipePie\Pipeline;
use Toobo\PipePie\DTO;

$pipeline = (new Pipeline())
    ->pipe(function($carry, $initial, DTO $dto) {
       $dto['ai'] = new ArrayIterator();
       $dto['ai']->append('bar');
    })->pipe(function($carry, $initial, DTO $dto) {
       $dto['ai']->append('baz');
    })->pipe(function($carry, $initial, DTO $dto) {
       return $carry.implode(',' $dto['ai']->getArrayCopy());
    });
    
echo $pipeline->applyTo('foo,'); // 'foo,bar,baz'
```
For the sake of data integrity, DTO implements `ArrayAccess` in a way that:

 - it's **not** possible inside a callback to unset a DTO value that has been set by a previous callback
 - it's **not** possible inside a callback to override a DTO value that has been set by a previous callback, if that value is an object

## Context

As shown above, DTO is a good way to pass data from a callback to others.

Sometimes may be desirable to pass some *context* to **all** the callbacks, at Pipeline level.

That can be done by passing context data as 1st argument to Pipeline constructor.
After that, context is reachable inside callbacks via `DTO::context()` method,
called on the DTO instance that is passed to callbacks.

```php
use Toobo\PipePie\Pipeline;
use Toobo\PipePie\DTO;

// Context here is a string, but can be anything
$pipeline = (new Pipeline('I am the context '))
    ->pipe(function($carry, $initial, DTO $dto) {
       return $carry.$dto->context();
    })->pipe(function($carry, $initial, DTO $dto) {
       return $carry.$dto->context();
    });
    
echo $pipeline->applyTo('foo '); // 'foo I am the context I am the context'
```

## DTO "freshness"

Every time `Pipeline::applyTo()` is called on same Pipeline instance, DTO instance passed to callbacks
is a *fresh* one, i.e. DTO state is not maintained across `applyTo()` calls.

Only `DTO::context()` returns the same value because it is set at Pipeline level.

```php
use Toobo\PipePie\Pipeline;
use Toobo\PipePie\DTO;

$pipeline = (new Pipeline('Call: '))
    ->pipe(function($carry, $initial, DTO $dto) {
       $dto['random'] = rand(1, 100);
       return $carry;
    })->pipe(function($carry, $initial, DTO $dto) {
       return $carry.$dto->context().$dto['random'];
    });
    
echo $pipeline->applyTo('First '); // 'First Call: 5'
echo $pipeline->applyTo('Second '); // 'Second Call: 72'
```

## Auto Type Casting

May be desirable to be sure that every callback in the pipeline returns a specific data type.
PipePie allows to do that in 2 ways:

 - via Pipeline flags
 - via custom callback
 
### Type Casting via Flag

Pipeline class has some constants:

 - `Pipeline::TO_ARRAY`
 - `Pipeline::TO_OBJECT`
 - `Pipeline::TO_STRING`
 - `Pipeline::TO_INT`
 - `Pipeline::TO_BOOL`
 - `Pipeline::TO_FLOAT`
 
Passing one of them as 2nd argument to Pipeline constructor is possible be sure that the type returned
by **all** the callbacks is the desired one.

```php
use Toobo\PipePie\Pipeline;

$pipeline = (new Pipeline(null, Pipeline::TO_ARRAY))
    ->pipe(function ($carry) {
        return $carry;
    })->pipe(function (array $carry) { // type casting flag ensures $carry is an array
        $carry[] = 'bar';
        return $carry;
    });

return $pipeline->applyTo('foo'); // ['foo', 'bar']
```

Note that, by default, initial value *passed to* 1st callback, is **not** type casted (but its *returning value* is).

However, it's also possible to type cast even the initial value before it is passed to first callback.
That can be done by passing `true` as 3rd argument to Pipeline constructor.

```php
use Toobo\PipePie\Pipeline;

$pipeline = (new Pipeline(null, Pipeline::TO_ARRAY, true))
    ->pipe(function (array $carry) {  // Even 1st callback receives type-casted value
        return $carry;
    });

return $pipeline->applyTo('foo'); // ['foo']
```


### Type Casting via Custom Callback

Is possible to pass as 2nd argument to Pipeline constructor a callback that can be used to type cast 
the value returned by all the callbacks in the Pipeline.

```php
use Toobo\PipePie\Pipeline;

/**
 * Ensures returned value is an array of unique integers
 */
$caster = function ($data) { 
    if (! is_array($data)) {
        $data = (array) $data;
    }
    return array_values(array_unique(array_filter($data, 'is_int')));
};

$pipeline = (new Pipeline(null, $caster))
    ->pipe(function (array $carry) {
        return array_merge($carry, ['a', 1, 'b', 2, false, 3]);
    })->pipe(function (array $carry) {
        return array_merge($carry, ['foo', 4, 'bar', 3, false, 2, 1]);
    });

return $pipeline->applyTo(['foo' => 0, 'bar' => 'baz'])); // [0, 1, 2, 3, 4]
```

Even if main scope of type-casting custom callback is, in fact, type-cast Pipeline callbacks result,
it can be actually used to do anything.

## Callback Additional Arguments

In the examples above is shown how Pipeline callbacks receive *at least* 3 arguments:

 - 1st argument will be the result of previous callback in the pipeline (or initial value for 1st callback);
 - 2nd argument will always be the initial data;
 - 3rd argument will be a DTO instance.
 
It is possible to pass additional arguments on a callback basis.

That is done by passing an array of arguments as 2nd argument for `Pipeline::pipe()` method:

```php
use Toobo\PipePie\Pipeline;

$pipeline = (new Pipeline())->pipe(
    function ($carry, $initial, $dto, $foo, $bar) { // 1st arg is the callback
        return $carry.$foo.', '.$bar;
    },
    ['"foo"', '"bar"']            // 2nd arg is the additional arguments array
);

echo $pipeline->applyTo('Args: '); // 'Args: "foo", "bar"'
```

## Nested Pipelines

If you like the fact that invokable objects can be used as callback to promote code reusability, you'll love
the fact that `Pipeline` class implements an `__invoke()` method, that allows to use
**any Pipeline as a callback to be added to a "parent" Pipeline**.

```php
use Toobo\PipePie\Pipeline;

$child1 = (new Pipeline())->pipe(function ($carry) {
        return $carry.'Inner 1/1, ';
    })->pipe(function ($carry) {
        return $carry.'Inner 1/2, ';
    });
    
$child2 = (new Pipeline())->pipe(function ($carry) {
        return $carry.'Inner 2/1, ';
    })->pipe(function ($carry) {
        return $carry.'Inner 2/2.';
    });
    
$parent = (new Pipeline())
    ->pipe($child1)
    ->pipe(function ($carry) {
        return $carry.'Parent, ';
    });
    ->pipe($child2);

echo $pipeline->applyTo('Called: ');
// 'Called: Inner 1/1, Inner 1/2, Parent, Inner 2/1, Inner 2/2.'
```

Note that when using nested pipelines, just like in code above, DTO is shared among pipelines,
i.e. **DTO instance is the same** for callbacks in *parent* pipeline and callbacks in *child* pipelines.

## Debug Aids

Pipeline class has a method: `info()` that gives information on:

- the context of the Pipeline (whatever was passed as 1st argument to constructor)
- for every `applyTo()` call on the Pipeline instance, the method returns an array with:
   - key 'started_at': contains the Unix timestamp with microseconds (obtained via [`microtime(true)`](http://php.net/manual/en/function.microtime.php)) at the moment `applyTo()` was called
   - key 'transported': array representation of the whole content of the DTO object related to the `applyTo()` call
   - key: 'input' the initial value that was passed to `applyTo()`
   
```php
use Toobo\PipePie\Pipeline;

$pipeline = (new Pipeline('I am the context'))
    ->pipe(function ($carry, $initial, $dto) {
        $dto['callback'] = 'First Callback';
        $dto['random'] = rand(1, 100);
        return $carry;
    });

$pipeline->applyTo('One');
$pipeline->applyTo('Two');
$pipeline->applyTo('Three');

return $pipeline->info();
/*
[
    'context' => 'I am the context'
    [
        'input'       => 'Three',
        'transported' => [
            'callback' => 'First Callback',
            'random'   => 72
        ],
        'started_at' => 1423689928.5802
    ],
    [
        'input'       => 'Two',
        'transported' => [
            'callback' => 'First Callback',
            'random'   => 21
        ],
        'started_at' => 1423689928.5801
    ],
    [
        'input'       => 'One',
        'transported' => [
            'callback' => 'First Callback',
            'random'   => 89
        ],
        'started_at' => 1423689928.5800
    ],
)
*/
```

Note that:

 - information on various `applyTo()` calls, are shown in inverse chronological order: later calls are shown first;
 - an `info()` call *flushes* information, so on subsequent calls the method returns only data for `applyTo()` calls
 happened since last `info()` call.

## Requirements

- PHP 5.4+
- Composer to install

## Installation

- PipePie is a Composer package available on Packagist and can be installed by running

```
composer require toobo/pipepie:~0.1
```

## Unit Tests

PipePie repository contains some unit tests written for [PHPUnit](https://phpunit.de/).

To run tests, navigate to repo folder from console and run:

```shell
phpunit
```

## License

PipePie is released under MIT, see LICENSE file for more info.
