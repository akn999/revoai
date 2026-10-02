# Blueprint: Revo AI super-admin panel (Filament)

Planned before implementation, per the SRS (FR-ADM-001…015). Derived from the Phase 1 plan.

## Filament resources (planned before implementation)

Panel `admin` (path `/admin`): guard `admin`, `AdminUser`, mandatory app-based
TOTP, no registration. Navigation groups: **Merchants**, **Billing**, **AI**,
**Content defaults**, **Operations**, **Salla**, **System**. Every resource uses
the project pattern (resource + `Tables/` + `Schemas/` classes), is `Heroicon`
labelled, and edits to config models are audited (`AuditsAdminChanges` trait →
`admin_audit_logs`: admin, model, id, before/after, time; FR-ADM-014).

Scaffold order/commands (all `--no-interaction`; re-run once if the scaffold's
first run throws `NonInteractiveValidationException`):

| # | Resource (location `App\Filament\Resources\…`) | Group | Pages | Core spec |
| --- | --- | --- | --- | --- |
| 1 | `MerchantResource` (Merchants) | Merchants | List, View | Columns: name, merchant_id, status badge, plan_code, wallet balance (relationship), usage this month (withSum from usage_ledger), installed_at; search merchant_id/name/email/domain; filters status, plan, reauth_required. View infolist: settings (read-only), relation managers: **Ledger** (credit_transactions), **Content generations**, **Image generations**, **Moderation events**, **Webhook events**. Header actions: `Grant credits` / `Deduct credits` modal (amount, **reason required**; deduction capped at available balance; writes ledger + audit). FR-ADM-010/011. |
| 2 | `AiModelResource` | AI | List, Create, Edit | Form: provider select (bedrock/fal), provider model id, name ar/en, features (CheckboxList of 7 features), capabilities (vision, tool use), credit prices per action (KeyValue/Repeater), parameter schema (Textarea JSON, validated), cost rates (USD per 1k in/out or per image), active, default-for-feature (multi-select). Table filters provider/feature/active. FR-ADM-002, FR-AI-002. |
| 3 | `PricingSettings` (custom Page, Billing) | Billing | Page | Form fields: default price per action (product content 8, field regen 2, image edit 20), starter credits (100), low-balance threshold (40); saved to `app_settings`; audited. FR-ADM-003. Per-model/per-preset overrides live on AiModel/Preset forms. |
| 4 | `PromptDefaultResource` | Content defaults | List, Edit | Four keys (product tone, image general, image editing, chat system); not versioned; no create/delete. FR-ADM-004. |
| 5 | `PresetResource` (default presets, `merchant_id` null) | Content defaults | List, Create, Edit | name ar/en, prompt, model (AiModel select, image edit), params JSON, price override, active, sort; reorderable. FR-ADM-005. |
| 6 | `ModerationCategoryResource` | AI | List, Edit | name, description, instruction text, active toggle (TernaryFilter + ToggleColumn); no create/delete. FR-ADM-006. |
| 7 | `ContextOptionResource` | Content defaults | List, Create, Edit | field (select of dropdown fields), key, label ar/en, sort, active. FR-ADM-007. |
| 8 | `PlanResource` | Billing | List, Create, Edit | code (slug), name, features (KeyValue toggles), Salla plan reference (`salla_plan_name`); widget **Unmapped plans** (subscriptions with plan_id null). FR-ADM-008. |
| 9 | `CreditPackResource` | Billing | List, Create, Edit | Salla add-on slug, credits, name ar/en, active. FR-ADM-009. |
| 10 | `PurchaseIntentResource` | Billing | List, View | status, verifier strategy, order id, merchant; filters unreconciled/status; actions **Mark reconciled**, **Reverse** (deducts up to available balance, records shortfall, audit). Plus `VerifierSettings` page (strategy switch stored in `app_settings`, no deploy). FR-ADM-012. |
| 11 | `WebhookEventResource` (model `AppEvent`) | Operations | List, View | status/event/merchant filters, payload viewer, **Replay** action (same logic as `salla:events:replay`). FR-ADM-013. |
| 12 | `FailedJobResource` (table `failed_jobs`) | Operations | List, View | **Retry** action (`queue:retry` by uuid), delete forbidden. FR-ADM-013, NFR-REL-001. |
| 13 | `ModerationEventResource` | Operations | List, View | filters merchant/category/decision/subject type; read-only. FR-ADM-013, FR-MOD-003. |
| 14 | `UsageReport` (custom Page) | Operations | Page | Table over `usage_ledger` aggregated by merchant × model × feature: calls, tokens/images, provider cost USD, credits charged; filters date range, merchant, feature. FR-ADM-013. |
| 15 | `AuditLogResource` (`admin_audit_logs`) | System | List, View | filters admin/model/date; before/after diff. FR-ADM-014. |
| 16 | existing `SubscriptionResource`, `ActivityLogResource` | Salla / System | unchanged | now behind the admin guard. |

Authorization for all: any authenticated, MFA-verified `AdminUser`
(`canAccessPanel` true only for `AdminUser`). No policies (single staff role;
roles are open item OI-13). Resources 4 and 6 forbid create/delete; 1, 10–15 are
read-mostly with the explicit actions above. Each resource gets a
`tests/Feature/Filament/<Name>Test.php` (access, columns/filters, search,
actions incl. validation + audit + denial-without-write, tenant independence).
A copy of this section is saved as `blueprints/admin-panel.md` when
implementation starts.

