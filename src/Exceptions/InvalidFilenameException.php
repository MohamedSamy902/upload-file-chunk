<?php

namespace MohamedSamy902\AdvancedFileUpload\Exceptions;

use RuntimeException;

/**
 * Thrown when a filename contains path traversal characters or invalid sequences.
 */
final class InvalidFilenameException extends RuntimeException {}
