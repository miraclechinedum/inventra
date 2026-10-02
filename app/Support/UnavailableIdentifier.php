<?php

namespace App\Support;

/**
 * What an operator is told when a staff email or phone cannot be used.
 *
 * Login identifiers are unique across every Business, so an identifier can be unavailable because
 * a colleague holds it or because an account in another Business does. The wording is identical
 * either way and never says an account exists, so the form cannot be used to learn who is
 * registered elsewhere.
 */
final class UnavailableIdentifier
{
    public const EMAIL = 'This email address can\'t be used. Enter a different one.';

    public const PHONE = 'This phone number can\'t be used. Enter a different one.';

    /** When a database constraint, not validation, caught the clash and the field is unknown. */
    public const EITHER = 'This email address or phone number can\'t be used. Enter different details.';
}
