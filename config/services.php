<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'instagram' => [
        'client_id'     => env('INSTAGRAM_CLIENT_ID'),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
        'redirect'      => env('INSTAGRAM_REDIRECT_URI'),
        'link_redirect' => env('INSTAGRAM_LINK_REDIRECT_URI'),
    ],

    'tiktok' => [
        'client_key'    => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect'      => env('TIKTOK_REDIRECT_URI'),
        'link_redirect' => env('TIKTOK_LINK_REDIRECT_URI'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'amazon' => [
        // Default destination for affiliate redirects: the book's Amazon page.
        // Users can override it with a personal review link on their profile.
        'book_url' => env('AMAZON_URL'),

        // ASIN/ISBN of every edition of the book. A user's personal review
        // link must be a review permalink (which may carry one of these ASINs)
        // or the book's own product page - never another product or page.
        // B0GW2FJ2X1 = paperback, B0GX2WRBBB = Kindle, 9941881677 = ISBN
        'book_asins' => array_filter(array_map('trim', explode(',', env('AMAZON_BOOK_ASINS', 'B0GW2FJ2X1,B0GX2WRBBB,9941881677')))),
    ],

    'credits' => [
        'price_cents_per_credit' => (int) env('CREDIT_PRICE_CENTS', 10), // $0.10 per credit, placeholder - tune to real cost

        // HeyGen bills Video Agent generations by duration, not per video, in
        // direct USD (no separate credit-to-dollar conversion on their side -
        // see https://developers.heygen.com/docs/pricing). Confirmed
        // 2026-07-02: $0.0333/sec, cross-checked exactly against real billed
        // usage in HeyGen's own usage export (0.53/1.2/1.3 credits for
        // 15.91s/35.84s/38.69s videos).
        'heygen_cost_cents_per_second' => (float) env('HEYGEN_COST_CENTS_PER_SECOND', 3.33),

        // Markup applied on top of our actual HeyGen cost before converting
        // to our own credits.
        'heygen_margin_multiplier' => (float) env('HEYGEN_MARGIN_MULTIPLIER', 2.0),

        // We don't know a video's real duration until HeyGen finishes
        // rendering it, so this many seconds' worth of credits are held
        // upfront and reconciled (refunded or topped up) once the actual
        // duration is known.
        'heygen_estimated_max_seconds' => (int) env('HEYGEN_ESTIMATED_MAX_SECONDS', 60),

        'heygen_minimum_charge_credits' => (int) env('HEYGEN_MINIMUM_CHARGE_CREDITS', 5),

        // Per-affiliate voice cloning. One-time charge to create/store a voice
        // clone (a HeyGen native clone for "rich" b-roll mode and/or a stored
        // reference sample for open-source Chatterbox "talking-head" mode).
        // Placeholder value - tune once real usage is seen.
        'voice_clone_cost_credits' => (int) env('VOICE_CLONE_COST_CREDITS', 20),

        // Chatterbox HD (Resemble AI, open-source) synthesis on fal.ai, billed
        // ~$0.025 per 1,000 characters. Marked up (reuses heygen_margin_multiplier)
        // and added on top of the video render charge for talking-head videos.
        'chatterbox_cost_cents_per_1k_chars' => (float) env('CHATTERBOX_COST_CENTS_PER_1K_CHARS', 2.5),

        // AI post image generation (chapter -> caption + 1-4 images). Two
        // different engines are used depending on the user's model choice,
        // with very different real costs:
        // - OpenAI gpt-image-2, "low" quality, 1024x1536: ~$0.005-0.01/image
        //   (developers.openai.com/api/docs/guides/image-generation)
        // - Gemini gemini-3-pro-image (Nano Banana Pro), 1K/2K: ~$0.134/image
        //   (ai.google.dev/gemini-api/docs/pricing) - switched from the
        //   flash tier 2026-07-04 for reliable text rendering on images.
        'image_openai_cost_cents' => (float) env('IMAGE_OPENAI_COST_CENTS', 1),
        'image_gemini_cost_cents' => (float) env('IMAGE_GEMINI_COST_CENTS', 13.4),

        // Markup applied on top of our actual image-generation cost.
        'image_margin_multiplier' => (float) env('IMAGE_MARGIN_MULTIPLIER', 2.0),

        // Flat charge for the caption/concept text call (GPT/Claude/Gemini
        // text), which always happens regardless of how many images
        // succeed. Real per-call cost is well under a cent for any of the
        // three models, not worth metering per-model.
        'image_text_generation_cost_credits' => (int) env('IMAGE_TEXT_GENERATION_COST_CREDITS', 1),

        'image_minimum_charge_credits' => (int) env('IMAGE_MINIMUM_CHARGE_CREDITS', 1),

        // Grok Imagine image-to-video, accessed via fal.ai (not xAI directly -
        // confirmed 2026-07-03 via fal.ai/models/xai/grok-imagine-video/image-to-video
        // pricing page): $0.05/sec at 480p, $0.07/sec at 720p, plus a flat
        // $0.002 per image input. Duration is chosen upfront by the user (6
        // or 10s), so unlike HeyGen there's no estimate-then-reconcile step -
        // the exact cost is known before charging.
        'grok_video_cost_cents_per_second_480p' => (float) env('GROK_VIDEO_COST_CENTS_PER_SECOND_480P', 5.0),
        'grok_video_cost_cents_per_second_720p' => (float) env('GROK_VIDEO_COST_CENTS_PER_SECOND_720P', 7.0),
        'grok_video_image_input_cost_cents' => (float) env('GROK_VIDEO_IMAGE_INPUT_COST_CENTS', 0.2),

        // Markup applied on top of our actual Grok cost before converting
        // to our own credits.
        'grok_video_margin_multiplier' => (float) env('GROK_VIDEO_MARGIN_MULTIPLIER', 2.0),

        'grok_video_minimum_charge_credits' => (int) env('GROK_VIDEO_MINIMUM_CHARGE_CREDITS', 3),
    ],

    'heygen' => [
        'api_key' => env('HEYGEN_API_KEY'),
        'base_url' => env('HEYGEN_BASE_URL', 'https://api.heygen.com'),

        // Every generated video must end with a shot of the book cover.
        // HeyGen's Video Agent has no reliable way to force a specific
        // closing frame via prompting alone, so we append it ourselves with
        // ffmpeg after the video finishes rendering - see VideoOutroService.
        // Blank/not-executable path disables outro processing gracefully
        // (falls back to serving HeyGen's raw video) until ffmpeg is
        // installed on this server.
        'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
        'outro_image_path' => env('HEYGEN_OUTRO_IMAGE_PATH'), // null = use the same cover image as image-gen
        'outro_duration_seconds' => (float) env('HEYGEN_OUTRO_DURATION_SECONDS', 3),

        // Interim best-effort attempt while ffmpeg isn't installed yet:
        // attach the cover image and ask the Video Agent to end with it.
        // Not guaranteed - HeyGen's docs are explicit that the agent
        // "autonomously determines visual composition" - this is a stopgap
        // to see how often it actually complies, not a substitute for the
        // deterministic ffmpeg outro above.
        'request_cover_outro_via_prompt' => (bool) env('HEYGEN_REQUEST_COVER_OUTRO_VIA_PROMPT', true),
        'outro_image_url' => env('HEYGEN_OUTRO_IMAGE_URL'), // null = derive from APP_URL + /assets/cover-image.png

        // Preferred over outro_image_url: HeyGen's own edge (resource*.heygen.ai)
        // fetches files server-side, and Hostinger's WAF blocks that fetch for
        // some accounts/IP ranges even though the URL works fine in a browser.
        // Pre-uploading via POST /v3/assets once and referencing the returned
        // asset_id sidesteps the fetch entirely. Falls back to outro_image_url
        // if unset.
        'outro_asset_id' => env('HEYGEN_OUTRO_ASSET_ID'),
    ],

    // Grok Imagine's image-to-video model, accessed through fal.ai's queue
    // API rather than xAI directly - confirmed 2026-07-03 against
    // fal.ai/models/xai/grok-imagine-video/image-to-video/api.
    'fal' => [
        'api_key' => env('FAL_KEY'),
        'base_url' => env('FAL_BASE_URL', 'https://queue.fal.run'),
        'grok_video_model' => env('FAL_GROK_VIDEO_MODEL', 'xai/grok-imagine-video/image-to-video'),
        'grok_video_resolution' => env('FAL_GROK_VIDEO_RESOLUTION', '720p'),

        // Chatterbox HD text-to-speech (Resemble AI, MIT-licensed model) for
        // open-source voice cloning - confirmed 2026-07-07 against
        // fal.ai/models/resemble-ai/chatterboxhd/text-to-speech.
        'chatterbox_model' => env('FAL_CHATTERBOX_MODEL', 'resemble-ai/chatterboxhd/text-to-speech'),

        // fal's account-billing API (admin "API Credits" panel) rejects
        // regular inference keys with 403 - it needs a key created with
        // ADMIN scope at fal.ai/dashboard/keys. Falls back to FAL_KEY so the
        // panel can at least show a helpful error until this is set.
        'admin_api_key' => env('FAL_ADMIN_KEY'),
    ],

    // Read-only reporting keys used ONLY by the admin "API Credits" panel
    // (ProviderBalanceService). Anthropic and OpenAI gate their cost APIs
    // behind dedicated admin keys, separate from the normal inference keys
    // in config/constant.php and config/openai.php. Leaving these unset just
    // shows a "key missing" row in the panel - nothing else breaks.
    'anthropic' => [
        'admin_api_key' => env('ANTHROPIC_ADMIN_API_KEY'),
    ],
    'openai' => [
        'admin_api_key' => env('OPENAI_ADMIN_API_KEY'),
    ],

    // Carbogenetics affiliate provisioning (the new Next.js site on Vercel).
    // At signup every co2body user is auto-enrolled as a carbogenetics.com
    // affiliate via POST {base_url}/api/affiliate/provision; the returned
    // ref_code is stored in users.other_affiliate_id and used by the
    // co2body.com/{handle} -> carbogenetics.com/ref/{code} redirect chain.
    // Until the DNS cutover, point base_url at the Vercel preview URL.
    'carbogenetics' => [
        'base_url' => env('CARBOGENETICS_BASE_URL', 'https://carbogenetics.com'),
        // Nullable on purpose: a missing secret disables provisioning
        // gracefully instead of fataling signup (HeygenService pattern).
        'provision_secret' => env('CARBOGENETICS_PROVISION_SECRET'),
    ],

];
