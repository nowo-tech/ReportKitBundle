<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * Type of an extra report parameter ({@see ParameterDefinition}).
 */
enum ParameterType: string
{
    /** One of a closed list of strings. */
    case Choice = 'choice';
    /** An integer within optional bounds. */
    case Int = 'int';
    /** A yes / no switch. */
    case Bool = 'bool';
    /** A calendar day, `Y-m-d`. */
    case Date = 'date';
}
