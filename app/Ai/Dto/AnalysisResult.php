<?php

namespace App\Ai\Dto;

final class AnalysisResult
{
    public function __construct(public readonly string $description, public readonly ?string $requestId = null) {}
}
