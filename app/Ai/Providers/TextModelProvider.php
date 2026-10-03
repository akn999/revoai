<?php

namespace App\Ai\Providers;

use App\Ai\Dto\TextRequest;
use App\Ai\Dto\TextResponse;

interface TextModelProvider
{
    public function converse(TextRequest $request): TextResponse;
}
