<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

use Nandan108\SlotFlow\Contracts\ShipmentCalendarRuleInterface;
use Nandan108\SlotFlow\Contracts\ShipmentPlannerInterface;
use Nandan108\SlotFlow\Exceptions\SlotFlowInvalidArgumentException;
use Nandan108\SlotFlow\Results\DemandShipment;
use Nandan108\SlotFlow\Results\DemandShipmentLine;
use Nandan108\SlotFlow\Results\ScheduledStep;

/**
 * Builds shipments by walking the arrival timeline, applying consolidation windows and release policies.
 *
 * @experimental The timed and demand-scheduling layer is unproven against a real workload:
 *               tested and documented, but not yet validated by a production consumer, so
 *               its shape is expected to change once one exists. Pin an exact version if
 *               you build on it. The execution engine carries no such caveat.
 *
 * @api
 */
final class TimelineShipmentPlanner implements ShipmentPlannerInterface
{
    /**
     * @param list<Results\DemandLineSchedule> $lineSchedules
     *
     * @return list<DemandShipment>
     */
    #[\Override]
    public function plan(DemandScheduleRequest $request, array $lineSchedules): array
    {
        /** @var array<int, list<array{subjectKey: string, quantity: int|float}>> $arrivalsByTime */
        $arrivalsByTime = [];
        /** @var array<string, int|float> $availableBySubject */
        $availableBySubject = [];
        /** @var array<string, int|float> $shippedBySubject */
        $shippedBySubject = [];
        /** @var list<DemandShipment> $shipments */
        $shipments = [];

        foreach ($lineSchedules as $lineSchedule) {
            foreach ($lineSchedule->arrivals as $arrival) {
                $arrivalsByTime[$arrival->time][] = [
                    'subjectKey' => $lineSchedule->subjectKey,
                    'quantity'   => $arrival->quantity,
                ];
            }
        }

        if ([] === $arrivalsByTime) {
            return [];
        }

        ksort($arrivalsByTime);
        $times = array_keys($arrivalsByTime);
        $index = 0;
        $lastCandidateTime = 0;

        while ($index < count($times)) {
            $windowStart = $times[$index];
            $candidateTime = $windowStart + $request->consolidationWindow;
            $lastCandidateTime = $candidateTime;

            while ($index < count($times) && $times[$index] <= $candidateTime) {
                foreach ($arrivalsByTime[$times[$index]] as $arrival) {
                    /** @psalm-suppress InvalidOperand */
                    $availableBySubject[$arrival['subjectKey']] = ($availableBySubject[$arrival['subjectKey']] ?? 0) + $arrival['quantity'];
                }

                ++$index;
            }

            $this->releaseAtCandidateTime(
                request: $request,
                lineSchedules: $lineSchedules,
                candidateTime: $candidateTime,
                finalEvaluation: false,
                availableBySubject: $availableBySubject,
                shippedBySubject: $shippedBySubject,
                shipments: $shipments,
            );
        }

        $this->releaseAtCandidateTime(
            request: $request,
            lineSchedules: $lineSchedules,
            candidateTime: $lastCandidateTime,
            finalEvaluation: true,
            availableBySubject: $availableBySubject,
            shippedBySubject: $shippedBySubject,
            shipments: $shipments,
        );

        return $shipments;
    }

    /**
     * @param list<Results\DemandLineSchedule> $lineSchedules
     * @param array<string, int|float>         $availableBySubject
     * @param array<string, int|float>         $shippedBySubject
     * @param list<DemandShipment>             $shipments
     */
    #[\Override]
    public function context(
        DemandScheduleRequest $request,
        array $lineSchedules,
        int $time,
        bool $finalEvaluation,
        array $availableBySubject,
        array $shippedBySubject,
        array $shipments,
    ): DemandReleaseContext {
        return new DemandReleaseContext(
            $request,
            $lineSchedules,
            $time,
            $finalEvaluation,
            $availableBySubject,
            $shippedBySubject,
            $shipments,
        );
    }

