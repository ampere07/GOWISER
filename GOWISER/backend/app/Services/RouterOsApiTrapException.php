<?php

namespace App\Services;

/**
 * A RouterOS API `!trap`: the router rejected the command (unknown menu, bad argument,
 * failed login, ...). The connection itself is still usable.
 */
class RouterOsApiTrapException extends \RuntimeException
{
}
