<?php

declare(strict_types=1);

namespace Tests;

use Nandan108\SlotFlow\Inventory;
use Nandan108\SlotFlow\MovementEdge;
use Nandan108\SlotFlow\MovementEngine;
use Nandan108\SlotFlow\MovementPath;
use Nandan108\SlotFlow\SlotSpace;
use PHPUnit\Framework\TestCase;

final class MovementEngineTest extends TestCase
{
    public function testItTreatsNilSourceAsAnUnboundedInput(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo'],
            'state' => ['fs'],
        ]);

        $fooFs = $space->slot('foo.fs');
        $nil = $space->nilSlot();
        $inventory = new Inventory([[$fooFs, 2]]);

        $result = (new MovementEngine())->execute(
            $inventory,
            new MovementPath(new MovementEdge($nil, $fooFs)),
            3,
        );

        self::assertSame(0, $result->remaining());
        self::assertCount(1, $result->events());
        self::assertSame(5, $inventory->get($fooFs));
        self::assertSame(0, $inventory->get($nil));
        self::assertNull($result->events()[0]->initialFrom());
    }

    public function testItTreatsNilSinkAsAnOpenEndedOutput(): void
    {
        $space = SlotSpace::define([
            'loc'   => ['foo'],
            'state' => ['fs'],
        ]);

        $fooFs = $space->slot('foo.fs');
        $nil = $space->nilSlot();
        $inventory = new Inventory([[$fooFs, 2]]);

        $result = (new MovementEngine())->execute(
            $inventory,
            new MovementPath(new MovementEdge($fooFs, $nil)),
            2,
        );

        self::assertSame(0, $result->remaining());
        self::assertCount(1, $result->events());
        self::assertSame(0, $inventory->get($fooFs));
        self::assertSame(0, $inventory->get($nil));
        self::assertNull($result->events()[0]->initialTo());
    }
}
