<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Rules;

/**
 * Whether the edges a flow may traverse are limited to the ones declared by edge rules.
 *
 * The same "stated, not inferred" argument as {@see SlotRuleBase}, applied to topology rather than
 * to the slot set. A movement step names a `from` and a `to` pattern, and those patterns can always
 * express a pair the modeller never sanctioned; whether that pair is a legal movement is a question
 * about the space, not about the step, so the space has to answer it — and has to say which answer
 * it is giving.
 *
 * @api
 */
enum EdgeRuleBase
{
    /**
     * Any pair the step's patterns can express is traversable; edge rules label, annotate and deny.
     *
     * The default, and what a space that declares no topology has always meant. Declaring rules
     * under this base still buys labeled steps ({@see \Nandan108\SlotFlow\Flow::stepByLabeledEdges})
     * and the metadata the timed layer reads, but it does not constrain `move()`.
     */
    case All;

    /**
     * Only declared edges are traversable; a `move()` over an undeclared pair moves nothing.
     *
     * The base that makes "an edge is an allowed movement" true. Worth stating wherever the legal
     * transitions are part of the domain rather than an implementation detail — an unsanctioned
     * path then becomes a refusal at execution instead of a movement someone has to notice
     * afterwards.
     *
     * Boundary movements are exempt: a step into or out of the nil slot — `create()` and
     * `destroy()` — is never constrained by this base, for the same reason the nil slot survives
     * any {@see SlotRuleBase}. It is the outside of the space, not a member of it, so no topology
     * rule describes it.
     */
    case None;
}
