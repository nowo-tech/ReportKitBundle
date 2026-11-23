<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\Contract;

/**
 * Host SPI (contract §4.3): the people directory a scheduled run reads **at run time**, never a stored copy. The run
 * builds as `userOf()` (the same object the scope resolver and access checker receive on the pages), mails
 * `email()` in `locale()`, and pauses with `owner_inactive` when `isActive()` is false or the user is gone.
 *
 * Every method must answer for exactly the owner asked for (REQ-SEC-006 rule 4): the bundle re-checks `userOf()`
 * through the owner resolver and refuses a user whose owner reference differs. Called once per run, never memoised.
 *
 * Default: `owner.directory` service, else the Doctrine directory of `owner.class` (looked up by `owner.id_field`;
 * `getEmail()`, `getLocale()`, `getTimezone()`, `isEnabled()` / `isActive()`, `getDisplayName()` when present).
 */
interface OwnerDirectoryInterface
{
    /**
     * The host user object of the owner; null when it no longer exists.
     */
    public function userOf(OwnerRef $owner): ?object;

    /**
     * Whether the owner may still receive scheduled reports (not disabled, not anonymised).
     */
    public function isActive(OwnerRef $owner): bool;

    /**
     * The owner's current e-mail address; null = none (the run pauses with `owner_inactive`).
     */
    public function email(OwnerRef $owner): ?string;

    /**
     * The owner's locale (`es`, `pt_BR`); null = `en`.
     */
    public function locale(OwnerRef $owner): ?string;

    /**
     * The owner's IANA time zone; null = `schedule.default_timezone`. The default hour of a new subscription is read
     * in it (Q-O5).
     */
    public function timezone(OwnerRef $owner): ?string;

    /**
     * A name to greet the owner with in the mail; null = no name.
     */
    public function displayName(OwnerRef $owner): ?string;
}
