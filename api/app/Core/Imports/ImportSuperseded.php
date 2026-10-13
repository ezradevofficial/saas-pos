<?php

namespace App\Core\Imports;

use RuntimeException;

/** IMP-03: the import moved on while a worker ran it (another state was set); the worker stops without touching it. */
final class ImportSuperseded extends RuntimeException {}
