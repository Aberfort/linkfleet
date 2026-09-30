<?php

namespace App\Exceptions;

use RuntimeException;

/** A conversion that cannot be recorded, with a reason fit to show the person who sent it. */
class ConversionRejected extends RuntimeException {}