    /**
     * @param list<Results\DemandLineSchedule> $lineSchedules
     * @param array<string, int|float>         $availableBySubject
     * @param array<string, int|float>         $shippedBySubject
     * @param list<DemandShipment>             $shipments
     */
    private function releaseAtCandidateTime(
        DemandScheduleRequest $request,
        array $lineSchedules,
        int $candidateTime,
        bool $finalEvaluation,
        array &$availableBySubject,
        array &$shippedBySubject,
        array &$shipments,
    ): void {
        $context = $this->context(
            $request,
            $lineSchedules,
            $candidateTime,
            $finalEvaluation,
            $availableBySubject,
            $shippedBySubject,
            $shipments,
        );
        $shipmentLines = $request->releasePolicy->release($context);
        if ([] === $shipmentLines) {
            return;
        }

        $calendarContext = $this->context(
            $request,
            $lineSchedules,
            $candidateTime,
            $finalEvaluation,
            $availableBySubject,
            $shippedBySubject,
            $shipments,
        );
        $releaseTime = $this->releaseTime(
            $calendarContext,
            $shipmentLines,
            $candidateTime,
        );
        if ([] !== $shipments && $shipments[array_key_last($shipments)]->releaseTime === $releaseTime) {
            $lastShipment = array_pop($shipments);
            $shipmentLines = [...$lastShipment->lines, ...$shipmentLines];
        }

        $shipments[] = new DemandShipment($releaseTime, $shipmentLines);

        foreach ($shipmentLines as $shipmentLine) {
            $subjectKey = $shipmentLine->subjectKey;
            /** @psalm-suppress InvalidOperand */
            $availableBySubject[$subjectKey] = ($availableBySubject[$subjectKey] ?? 0) - $shipmentLine->quantity;

            if (($availableBySubject[$subjectKey] ?? 0) <= 0) {
                unset($availableBySubject[$subjectKey]);
            }

            /** @psalm-suppress InvalidOperand */
            $shippedBySubject[$subjectKey] = ($shippedBySubject[$subjectKey] ?? 0) + $shipmentLine->quantity;
        }
    }

    /**
     * @param list<DemandShipmentLine> $shipmentLines
     */
    private function releaseTime(
        DemandReleaseContext $context,
        array $shipmentLines,
        int $candidateTime,
    ): int {
        $releaseTime = $candidateTime;

        foreach ($shipmentLines as $shipmentLine) {
            $alreadyShipped = $context->shippedQuantityForLine($shipmentLine->lineSchedule);
            foreach ($shipmentLine->lineSchedule->releasedArrivalSteps($candidateTime, $alreadyShipped, $shipmentLine->quantity) as $step) {
                foreach ($step->shipmentCalendarRules() as $rule) {
                    $releaseTime = $this->applyShipmentCalendarRule($rule, $context, $shipmentLine, $step, $releaseTime);
                }
            }
        }

        if (null !== $context->request->shipmentCalendar) {
            $releaseTime = $this->validateReleaseTime(
                $context->request->shipmentCalendar->releaseTime($context),
                $releaseTime,
            );
        }

        return $releaseTime;
    }

    private function applyShipmentCalendarRule(
        ShipmentCalendarRuleInterface $rule,
        DemandReleaseContext $context,
        DemandShipmentLine $shipmentLine,
        ScheduledStep $step,
        int $releaseTime,
    ): int {
        return $this->validateReleaseTime(
            $rule->releaseTime($context, $shipmentLine, $step, $releaseTime),
            $releaseTime,
        );
    }

    private function validateReleaseTime(int $releaseTime, int $minimumTime): int
    {
        if ($releaseTime < $minimumTime) {
            throw new SlotFlowInvalidArgumentException(
                'Shipment calendar cannot move a release earlier than the candidate shipment time.',
                ['release_time' => $releaseTime, 'candidate_time' => $minimumTime],
            );
        }

        return $releaseTime;
    }
}
