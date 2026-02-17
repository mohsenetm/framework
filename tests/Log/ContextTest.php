<?php

namespace Illuminate\Tests\Log;

use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Log\ContextLogProcessor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Log\Context\Events\ContextDehydrating as Dehydrating;
use Illuminate\Log\Context\Events\ContextHydrated as Hydrated;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\LogRecord;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class ContextTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[\Override]
    protected function tearDown(): void
    {
        MyAddContextProcessor::$wasConstructed = false;

        parent::tearDown();
    }

    public function testItCanSetValues()
    {
        $values = [
            'string' => 'string',
            'bool' => false,
            'int' => 5,
            'float' => 5.5,
            'null' => null,
            'array' => [1, 2, 3],
            'hash' => ['foo' => 'bar'],
            'object' => (object) ['foo' => 'bar'],
            'enum' => Suit::Clubs,
            'backed_enum' => StringBackedSuit::Clubs,
        ];

        foreach ($values as $type => $value) {
            Context::add($type, $value);
        }

        foreach ($values as $type => $value) {
            $this->assertSame($value, Context::get($type));
        }
    }

    public function testItCanAddValuesWhenNotAlreadyPresent()
    {
        Context::addIf('foo', 1);
        $this->assertSame(1, Context::get('foo'));

        Context::addIf('foo', 2);
        $this->assertSame(1, Context::get('foo'));
    }

    public function testItCanListenToTheHydratingEvent()
    {
        Context::add('one', 1);
        Context::add('two', 2);
        Context::hydrated(function (Repository $context) {
            Context::add('two', 99);
            Context::add('three', 3);
        });
        Event::dispatch(new Hydrated(Context::getFacadeRoot()));

        $this->assertSame(1, Context::get('one'));
        $this->assertSame(99, Context::get('two'));
        $this->assertSame(3, Context::get('three'));
    }

    public function testItCanListenToTheDehydratedEvent()
    {
        Context::add('one', 1);
        Context::add('two', 2);
        Context::dehydrating(function (Repository $context) {
            Context::add('two', 99);
            Context::add('three', 3);
        });
        Event::dispatch(new Dehydrating(Context::getFacadeRoot()));

        $this->assertSame(1, Context::get('one'));
        $this->assertSame(99, Context::get('two'));
        $this->assertSame(3, Context::get('three'));
    }

    public function testItCanModifyContextWhileDehydratingWithoutImpactingGlobalInstance()
    {
        Context::add('one', 1);
        Context::dehydrating(function (Repository $context) {
            $context->add('one', 99);
        });

        $dehydrated = Context::dehydrate();
        $this->assertSame(1, Context::get('one'));

        Context::hydrate($dehydrated);
        $this->assertSame(99, Context::get('one'));
    }

    public function testDehydrateReturnsNullWhenEmpty()
    {
        $this->assertNull(Context::dehydrate());
    }

    public function testHydratingNullTriggersHydratingEvent()
    {
        $called = false;
        Context::hydrated(function () use (&$called) {
            $called = true;
        });

        Context::hydrate(null);

        $this->assertTrue($called);
    }

    public function testItCanSerializeValues()
    {
        Context::add([
            'string' => 'string',
            'bool' => false,
            'int' => 5,
            'float' => 5.5,
            'null' => null,
            'array' => [1, 2, 3],
            'hash' => ['foo' => 'bar'],
            'object' => (object) ['foo' => 'bar'],
            'enum' => Suit::Clubs,
            'backed_enum' => StringBackedSuit::Clubs,
        ]);
        Context::addHidden('number', 55);

        $dehydrated = Context::dehydrate();

        $this->assertSame([
            'data' => [
                'string' => 's:6:"string";',
                'bool' => 'b:0;',
                'int' => 'i:5;',
                'float' => 'd:5.5;',
                'null' => 'N;',
                'array' => 'a:3:{i:0;i:1;i:1;i:2;i:2;i:3;}',
                'hash' => 'a:1:{s:3:"foo";s:3:"bar";}',
                'object' => 'O:8:"stdClass":1:{s:3:"foo";s:3:"bar";}',
                'enum' => 'E:31:"Illuminate\Tests\Log\Suit:Clubs";',
                'backed_enum' => 'E:43:"Illuminate\Tests\Log\StringBackedSuit:Clubs";',
            ],
            'hidden' => [
                'number' => 'i:55;',
            ],
        ], $dehydrated);

        Context::flush();
        $this->assertNull(Context::get('string'));

        Context::hydrate($dehydrated);

        $this->assertSame(Context::get('string'), 'string');
        $this->assertSame(Context::get('bool'), false);
        $this->assertSame(Context::get('int'), 5);
        $this->assertSame(Context::get('float'), 5.5);
        $this->assertSame(Context::get('null'), null);
        $this->assertSame(Context::get('array'), [1, 2, 3]);
        $this->assertSame(Context::get('hash'), ['foo' => 'bar']);
        $this->assertEquals(Context::get('object'), (object) ['foo' => 'bar']);
        $this->assertSame(Context::get('enum'), Suit::Clubs);
        $this->assertSame(Context::get('backed_enum'), StringBackedSuit::Clubs);
        $this->assertSame(Context::getHidden('number'), 55);
    }

    public function testItCanPushToList()
    {
        Context::push('breadcrumbs', 'foo');
        Context::push('breadcrumbs', 'bar');
        Context::push('breadcrumbs', 'baz', 'qux');

        $this->assertSame(['foo', 'bar', 'baz', 'qux'], Context::get('breadcrumbs'));
    }

    public function testThrowsWhenPushingToNonArray()
    {
        Context::add('breadcrumbs', 'foo');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to push value onto context stack for key [breadcrumbs].');
        Context::push('breadcrumbs', 'bar');
    }

    public function testThrowsWhenPushingToNonListArray()
    {
        Context::add('breadcrumbs', ['foo' => 'bar']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to push value onto context stack for key [breadcrumbs].');
        Context::push('breadcrumbs', 'bar');
    }

    public function testItCanPopFromList()
    {
        Context::push('breadcrumbs', 'foo', 'bar');

        $this->assertSame('bar', Context::pop('breadcrumbs'));
        $this->assertSame('foo', Context::pop('breadcrumbs'));
        $this->assertSame([], Context::get('breadcrumbs'));
    }

    public function testThrowsWhenPoppingFromEmptyList()
    {
        Context::push('breadcrumbs', 'bar');
        Context::pop('breadcrumbs');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to pop value from context stack for key [breadcrumbs].');

        Context::pop('breadcrumbs');
    }

    public function testThrowsWhenPoppingFromNonListArray()
    {
        Context::add('breadcrumbs', ['foo' => 'bar']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to pop value from context stack for key [breadcrumbs].');
        Context::pop('breadcrumbs');
    }

    public function testItCanPopFromHiddenList()
    {
        Context::pushHidden('breadcrumbs', 'foo', 'bar');

        $this->assertSame('bar', Context::popHidden('breadcrumbs'));
        $this->assertSame('foo', Context::popHidden('breadcrumbs'));
        $this->assertSame([], Context::getHidden('breadcrumbs'));
    }

    public function testThrowsWhenPoppingFromEmptyHiddenList()
    {
        Context::pushHidden('breadcrumbs', 'bar');
        Context::popHidden('breadcrumbs');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to pop value from hidden context stack for key [breadcrumbs].');

        Context::popHidden('breadcrumbs');
    }

    public function testThrowsWhenPoppingFromHiddenNonListArray()
    {
        Context::addHidden('breadcrumbs', ['foo' => 'bar']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to pop value from hidden context stack for key [breadcrumbs].');
        Context::popHidden('breadcrumbs');
    }

    public function testItCanCheckIfContextHasBeenSet()
    {
        Context::add('foo', 'bar');
        Context::add('null', null);

        $this->assertTrue(Context::has('foo'));
        $this->assertTrue(Context::has('null'));
        $this->assertFalse(Context::has('unset'));
    }

    public function testItCanCheckIfContextIsMissing()
    {
        Context::add('foo', 'bar');

        $this->assertTrue(Context::missing('lorem'));
        $this->assertFalse(Context::missing('foo'));
    }

    public function testItCanCheckIfValueIsInContextStack()
    {
        Context::push('foo', 'bar', 'lorem');

        $this->assertTrue(Context::stackContains('foo', 'bar'));
        $this->assertTrue(Context::stackContains('foo', 'lorem'));
        $this->assertFalse(Context::stackContains('foo', 'doesNotExist'));
    }

    public function testItCanCheckIfValueIsInContextStackWithClosures()
    {
        Context::push('foo', 'bar', ['lorem'], 123);
        Context::pushHidden('baz');

        $this->assertTrue(Context::stackContains('foo', fn ($value) => $value === 'bar'));
        $this->assertFalse(Context::stackContains('foo', fn ($value) => $value === 'baz'));
    }

    public function testItCanCheckIfValueIsInHiddenContextStack()
    {
        Context::pushHidden('foo', 'bar', 'lorem');

        $this->assertTrue(Context::hiddenStackContains('foo', 'bar'));
        $this->assertTrue(Context::hiddenStackContains('foo', 'lorem'));
        $this->assertFalse(Context::hiddenStackContains('foo', 'doesNotExist'));
    }

    public function testItCanCheckIfValueIsInHiddenContextStackWithClosures()
    {
        Context::pushHidden('foo', 'baz');
        Context::push('foo', 'bar', ['lorem'], 123);

        $this->assertTrue(Context::hiddenStackContains('foo', fn ($value) => $value === 'baz'));
        $this->assertFalse(Context::hiddenStackContains('foo', fn ($value) => $value === 'bar'));
    }

    public function testItCannotCheckIfHiddenValueIsInNonHiddenContextStack()
    {
        Context::pushHidden('foo', 'bar', 'lorem');

        $this->assertFalse(Context::stackContains('foo', 'bar'));
    }

    public function testItCanGetAllValues()
    {
        Context::add('foo', 'bar');
        Context::add('null', null);

        $this->assertSame([
            'foo' => 'bar',
            'null' => null,
        ], Context::all());
    }

    public function testItSilentlyIgnoresUnsetValues()
    {
        $this->assertNull(Context::get('foo'));
        $this->assertFalse(Context::has('foo'));
        $this->assertSame([], Context::all());
    }

    public function testItIsSimpleKeyValueSystem()
    {
        Context::add('parent.child', 5);

        $this->assertNull(Context::get('parent'));
        $this->assertSame(5, Context::get('parent.child'));
    }

    public function testItCanRetrieveSubsetOfContext()
    {
        Context::add('parent.child.1', 5);
        Context::add('parent.child.2', 6);
        Context::add('another', 7);

        $this->assertSame([
            'parent.child.1' => 5,
            'parent.child.2' => 6,
        ], Context::only([
            'parent.child.1',
            'parent.child.2',
        ]));
    }

    public function testItCanExcludeSubsetOfContext()
    {
        Context::add('parent.child.1', 5);
        Context::add('parent.child.2', 6);
        Context::add('another', 7);

        $this->assertSame([
            'another' => 7,
        ], Context::except([
            'parent.child.1',
            'parent.child.2',
        ]));
    }

    public function testItCanExcludeSubsetOfHiddenContext()
    {
        Context::addHidden('parent.child.1', 5);
        Context::addHidden('parent.child.2', 6);
        Context::addHidden('another', 7);

        $this->assertSame([
            'another' => 7,
        ], Context::exceptHidden([
            'parent.child.1',
            'parent.child.2',
        ]));
    }

    public function testItAddsContextToLogging()
    {
        $path = storage_path('logs/laravel.log');
        file_put_contents($path, '');
        Str::createUuidsUsingSequence(['expected-trace-id']);

        Context::add('trace_id', Str::uuid());
        Context::add('foo.bar', 123);
        Context::push('bar.baz', 456);
        Context::push('bar.baz', 789);

        Log::channel('single')->info('My name is {name}', [
            'name' => 'Tim',
            'framework' => 'Laravel',
        ]);
        $log = Str::after(file_get_contents(storage_path('logs/laravel.log')), '] ');

        $this->assertSame('testing.INFO: My name is Tim {"name":"Tim","framework":"Laravel"} {"trace_id":"expected-trace-id","foo.bar":123,"bar.baz":[456,789]}', trim($log));

        file_put_contents($path, '');
        Str::createUuidsNormally();
    }

    public function testItDoesntOverrideLogInstanceContext()
    {
        $path = storage_path('logs/laravel.log');
        file_put_contents($path, '');
        Str::createUuidsUsingSequence(['expected-trace-id']);

        Context::add('name', 'James');

        Log::channel('single')->info('My name is {name}', [
            'name' => 'Tim',
        ]);
        $log = Str::after(file_get_contents($path), '] ');

        $this->assertSame('testing.INFO: My name is Tim {"name":"Tim"} {"name":"James"}', trim($log));

        file_put_contents($path, '');
        Str::createUuidsNormally();
    }

    public function testItDoesntAllowContextToBeUsedAsParameters()
    {
        $path = storage_path('logs/laravel.log');
        file_put_contents($path, '');
        Str::createUuidsUsingSequence(['expected-trace-id']);

        Context::add('name', 'James');

        Log::channel('single')->info('My name is {name}');
        $log = Str::after(file_get_contents($path), '] ');

        $this->assertSame('testing.INFO: My name is {name}  {"name":"James"}', trim($log));

        file_put_contents($path, '');
        Str::createUuidsNormally();
    }

    public function testDoesNotAddHiddenContextToLogging()
    {
        $path = storage_path('logs/laravel.log');
        file_put_contents($path, '');
        Str::createUuidsUsingSequence(['expected-trace-id']);

        Context::addHidden('hidden_data', 'hidden_data');

        Log::channel('single')->info('My name is {name}', [
            'name' => 'Tim',
            'framework' => 'Laravel',
        ]);
        $log = Str::after(file_get_contents($path), '] ');

        $this->assertStringNotContainsString('hidden_data', trim($log));

        file_put_contents($path, '');
        Str::createUuidsNormally();
    }

    public function testItCanAddHidden()
    {
        Context::addHidden('foo', 'data');

        $this->assertFalse(Context::has('foo'));
        $this->assertTrue(Context::hasHidden('foo'));
        $this->assertNull(Context::get('foo'));
        $this->assertSame('data', Context::getHidden('foo'));
        $this->assertSame(['foo' => 'data'], Context::onlyHidden(['foo']));

        Context::forgetHidden('foo');

        $this->assertFalse(Context::has('foo'));
        $this->assertFalse(Context::hasHidden('foo'));
        $this->assertNull(Context::get('foo'));
        $this->assertNull(Context::getHidden('foo'));

        Context::pushHidden('foo', 1);
        Context::pushHidden('foo', 2);
        $this->assertSame([1, 2], Context::getHidden('foo'));

        Context::addHidden('foo', 'bar');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to push value onto hidden context stack for key [foo].');
        Context::pushHidden('foo', 2);
    }

    public function testItCanPull()
    {
        Context::add('foo', 'data');

        $this->assertSame('data', Context::pull('foo'));
        $this->assertNull(Context::get('foo'));

        Context::addHidden('foo', 'data');

        $this->assertSame('data', Context::pullHidden('foo'));
        $this->assertNull(Context::getHidden('foo'));
    }

    public function testItAddsContextToLoggedExceptions()
    {
        $path = storage_path('logs/laravel.log');
        file_put_contents($path, '');
        Str::createUuidsUsingSequence(['expected-trace-id']);

        Context::add('trace_id', Str::uuid());
        Context::add('foo.bar', 123);
        Context::push('bar.baz', 456);
        Context::push('bar.baz', 789);

        $this->app[ExceptionHandler::class]->report(new Exception('Whoops!'));
        $log = Str::after(file_get_contents($path), '] ');

        $this->assertStringEndsWith(' {"trace_id":"expected-trace-id","foo.bar":123,"bar.baz":[456,789]}', trim($log));

        file_put_contents($path, '');
        Str::createUuidsNormally();
    }

    public function testScopeSetsKeysAndRestores()
    {
        $contextInClosure = [];
        $callback = function () use (&$contextInClosure) {
            $contextInClosure = ['data' => Context::all(), 'hidden' => Context::allHidden()];

            throw new Exception('test_with_sets_keys_and_restores');
        };

        Context::add('key1', 'value1');
        Context::add('key2', 123);
        Context::addHidden([
            'hiddenKey1' => 'hello',
            'hiddenKey2' => 'world',
        ]);

        try {
            Context::scope(
                $callback,
                ['key1' => 'with', 'key3' => 'also-with'],
                ['hiddenKey3' => 'foobar'],
            );

            $this->fail('No exception was thrown.');
        } catch (Exception) {
        }

        $this->assertEqualsCanonicalizing([
            'data' => [
                'key1' => 'with',
                'key2' => 123,
                'key3' => 'also-with',
            ],
            'hidden' => [
                'hiddenKey1' => 'hello',
                'hiddenKey2' => 'world',
                'hiddenKey3' => 'foobar',
            ],
        ], $contextInClosure);

        $this->assertEqualsCanonicalizing([
            'key1' => 'value1',
            'key2' => 123,
        ], Context::all());
        $this->assertEqualsCanonicalizing([
            'hiddenKey1' => 'hello',
            'hiddenKey2' => 'world',
        ], Context::allHidden());
    }

    public function testUsesClosureForContextProcessor()
    {
        $path = storage_path('logs/laravel.log');
        file_put_contents($path, '');

        $this->app->bind(
            ContextLogProcessor::class,
            fn () => function (LogRecord $record): LogRecord {
                $logChannel = Context::getHidden('log_channel_name');

                return $record->with(
                    // allow overriding the context from what's been set on the log
                    context: array_merge(Context::all(), $record->context),
                    // use the log channel we've set in context, or fallback to the current channel
                    channel: $logChannel ?? $record->channel,
                );
            }
        );

        Context::addHidden('log_channel_name', 'closure-test');
        Context::add(['value_from_context' => 'hello']);

        Log::info('This is an info log.', ['value_from_log_info_context' => 'foo']);

        $log = Str::after(file_get_contents($path), '] ');
        $this->assertSame('closure-test.INFO: This is an info log. {"value_from_context":"hello","value_from_log_info_context":"foo"}', Str::trim($log));
        file_put_contents($path, '');
    }

    public function testCanRebindToSeparateClass()
    {
        $path = storage_path('logs/laravel.log');
        file_put_contents($path, '');

        $this->app->bind(ContextLogProcessor::class, MyAddContextProcessor::class);

        Context::add(['this-will-be-included' => false]);

        Log::info('This is an info log.', ['value_from_log_info_context' => 'foo']);
        $log = Str::after(file_get_contents($path), '] ');
        $this->assertSame(
            'testing.INFO: This is an info log. {"value_from_log_info_context":"foo","inside of MyAddContextProcessor":true}',
            Str::trim($log)
        );
        $this->assertTrue(MyAddContextProcessor::$wasConstructed);

        file_put_contents($path, '');
    }

    public function testItIncrementsACounter()
    {
        Context::increment('foo');
        $this->assertSame(1, Context::get('foo'));

        Context::increment('foo');
        $this->assertSame(2, Context::get('foo'));
    }

    public function testItCustomIncrementsACounter()
    {
        Context::increment('foo', 2);
        $this->assertSame(2, Context::get('foo'));

        Context::increment('foo', 3);
        $this->assertSame(5, Context::get('foo'));
    }

    public function testItDecrementsACounter()
    {
        Context::increment('foo');
        Context::decrement('foo');
        $this->assertSame(0, Context::get('foo'));
    }

    public function testItCustomDecrementsACounter()
    {
        Context::increment('foo', 2);
        Context::decrement('foo', 2);
        $this->assertSame(0, Context::get('foo'));
    }

    public function testItRemembersAValue()
    {
        $this->assertSame(1, Context::remember('int', 1));

        $closureRunCount = 0;
        $closure = function () use (&$closureRunCount) {
            $closureRunCount++;

            return 'bar';
        };

        $this->assertSame('bar', Context::remember('foo', $closure));
        $this->assertSame('bar', Context::get('foo'));

        Context::remember('foo', $closure);
        $this->assertSame(1, $closureRunCount);
    }

    public function testItRemembersAHiddenValue()
    {
        $this->assertSame(1, Context::rememberHidden('int', 1));

        $closureRunCount = 0;
        $closure = function () use (&$closureRunCount) {
            $closureRunCount++;

            return 'bar';
        };

        $this->assertSame('bar', Context::rememberHidden('foo', $closure));
        $this->assertSame('bar', Context::getHidden('foo'));

        Context::rememberHidden('foo', $closure);
        $this->assertSame(1, $closureRunCount);
    }
}

enum Suit
{
    case Hearts;
    case Diamonds;
    case Clubs;
    case Spades;
}

enum StringBackedSuit: string
{
    case Hearts = 'hearts';
    case Diamonds = 'diamonds';
    case Clubs = 'clubs';
    case Spades = 'spades';
}

class ContextModel extends Model
{
    //
}

class MyAddContextProcessor implements ContextLogProcessor
{
    public static bool $wasConstructed = false;

    public function __construct()
    {
        self::$wasConstructed = true;
    }

    #[\Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: array_merge($record->context, ['inside of MyAddContextProcessor' => true]));
    }
}
