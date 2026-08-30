<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the one-way dependency between the execution core and the timed/demand layer.
 *
 * The timed and demand-scheduling layer is the least mature part of the library and currently has
 * no consumer. Keeping its dependency one-way means that immaturity cannot constrain the code that
 * does ship: the core may be refactored without keeping a scheduling design honest, and the timed
 * layer can be reshaped — or dropped — without touching the core.
 *
 * The boundary is enforced here rather than by a package split, because the value is in the
 * direction of the dependency, not in shipping two artefacts. If the layer is ever extracted to its
 * own package, this test is what makes that a packaging change instead of a redesign.
 */
final class BoundaryTest extends TestCase
{
    /**
     * Files permitted to reference the timed layer from outside `src/Time/`.
     *
     * Each entry is a scheduling-side class that happens not to live under `Time/`. Adding to this
     * list widens the boundary — do it deliberately, and never for a file that belongs to the
     * execution core.
     *
     * @var list<string>
     */
    private const TIMED_LAYER_OUTSIDE_TIME_DIR = [
        'Calendars/WeeklyShipmentCalendar.php',
        'PlannerRules/WeeklyShipmentCalendarRule.php',
        'Results/ScheduleMilestone.php',
        'Results/ScheduledStep.php',
        'Results/TimedQuantityStateDelta.php',
        'Solvers/EarliestArrivalSolver.php',
        // The core's own remaining temporal seam, and the only entry here that is not itself part
        // of the timed layer. SlotSpace still declares a time axis and a TemporalContext so that
        // `defineTimed()` and `$space->timeAxis` keep working; severing that last strand is a BC
        // break on published API, not a refactor. Removing this line is the goal.
        'SlotSpace.php',
    ];

    public function testTheExecutionCoreDoesNotDependOnTheTimedLayer(): void
    {
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $path) {
            if (str_starts_with($relative, 'Time/')) {
                continue;
            }

            if (in_array($relative, self::TIMED_LAYER_OUTSIDE_TIME_DIR, true)) {
                continue;
            }

            $contents = (string) file_get_contents($path);
            if (str_contains($contents, 'SlotFlow\\Time\\')) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'These execution-core files reference the timed layer. The dependency must run one way '
            ."(timed -> core), so either move the file into the timed layer's allow-list above if it "
            .'genuinely belongs to that layer, or remove the reference.',
        );
    }

    public function testTheSlotCodecContractIsTimeFree(): void
    {
        // The codec is a documented extension point of the core. A time type in its signature makes
        // every custom codec carry a scheduling dependency it has no use for.
        foreach (['Contracts/SlotCodec.php', 'Codecs/DefaultSlotKeyCodec.php'] as $relative) {
            $contents = (string) file_get_contents(__DIR__.'/../src/'.$relative);

            self::assertStringNotContainsString(
                'SlotFlow\\Time\\',
                $contents,
                "$relative must not reference the timed layer.",
            );
        }
    }

    public function testTheTimedLayerAllowListHasNoStaleEntries(): void
    {
        $stale = [];

        foreach (self::TIMED_LAYER_OUTSIDE_TIME_DIR as $relative) {
            $path = __DIR__.'/../src/'.$relative;
            if (!is_file($path) || !str_contains((string) file_get_contents($path), 'SlotFlow\\Time\\')) {
                $stale[] = $relative;
            }
        }

        self::assertSame(
            [],
            $stale,
            'These files no longer reference the timed layer (or no longer exist), so the boundary '
            .'has moved in the right direction. Drop them from the allow-list to lock the gain in.',
        );
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private static function sourceFiles(): array
    {
        $root = realpath(__DIR__.'/../src');
        self::assertIsString($root);

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $absolute = (string) $file->getRealPath();
            $files[str_replace(\DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($root) + 1))] = $absolute;
        }

        ksort($files);

        return $files;
    }
}
