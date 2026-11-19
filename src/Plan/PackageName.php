<?php

namespace ErnestDefoe\Millwright\Plan;

/**
 * The rules for package names and exact pins, in one place.
 *
 * A package name is the only value from a request that reaches a command
 * line, so the check that guards it is not something to have four copies of.
 */
class PackageName
{
    /** Composer's own rule for `vendor/name`. */
    public static function valid(string $name): bool
    {
        return (bool) preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $name);
    }

    /**
     * A constraint that admits exactly one version: a full version with no
     * wildcard, caret, tilde, alternative or range, so a newer release can
     * never satisfy it. Ranges genuinely can be "already newest".
     */
    public static function isExactPin(string $constraint): bool
    {
        return preg_match('/^v?\d+\.\d+\.\d+/', $constraint) && ! preg_match('/[\^~*|]|\s-\s/', $constraint);
    }
}
