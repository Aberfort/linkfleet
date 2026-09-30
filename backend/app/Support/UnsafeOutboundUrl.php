<?php

namespace App\Support;

use RuntimeException;

/** A URL LinkFleet refuses to call. The message is written for the person who typed it. */
class UnsafeOutboundUrl extends RuntimeException {}
