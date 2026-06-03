<?php

namespace MohamedSamy902\AdvancedFileUpload\Exceptions;

use RuntimeException;

/**
 * Thrown when a URL upload is blocked due to SSRF protection.
 */
final class SsrfException extends RuntimeException {}
