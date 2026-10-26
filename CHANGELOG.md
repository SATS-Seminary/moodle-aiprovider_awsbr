# Changelog

All notable changes to the AWS Bedrock AI provider (`aiprovider_awsbr`).

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
