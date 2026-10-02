<?php

namespace App\Tenancy;

use RuntimeException;

/** No usable Business for the operation: absent, suspended, or not the one this actor belongs to. */
class BusinessContextException extends RuntimeException {}
