<?php

namespace App\Moderation;

use App\Ai\AiContext;
use App\Ai\AiGateway;
use App\Ai\Dto\TextRequest;
use App\Ai\Exceptions\AiUnavailable;
use App\Ai\Exceptions\InvalidStructuredOutput;
use App\Ai\ModelResolver;
use App\Models\ModerationCategory;
use App\Models\ModerationEvent;
use Illuminate\Support\Collection;

/**
 * Platform-wide moderation: text with Bedrock, images with fal.ai. Every decision is logged and
 * every failure fails closed. Categories are managed by super admins, never by merchants.
 */
class ModerationService
{
    public function __construct(private AiGateway $gateway, private ModelResolver $models) {}

    /**
     * @return Collection<int, ModerationCategory>
     */
    public function categories(): Collection
    {
        return ModerationCategory::query()->active()->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * @throws ModerationBlocked
     * @throws ModerationUnavailable
     */
    public function checkText(AiContext $context, ?string $text, string $subjectType, ?string $subjectId = null): void
    {
        if ($text === null || trim($text) === '') {
            return;
        }

        $categories = $this->categories();

        if ($categories->isEmpty()) {
            $this->log($context, $subjectType, $subjectId, null, 'bedrock', 'allowed');

            return;
        }

        $model = $this->models->defaultFor('text_moderation') ?? $this->fail($context, $subjectType, $subjectId, 'bedrock');
        $context = $context->for('text_moderation', 'text_moderation');

        $request = new TextRequest(
            $model->provider_model_id,
            $this->textPolicy($categories),
            [['role' => 'user', 'content' => $text]],
            maxTokens: 200,
            temperature: 0.0,
        );

        try {
            $result = $this->gateway->structured($context, $model, $request, [
                'type' => 'object',
                'properties' => [
                    'blocked' => ['type' => 'boolean'],
                    'category' => ['type' => ['string', 'null'], 'enum' => [...$categories->pluck('key')->all(), null]],
                ],
                'required' => ['blocked'],
            ], 'report_moderation');
        } catch (AiUnavailable|InvalidStructuredOutput) {
            $this->fail($context, $subjectType, $subjectId, 'bedrock');
        }

        $this->decide($context, $categories, (bool) ($result['blocked'] ?? false), $result['category'] ?? null, $subjectType, $subjectId, 'bedrock');
    }

    /**
     * @throws ModerationBlocked
     * @throws ModerationUnavailable
     */
    public function checkImage(AiContext $context, string $imageUrl, string $subjectType, ?string $subjectId = null): void
    {
        $categories = $this->categories();
        $model = $this->models->defaultFor('image_moderation') ?? $this->fail($context, $subjectType, $subjectId, 'fal');
        $context = $context->for('image_moderation', 'image_moderation');

        try {
            $verdict = $this->gateway->moderateImage(
                $context,
                $model,
                $imageUrl,
                $categories->pluck('instruction', 'key')->all(),
            );
        } catch (AiUnavailable) {
            $this->fail($context, $subjectType, $subjectId, 'fal');
        }

        $this->decide($context, $categories, $verdict->blocked, $verdict->category, $subjectType, $subjectId, 'fal');
    }

    /**
     * The always-on instructions appended to every edit prompt (modesty rule included), never editable.
     */
    public function platformRules(): string
    {
        return (string) config('revo.moderation_platform_rules');
    }

    /**
     * @param  Collection<int, ModerationCategory>  $categories
     */
    private function decide(AiContext $context, Collection $categories, bool $blocked, ?string $category, string $subjectType, ?string $subjectId, string $provider): void
    {
        $matched = $category ? $categories->firstWhere('key', $category) : null;

        if (! $blocked) {
            $this->log($context, $subjectType, $subjectId, null, $provider, 'allowed');

            return;
        }

        $this->log($context, $subjectType, $subjectId, $matched->key ?? $category, $provider, 'blocked');

        throw new ModerationBlocked($matched->key ?? $category ?? 'other', $matched->name_en ?? 'Policy violation');
    }

    private function fail(AiContext $context, string $subjectType, ?string $subjectId, string $provider): never
    {
        $this->log($context, $subjectType, $subjectId, null, $provider, 'error');

        throw new ModerationUnavailable;
    }

    private function log(AiContext $context, string $subjectType, ?string $subjectId, ?string $category, string $provider, string $decision): void
    {
        ModerationEvent::query()->create([
            'merchant_id' => $context->merchantId,
            'salla_user_id' => $context->sallaUserId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'category' => $category,
            'provider' => $provider,
            'decision' => $decision,
        ]);
    }

    /**
     * @param  Collection<int, ModerationCategory>  $categories
     */
    private function textPolicy(Collection $categories): string
    {
        $lines = $categories->map(fn (ModerationCategory $category): string => "- {$category->key}: {$category->instruction}"
            .($category->allowed ? " Explicitly allowed: {$category->allowed}" : ''));

        return "You are a strict content moderation filter for an e-commerce platform. Decide whether the user's text asks for or contains content in any blocked category. Genuine branded products, existing logos and adding brand logos are always allowed. Report blocked=false and category=null when nothing is violated.\n\nBlocked categories:\n".$lines->implode("\n");
    }
}
