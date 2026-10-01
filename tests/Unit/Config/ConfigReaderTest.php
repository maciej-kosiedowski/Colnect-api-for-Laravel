<?php

declare(strict_types=1);

namespace Slimad\ColnectApi\Laravel\Tests\Unit\Config;

use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use Slimad\ColnectApi\Laravel\Config\ConfigReader;
use stdClass;

final class ConfigReaderTest extends TestCase
{
    public function test_strings_are_trimmed(): void
    {
        self::assertSame('abc def', $this->reader('  abc def  ')->string('value'));
    }

    public function test_numbers_are_read_as_strings(): void
    {
        self::assertSame('42', $this->reader(42)->string('value'));
        self::assertSame('1.5', $this->reader(1.5)->string('value'));
    }

    public function test_a_zero_is_a_value_not_a_blank(): void
    {
        self::assertSame('0', $this->reader('0')->string('value'));
        self::assertSame('0', $this->reader(0)->string('value'));
    }

    public function test_blank_and_unusable_strings_are_null(): void
    {
        self::assertNull($this->reader(null)->string('value'));
        self::assertNull($this->reader('')->string('value'));
        self::assertNull($this->reader(" \t\n")->string('value'));
        self::assertNull($this->reader(true)->string('value'));
        self::assertNull($this->reader(['a'])->string('value'));
        self::assertNull($this->reader(new stdClass)->string('value'));
        self::assertNull((new ConfigReader(new Repository([])))->string('missing'));
    }

    public function test_integers_are_read_as_they_are(): void
    {
        self::assertSame(30, $this->reader(30)->integer('value'));
        self::assertSame(-5, $this->reader(-5)->integer('value'));
        self::assertSame(0, $this->reader(0)->integer('value'));
    }

    public function test_numeric_strings_from_env_become_integers(): void
    {
        self::assertSame(30, $this->reader('30')->integer('value'));
        self::assertSame(30, $this->reader(' 30 ')->integer('value'));
        self::assertSame(-5, $this->reader('-5')->integer('value'));
        self::assertSame(0, $this->reader('0')->integer('value'));
    }

    public function test_anything_else_is_not_an_integer(): void
    {
        self::assertNull($this->reader(null)->integer('value'));
        self::assertNull($this->reader('')->integer('value'));
        self::assertNull($this->reader('thirty')->integer('value'));
        self::assertNull($this->reader('30s')->integer('value'));
        self::assertNull($this->reader("30\n1")->integer('value'));
        self::assertNull($this->reader('1.5')->integer('value'));
        self::assertNull($this->reader(1.5)->integer('value'));
        self::assertNull($this->reader(true)->integer('value'));
        self::assertNull($this->reader([30])->integer('value'));
        self::assertNull($this->reader('99999999999999999999')->integer('value'));
    }

    public function test_booleans_are_read_as_they_are(): void
    {
        self::assertTrue($this->reader(true)->boolean('value'));
        self::assertFalse($this->reader(false)->boolean('value'));
    }

    public function test_boolean_strings_and_integers_are_understood(): void
    {
        foreach (['true', 'on', 'yes', '1', 1] as $truthy) {
            self::assertTrue($this->reader($truthy)->boolean('value'), var_export($truthy, true));
        }

        foreach (['false', 'off', 'no', '0', 0] as $falsy) {
            self::assertFalse($this->reader($falsy)->boolean('value'), var_export($falsy, true));
        }
    }

    public function test_anything_else_is_not_a_boolean(): void
    {
        self::assertNull($this->reader(null)->boolean('value'));
        self::assertNull($this->reader('maybe')->boolean('value'));
        self::assertNull($this->reader(2)->boolean('value'));
        self::assertNull($this->reader(1.0)->boolean('value'));
        self::assertNull($this->reader([true])->boolean('value'));
    }

    private function reader(mixed $value): ConfigReader
    {
        return new ConfigReader(new Repository(['value' => $value]));
    }
}
