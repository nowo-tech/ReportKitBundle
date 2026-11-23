<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * Translation domain of the bundle copy (REQ-I18N-003): the catalogue a host overrides. It lives in the contract so
 * every layer (UI, mail, PDF) reads it without depending on the bundle class (REQ-ARCH-002);
 * `TranslationDomain::NAME` is the same value.
 */
final class TranslationDomain
{
    /** The domain name (also the Twig namespace, REQ-TWIG-002). */
    public const NAME = 'NowoReportKitBundle';
}
