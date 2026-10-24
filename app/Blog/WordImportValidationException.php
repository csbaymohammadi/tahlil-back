<?php

namespace App\Blog;

use RuntimeException;

class WordImportValidationException extends RuntimeException
{
    /**
     * @param  list<array{code: string, field: string, label: string, location: string, message: string}>  $issues
     * @param  array<string, list<string>>  $fields
     */
    public function __construct(public array $issues, public array $fields = [])
    {
        parent::__construct('The Word document has validation issues.');
    }
}
