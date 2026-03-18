<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow;

final class MovementPlan
{
    /**
     * @param MovementPath     $path        the path along which to move the items
     * @param list<Constraint> $constraints optional constraints for the movement
     */
    public function __construct(
        private MovementPath $path,
        private array $constraints = [],
    ) {
    }

    public static function make(MovementPath $path): self
    {
        return new self($path);
    }

    public function path(): MovementPath
    {
        return $this->path;
    }

    /** @return list<Constraint> */
    public function constraints(): array
    {
        return $this->constraints;
    }
}
