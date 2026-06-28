# Changelog

All notable changes to the AWS Bedrock AI provider (`aiprovider_awsbr`).

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
