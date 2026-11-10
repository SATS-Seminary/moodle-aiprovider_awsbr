# Changelog

All notable changes to the AWS Bedrock AI provider (`aiprovider_awsbr`).

## 1.7.0 — 2026-11-10
- New: the core `generate_image` action. Image models are called with
  `InvokeModel`, with request formats for Amazon Nova Canvas, Amazon Titan Image
  Generator and Stability AI (Stable Diffusion 3 / 3.5 Large, Stable Image Core
  and Ultra); any other model fails with `UnsupportedModel`. One image is
  requested per call. Core's `quality` (`hd` becomes `premium`) and
  `aspectratio` choose the size, and the core `style` option is ignored because
  Bedrock has no equivalent. The image is validated, given Moodle's AI watermark
  and stored in the user's draft area. A request or result blocked by the
  model's content filters fails with `ContentFiltered` (400) and a message that
  does not repeat the prompt. Per-action settings: model (default
  `amazon.nova-canvas-v1:0`), negative prompt and CFG scale. Throttling retries
  once on the fallback region, as for embeddings. Image calls use a 300 second
  read timeout, set through the client factory for these calls only. The upgrade
  adds the action to existing instances **disabled**, because image generation
  costs money: enable it in Configure actions. New instances have it enabled
  like the other actions.
- The model lists are per provider instance. This fixes the model dropdowns
  reading a table that does not exist and the site-wide setting, so they showed
  another instance's models (or none). Each dropdown shows its own instance's
  models, narrowed by that instance's available-models textarea, and keeps the
  model already saved. No AWS call is made when there is no provider instance.
- The embedding-model dropdown is filled from Bedrock `ListFoundationModels`
  (output modality EMBEDDING), and so is the new image-model dropdown (IMAGE).
  Both are cached per instance. They offer only models the plugin can call:
  Titan text embeddings and Cohere Embed v3, and the image families above. The
  same filter applies to the textarea fallback. Embedding and image lines of the
  textarea no longer appear in the chat-model lists.
