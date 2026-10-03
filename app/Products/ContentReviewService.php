<?php

namespace App\Products;

use App\Ai\AiContext;
use App\Models\ContentGeneration;
use App\Models\ContentVersion;
use App\Models\Merchant;
use App\Models\Product;
use App\Moderation\ModerationBlocked;
use App\Moderation\ModerationService;
use App\Products\Exceptions\OutdatedDraft;
use App\Products\Exceptions\PushFailed;
use App\Products\Exceptions\ReviewRejected;

/**
 * The review queue (FR-PRD-010/011/012/019/020): edit inline with limits enforced, approve per field or
 * all at once, reject, push only what was approved. Rejecting never refunds a successful generation.
 */
class ContentReviewService
{
    public function __construct(
        private ProductPushService $push,
        private HtmlSanitizer $sanitizer,
        private SlugGenerator $slugs,
        private ModerationService $moderation,
    ) {}

    /**
     * Fields of a draft that are still waiting for approval.
     *
     * @return array<int, string>
     */
    public function pendingFields(ContentGeneration $generation): array
    {
        $approved = $generation->approvedFields();

        return array_values(array_diff($generation->fields, $approved));
    }

    /**
     * Whether the product changed in Salla after the draft was generated (FR-PRD-020).
     */
    public function isOutdated(ContentGeneration $generation): bool
    {
        $product = Product::query()->find($generation->product_id);

        return $product !== null && $generation->source_hash !== null && $product->relevant_hash !== $generation->source_hash;
    }

    /**
     * @param  array<int, string>|null  $fields  Null approves every pending field.
     * @param  array<string, string>  $edits  field => merchant-edited value
     *
     * @throws ReviewRejected
     * @throws OutdatedDraft
     * @throws PushFailed
     */
    public function approve(ContentGeneration $generation, ?array $fields = null, array $edits = [], ?Merchant $merchant = null, ?int $sallaUserId = null, bool $acknowledgeOutdated = false, bool $silent = false): ContentGeneration
    {
        if (! $generation->isReviewable()) {
            throw new ReviewRejected('This draft can no longer be approved.');
        }

        $merchant ??= Merchant::findBySallaId($generation->merchant_id) ?? throw new ReviewRejected('Store not found.');
        $product = Product::query()->findOrFail($generation->product_id);
        $pending = $this->pendingFields($generation);
        $selected = $fields === null ? $pending : array_values(array_intersect($fields, $pending));

        if ($selected === []) {
            throw new ReviewRejected('Nothing to approve.');
        }

        if (! $acknowledgeOutdated && $this->isOutdated($generation)) {
            throw new OutdatedDraft;
        }

        $values = [];
        $sources = [];
        $errors = [];
        $manual = (array) $generation->manual_fields;

        foreach ($selected as $field) {
            if ($field === 'alt') {
                continue;
            }

            $edited = array_key_exists($field, $edits);
            $value = $edited ? $this->clean($field, (string) $edits[$field], $generation->lang) : ($generation->output[$field] ?? null);

            if (in_array($field, $manual, true) && ! $edited) {
                $errors[$field] = ['This field is over its limit; edit it before approving.'];

                continue;
            }

            if (! is_string($value) || trim($value) === '') {
                $errors[$field] = ['This field is empty.'];

                continue;
            }

            if (FieldLimits::overLimit([$field => $value]) !== []) {
                $errors[$field] = ['Over the '.FieldLimits::limit($field).' character limit.'];

                continue;
            }

            $values[$field] = $value;
            $sources[$field] = $edited ? ContentVersion::MANUAL_EDIT : ContentVersion::AI;
        }

        if ($errors !== []) {
            throw new ReviewRejected('Some fields cannot be approved.', $errors);
        }

        $this->moderateEdits($generation, $edits, $selected);
        $this->push->push($merchant, $product, $generation->lang, $values, $generation, $sources, $sallaUserId);

        if (in_array('alt', $selected, true)) {
            $generation->markAltApproved();
        }

        if ($this->pendingFields($generation->fresh()) === []) {
            $generation->forceFill(['status' => ContentGeneration::APPROVED, 'approved_at' => now()])->save();
        }

        return $generation->fresh();
    }

    public function reject(ContentGeneration $generation): ContentGeneration
    {
        if (! $generation->isReviewable()) {
            throw new ReviewRejected('This draft can no longer be rejected.');
        }

        $generation->forceFill(['status' => ContentGeneration::REJECTED])->save();

        return $generation;
    }

    /**
     * Unapproved text drafts are deleted after the retention period (FR-PRD-019).
     */
    public function pruneExpired(): int
    {
        return ContentGeneration::query()
            ->withoutGlobalScopes()
            ->where('status', ContentGeneration::DRAFT)
            ->where('expires_at', '<', now())
            ->delete();
    }

    private function clean(string $field, string $value, string $language): string
    {
        return match ($field) {
            'description' => $this->sanitizer->sanitize($value, $language),
            'metadata_url' => $this->slugs->make($value, $language),
            default => trim(strip_tags($value)),
        };
    }

    /**
     * @param  array<string, string>  $edits
     * @param  array<int, string>  $selected
     */
    private function moderateEdits(ContentGeneration $generation, array $edits, array $selected): void
    {
        $text = collect($edits)->only($selected)->map(fn ($value) => strip_tags((string) $value))->filter()->implode("\n");

        if ($text === '') {
            return;
        }

        try {
            $this->moderation->checkText(
                new AiContext($generation->merchant_id, 'product_content', 'text_moderation', $generation->salla_user_id),
                $text,
                'content_edit',
                (string) $generation->id,
            );
        } catch (ModerationBlocked $blocked) {
            throw new ReviewRejected($blocked->getMessage(), ['moderation' => [$blocked->getMessage()]]);
        }
    }
}
