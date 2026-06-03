<?php

namespace MohamedSamy902\AdvancedFileUpload\Exceptions;

use RuntimeException;

/**
 * Thrown when a file's MIME magic bytes do not match the declared extension/content-type.
 */
final class MimeTypeMismatchException extends RuntimeException {}
