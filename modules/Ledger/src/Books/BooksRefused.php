<?php

declare(strict_types=1);

namespace Modules\Ledger\Books;

use RuntimeException;

/** The accounting app refused an entry, with its reason in plain words (e.g. "The period is closed"). */
final class BooksRefused extends RuntimeException {}
