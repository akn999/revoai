<?php

namespace App\Products\Exceptions;

use RuntimeException;

/**
 * The product changed in Salla after the draft was generated; pushing needs an explicit acknowledgement (FR-PRD-020).
 */
class OutdatedDraft extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This product changed in Salla after the draft was generated. Review it before pushing.');
    }
}
