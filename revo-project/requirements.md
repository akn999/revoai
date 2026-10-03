Your answers resolve almost everything. Seven questions are still open; most come from how the new plans interact with the phases. Each includes my recommendation, so you can reply "use your defaults" if they're fine.

## Questions

**Product content**

84. Your `metadata` sample is nested (`metadata.title`, `metadata.description`, `metadata.url`). The Update Product CURL used flat fields (`metadata_title`, `metadata_description`). Which format does the PUT accept? Also, `url` holds a full URL: should the AI generate only the slug (e.g., `blue-cotton-tshirt`), with the system building the rest? And is the URL set per language?
    *Recommendation:* send the nested format, generate only the slug, and set it per language.
yes, a slug. the updated format is correct with the nested one
**Credits**

85. Does regenerating a single field (say, only the meta description) cost credits? If it's free, a merchant could regenerate one field over and over at no cost.
    *Recommendation:* charge a smaller per-field price, defaulting to 2 credits, set by the super admin like other prices.
yes defaulting to 2 credits if fine and admin can change it
86. `onResult` runs in the browser, so a merchant could fake a "success" call to our backend and get free credits. Is there a server-side way to confirm a purchase, such as a webhook or a Partners API lookup by `order_id`? It also needs to tell us which pack was bought. How do we learn when a `pending` payment completes?
    *Recommendation:* credits are added only after server-side confirmation. Until you have the endpoint, the SRS defines it as a placeholder interface.
I am not sure how this handled from salla side, just take whatever they say for granted. and implement whatever you think is best
**Plans and phases**

87. Complaints are on Pro, but chat is only on Enterprise. On Pro, the chat agent can't file complaints, so how do they get in?
    - (a) A "Report a problem" form in the storefront snippet for logged-in customers.
    - (b) Staff log complaints manually in the dashboard, from calls or WhatsApp.
    - (c) Both.

    *Recommendation:* (c). Option (a) depends on the customer verification you've parked, so the SRS would mark that part as open.
will go with your recommendation

88. Which plans are sold while only the MVP features exist?
    - (a) Only Plus.
    - (b) All three, with Pro and Enterprise features marked "coming soon."

    Also, do complaints and chat ship together in phase 2? Or do complaints ship in phase 2 and chat in phase 3, matching the Pro/Enterprise split?
    *Recommendation:* (a), with complaints in phase 2 and chat in phase 3.
as you say and recommended my lord
**Moderation**

89. "Allowed" settles weapons for hunting and knife stores. It doesn't settle whether generated people must wear modest clothing.
    *Recommendation:* a platform-wide modesty rule for generated people, since the market is mainly Saudi.
yes, I agree with your recommendation my lord
**Design**

90. The SDK docs were attached in our other chat, not this one, so I can't see them here. I'll reference SDK methods by name and point Claude Code to the installed package for exact signatures. If you want the design tokens (colors, fonts, spacing) written into the SRS itself, attach the design guideline doc here.
see attached files... 

## Updated defaults

- **Starter credits:** 100 credits, granted once per Salla merchant ID ever, replacing the earlier 1,000. Reinstalling never grants them again.
- **Credit retention:** Credits never expire. The credit wallet and the "starter credits granted" flag survive uninstalls and the 60-day data purge.
- **Text pricing:** One product, one language costs 8 credits by default, covering all selected fields. Alt text for the product's images is included.
- **Image pricing:** One edit request costs 20 credits by default, whether it produces 1, 2, or 3 variants.
- **Price control:** The super admin sets prices per model and per preset.
- **Plan gating:** Plus includes images and descriptions. Pro adds complaints, and Enterprise adds chat. Complaints and chat use no credits, and chat keeps working at zero credits.
- **Chat models:** On Enterprise, merchants choose a chat model from a list the super admin controls.
- **Plan changes:** The super admin maps each Salla plan to a Revo plan. Plan changes arrive through the `app.subscription.*` events. When a subscription lapses, features lock but data and credits remain.
- **Purchases:**
    - `onResult` is registered at app startup, so results arriving after a 3DS redirect are caught.
    - The pack ID travels in `context`.
    - The browser result only updates the UI. Credits are added only after server-side confirmation.
- **HTML descriptions:**
    - Output is limited to the tags in your example plus bold, italic, and underline, and sanitized against that list.
    - Arabic block elements get `class="ql-direction-rtl"`.
    - Settings lets merchants choose length (short, medium, long) and structure (paragraphs or bullets).
- **Re-sync:** A free per-product re-sync button. Reinstalling within the grace period also triggers a full re-sync automatically.
- **Super admin:** Built with Filament. Super admins can grant credits manually, with every grant recorded in an audit log, and can view merchants' generated content.
- **Weapons rule:** The weapons category blocks violent or illegal depictions only. Product photos of legal items like hunting gear and knives are allowed.
- **Document format:** One SRS document with a section per module and numbered requirement IDs. It can be exported to Markdown for the repo.

Now implement the requirements found in the link https://claude.ai/artifact/JwSrcbERbvjVsNJpzwJ84f#cc19fe68-8c9e.m0zavnte527.374 do not stop until you are finished. Write test cases and if you find any bugs, fix them.











curl --url 'https://revoai-production-tksmup.laravel.cloud/cdn-cgi/challenge-platform/scripts/jsd/main.js' \
-H 'sec-ch-ua-platform: "macOS"' \
-H 'Referer;' \
-H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36' \
-H 'sec-ch-ua: "Google Chrome";v="153", "Not_A Brand";v="8", "Chromium";v="153"' \
-H 'sec-ch-ua-mobile: ?0'
