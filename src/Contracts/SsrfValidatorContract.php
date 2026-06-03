<?php

namespace MohamedSamy902\AdvancedFileUpload\Contracts;

use MohamedSamy902\AdvancedFileUpload\Exceptions\SsrfException;

interface SsrfValidatorContract
{
    /**
     * Validate a URL against SSRF attack vectors.
     *
     * Checks scheme, hostname resolution, private/reserved IP ranges,
     * and optional domain allowlist.
     *
     * @param  string  $url
     * @return void
     * @throws SsrfException  When the URL is blocked
     */
    public function validate(string $url): void;
}
