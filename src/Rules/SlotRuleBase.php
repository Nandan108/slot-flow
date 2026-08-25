<?php

declare(strict_types=1);

namespace Nandan108\SlotFlow\Rules;

/**
 * The slot set a rule sequence starts from, before any rule is applied.
 *
 * Stated by the caller rather than inferred from the first rule. Inference reads well for a rule
 * list written all in one place — lead with an exclusion and you obviously meant "everything except
 * these" — but it makes the meaning of every rule depend on which one happens to come first. Where
 * a sequence is *assembled* from several independent sources, nobody is in a position to know that,
 * and the failure is silent: a list that begins with an inclusion starts from nothing, so an
 * inclusion matching nothing yields a space with no valid slots at all.
 *
 * @api
 */
enum SlotRuleBase
{
    /**
     * Start from the full cartesian product; rules narrow it.
     *
     * The default, and what an exclusion-led sequence has always meant.
     */
    case All;

    /**
     * Start from nothing; only what an inclusion admits becomes valid.
     *
     * For sparsely-populated spaces, where listing what exists is shorter than listing what does
     * not. Say so explicitly — this is the one that turns a mistake into an empty space.
     */
    case None;
}