- `grade_response` model overrides accept any model that the dropdown offers
  (the instance's live list narrowed by the textarea), not only models typed in
  the textarea. The default model of the chat actions is the first such model.
- The privacy provider now declares, exports and deletes both audit tables
  (`aiprovider_awsbr_embed_text` and `aiprovider_awsbr_grade_resp`), and the
  AWS metadata also covers image descriptions and generated images.
- Neutral defaults: the Bedrock region is `us-east-1` and the Polly voice is
  `Ruth`. The speech language is optional (Polly uses the voice's own language
  when none is set). New `speech::list_voices($languagecode, $engine)`;
  `list_french_voices()` is deprecated and calls it with `fr-FR`.
- Corrected the claim that API keys and secret keys are stored encrypted: Moodle
  saves provider settings as plain text, and the help texts now say so.
- `models.php` and `cli/list_models.php` take a `providerid` to browse a
  specific instance; the settings form links to it.
- A README was added.

## 1.6.1 — 2026-11-04
- `grade_response` works with the older AWS SDK that Moodle 5.0 ships (3.342).
  That SDK sent each prompt cache point as an empty block, so Bedrock refused
  every call with "The system field can't be null". Cache points are now sent
  only when the SDK knows them; without them the call works, uncached.

## 1.6.0 — 2026-11-02
- Moodle 5.0 is supported again (requires 5.0 or later; 5.3 also supported). CI
  tests 5.0, 5.1 and 5.3 (plus main). Nothing added since 1.3.0 needed an API
  newer than 5.0.
- New custom action `grade_response`: a general structured-output call. The
  caller supplies a system prompt, an optional cacheable prefix, a variable
  suffix, a JSON schema and an optional model. The answer uses the model's
  native structured output (`output_config.format` of type `json_schema`), not
  forced tool use: newer models reject a forced `toolChoice` and ignore an
  automatic one. The decoded JSON document is returned as `result` together
  with the token counts (including prompt cache reads and writes). A reply cut
  off at the token limit fails with `Truncated`; a reply that is not a JSON
  document fails with `InvalidJson`. Schema limit: `minItems` and `maxItems`
  accept only 0 or 1, and the action does not rewrite the schema, so callers
  must enforce item counts themselves. A model override must be listed in the provider's
  available models (or be the action's configured model), otherwise the call
  fails with `ModelNotAllowed` and nothing is sent to AWS. Per-action settings:
  model, max tokens (default 2048) and effort. The audit table
  `aiprovider_awsbr_grade_resp` stores token counts and a SHA-256 hash of the
  prompt, never the prompt text.
- The upgrade enables `grade_response` on existing provider instances.

## 1.5.0 — 2026-10-26
- New `speech::put_object_file($key, $path_or_stored_file, $contenttype)` uploads
  large files (hundreds of MB, e.g. lecture recordings) to the speech bucket with
  the AWS SDK's `ObjectUploader`: a single put below 16 MB, otherwise a multipart
  upload (16 MB parts, 3 in parallel) streamed from disk. A failed multipart
  upload is aborted so no orphaned parts remain. Returns the usual
  `['success' => ...]` array.
- The upload uses its own S3 client with a 15 minute per-request timeout
  (`speech_client_factory::get_s3_upload_client()`). `put_object()` and the
  timeouts of every other call are unchanged.

## 1.4.0 — 2026-10-20
- Requires Moodle 5.1 or later (5.0 is end of life). CI tests 5.1 and 5.3
  (plus main) instead of 5.0.
- PHPUnit tests use PHPUnit 11 attributes instead of docblock annotations.

## 1.3.0 — 2026-10-20
- Newer Claude models work: `temperature` and `topP` are no longer sent to
  Claude models after the 4.6 generation (Opus 4.7+, Sonnet 5+, Haiku 5.5,
  Fable, Mythos), which reject them with a 400. Older Claude and non-Claude
  models are unchanged.
- New per-action **Effort** setting (generate, summarise, explain): low,
  medium or high, sent as `output_config.effort` to Claude 4.6 and later.
  Lower effort cuts billed thinking tokens. Defaults to the model's own
  default, so existing instances behave as before.
- New `model_capabilities` helper holds these per-model rules.

## 1.2.0 — 2026-10-13
- Moodle 5.3 compatibility, while keeping Moodle 5.0 support (`requires` is unchanged).
- Region settings no longer depend on core's `\core\aws\admin_settings_aws_region`
  (deprecated since Moodle 4.5, removal planned for 6.0). The plugin now ships its
  own `admin_setting_awsregion` with a built-in region list; free-text regions are
  still accepted.
- Reviewed the core AI API differences between 5.0 and 5.3 (error name in
  responses, token columns moved to `ai_action_register`, `\core_admin` setting
  class aliases, `public/` web root). The processors, `embed_text` action and its
  own audit table already work on both; no code change was needed there.
- CI matrix now includes `MOODLE_503_STABLE`.

## 1.1.4 — 2026-06-30
- `speech::put_object()` now accepts a stream resource as well as a string body,
  so large uploads (e.g. long lecture audio) stream to S3 instead of being read
  whole into memory.

## 1.1.3 — 2026-06-28
- Add `speech::presigned_get_url()` — time-limited presigned S3 GET URLs so
  consumers can deliver generated audio from a private bucket via a stable,
  access-controlled URL (no public objects, no credential exposure).
- Apply the Moodle coding standard across the plugin (codechecker pass).

## 1.1.1 / 1.1.2 — 2026-06-28
- Live-validated the speech service against AWS (Polly generative, Transcribe,
  S3) in eu-west-2.
- Fixed the default generative voice to **Ambre** (fr-FR generative; the previous
  default was neural-only and failed under the generative engine).
- Added `speech::delete_object()` for cleanup of working intermediates.

## 1.1.0 — 2026-06-28
- Added a reusable **AWS speech capability** for consumers such as `local_nida`
  (used by its audio-dubbing pipeline):
  - `speech_client_factory` — IAM-authenticated Polly / Transcribe / S3 clients.
    The speech region is decoupled from the Bedrock translation region (default
    `eu-central-1`), because Polly's generative engine is not available in every
    Bedrock region.
  - `speech` — thin, normalised wrappers: `synthesize`, `start_long_synthesis`
    (+ `get_synthesis_task`), `start_transcription` (+ `get_transcription`),
    `put_object` / `get_object` / `delete_object`, `list_french_voices`.
    Generative engine, fr-FR; every method returns `['success' => bool, …]` and
    never throws to the caller. Clients are injectable for unit testing with the
    AWS SDK `MockHandler`.
  - Per-instance settings on the provider form: speech region, S3 bucket, engine
    and default voice.
  - **Deliberately not** modelled as synchronous `core_ai` actions — Transcribe
    and long-form Polly are asynchronous and S3-backed, which does not fit the
    request/response action model. Consumers call the wrappers directly and drive
    their own submit/poll loop.

## 1.0.0
- Baseline: AWS Bedrock provider for the `core_ai` subsystem (generate, summarise,
  explain and embed text), live model catalog with browse/refresh in settings,
  and a Moodle Plugin CI workflow.
