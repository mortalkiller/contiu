<?php

namespace App\Actions;

use RuntimeException;

/**
 * The workbook cannot be imported as asked (data already exists, or the file is not the expected one).
 */
class ImportRefusedException extends RuntimeException {}
