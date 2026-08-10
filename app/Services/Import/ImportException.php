<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * A document could not be parsed at all.
 *
 * Distinct from a warning: a warning means "one block was skipped, here is the
 * rest", whereas this means there is nothing usable to show a review screen.
 *
 * The message is written to be read by the person who uploaded the file, not
 * by a developer — it goes straight onto the screen.
 */
class ImportException extends RuntimeException {}
