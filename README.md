# AWS Bedrock AI Provider (aiprovider_awsbr)

An AI provider plugin for Moodle that connects Moodle's AI subsystem to
[Amazon Bedrock](https://aws.amazon.com/bedrock/) through the Bedrock Converse
API. Once a provider instance is configured and its actions are enabled, any
Moodle AI placement (for example the course assistant or the editor tools) can
use Bedrock models for text generation, summarising and explaining text, and
for generating images.

The plugin also adds two actions for developers (text embeddings and
structured-output grading) and a small set of helpers for Amazon Polly,
Amazon Transcribe and S3.

## Requirements

- Moodle 5.0 or later (`$plugin->requires = 2025040800`). CI tests Moodle 5.0
  and 5.3. Moodle 5.1 is expected to work but is not tested in CI.
- An AWS account with Amazon Bedrock model access granted for the models you
  want to use. Bedrock, Polly, Transcribe and S3 usage is billed by AWS. This
  plugin does not limit spending other than through the rate limits described
  below.
- Nothing to install separately. The plugin uses the AWS SDK for PHP that ships
  with Moodle core.

## Installation

From a ZIP file: go to Site administration > Plugins > Install plugins, upload
the ZIP and follow the prompts.

From Git:

```bash
cd /path/to/moodle
git clone https://github.com/SATS-Seminary/moodle-aiprovider_awsbr.git public/ai/provider/awsbr
```

(Use `ai/provider/awsbr` instead of `public/ai/provider/awsbr` if your Moodle
has no `public` directory.) The folder name must be `awsbr`. Then visit
Site administration > Notifications to finish the install.

## Configuration

### 1. Create a provider instance

1. Go to Site administration > AI > AI providers.
2. Create a new provider instance and choose "AWS Bedrock AI Provider".
3. Fill in the authentication, region and (optionally) model settings
   described below, and save.
4. Open the instance's action settings and enable the actions you want.

You can create more than one instance, for example one per AWS region or per
AWS account.

### 2. Authentication

| Mode | Use it when |
| --- | --- |
| Bedrock API key (bearer token) | You want the simplest setup, or a quick trial. Generate a long-term key in the Bedrock console and paste it in. It is sent as an `Authorization: Bearer` header. Cannot be used for the speech services. |
| Static access key + secret | You have an IAM user with an access key. Works for Bedrock and for speech. Suits servers outside AWS. |
| IAM instance role | Moodle runs on EC2 or ECS and the instance or task has an IAM role. No secret is stored in Moodle. This is the best choice in production on AWS. |
| Assume STS role | Moodle should assume another role (for example one in a different AWS account) before calling Bedrock. Set the role ARN and, optionally, a session name (default `moodle-bedrock`). Works on AWS and off it, see below. |

With "Assume STS role" the `sts:AssumeRole` call is signed with the base
credentials:

- If you fill in the access key ID and secret access key on the instance, they
  sign the call. Use this on a server outside AWS.
- If you leave them blank, the AWS SDK default credential chain signs it: the
  `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` environment variables, the shared
  credentials file or profile, then an ECS task role or EC2 instance profile.

The temporary credentials are cached until shortly before they expire, per role,
session name and base access key ID, so changing the base access key does not
reuse an old session. The speech services (Polly, Transcribe, S3) use the same
credentials.

Moodle saves provider settings as plain text (JSON) in the database. An API key
or secret access key is therefore not encrypted at rest, and anyone with access
to the database or a backup can read it. To avoid storing a secret in Moodle,
use the IAM instance role mode, or the Assume STS role mode on a server that has
an instance role.

Polly, Transcribe and S3 use SigV4 signing, so they need one of the three
IAM-based modes. A Bedrock API key does not work for the speech services.

### 3. Minimum IAM permissions

Grant only what you use. Replace the account ID, bucket name and role ARN.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "BedrockInference",
      "Effect": "Allow",
      "Action": [
        "bedrock:InvokeModel",
        "bedrock:InvokeModelWithResponseStream"
      ],
      "Resource": "*"
    },
    {
      "Sid": "BedrockModelList",
      "Effect": "Allow",
      "Action": [
        "bedrock:ListInferenceProfiles",
        "bedrock:ListFoundationModels"
      ],
      "Resource": "*"
    },
    {
      "Sid": "AssumeRoleOnly",
      "Effect": "Allow",
      "Action": "sts:AssumeRole",
      "Resource": "arn:aws:iam::123456789012:role/MoodleBedrock"
    },
    {
      "Sid": "Speech",
      "Effect": "Allow",
      "Action": [
        "polly:SynthesizeSpeech",
        "polly:StartSpeechSynthesisTask",
        "polly:GetSpeechSynthesisTask",
        "polly:DescribeVoices",
        "transcribe:StartTranscriptionJob",
        "transcribe:GetTranscriptionJob"
      ],
      "Resource": "*"
    },
    {
      "Sid": "SpeechBucket",
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:GetObject",
        "s3:DeleteObject",
        "s3:AbortMultipartUpload"
      ],
      "Resource": "arn:aws:s3:::your-moodle-speech-bucket/*"
    }
  ]
}
```

- Inference: `InvokeModel` covers Converse and embeddings;
  `InvokeModelWithResponseStream` is needed for streaming. For a tighter policy
  restrict `Resource` to the inference-profile and foundation-model ARNs you
  use. A cross-Region profile needs access to the foundation model in each
  Region it routes to.
- Model lists: `bedrock:ListInferenceProfiles` fills the chat-model dropdowns
  and `bedrock:ListFoundationModels` fills the embedding-model and
  image-model dropdowns. Without them the plugin falls back to the textarea
  (see Models).
- Image models: `InvokeModel` also covers `generate_image`. Stability AI models
  are not enabled by default: request access to each in the Bedrock console (in
  the image generation region) before using it, or calls fail with
  `AccessDeniedException`. `bedrock:ListFoundationModels` and
  `bedrock:ListInferenceProfiles` (in that region) fill the image-model dropdown.
- Assume role: the `sts:AssumeRole` statement goes on the identity Moodle
  runs as. The role being assumed needs the Bedrock (and speech) permissions
  above, and its trust policy must allow the Moodle identity.
- Speech: only needed if you use the speech helpers. The bucket must be in the
  speech region and private.

### 4. Region and fallback region

The region (default `us-east-1`) is where Bedrock is called and where the model
list is read from. Pick a region where your chosen models and inference
profiles are available.

The optional fallback region is used only when a request is throttled
(`ThrottlingException`) in the primary region. The plugin then retries once
against the fallback region. Other errors are not retried.

### 5. Models

There are three live lists, both read in the instance's own region with the
instance's credentials and cached:

- Chat models (generate, summarise, explain, `grade_response`): the instance's
  invokable inference profiles, from Bedrock `ListInferenceProfiles`.
- Embedding models (`embed_text`): the instance's on-demand embedding models,
  from Bedrock `ListFoundationModels` (output modality EMBEDDING).
- Image models (`generate_image`): the on-demand image models of the image
  generation region (the instance's region when that is empty), from Bedrock
  `ListFoundationModels` (output modality IMAGE), plus any inference profile
  (`ListInferenceProfiles`) of a supported text-to-image model.

The embedding and image lists offer only the model families the plugin can
call: Titan text embeddings (`amazon.titan-embed-text-*`,
`amazon.titan-embed-g1-text-*`) and Cohere Embed v3 (`cohere.embed-english-v3`,
`cohere.embed-multilingual-v3`); the Stability AI text-to-image models (Stable
Diffusion 3 / 3.5 Large, Stable Image Core and Ultra). Geo and global prefixes
of profile ids (`us.`, `eu.`, `apac.`, `global.` and so on) are ignored when
matching families. Other models Bedrock lists, such as Titan multimodal
embeddings, Cohere Embed v4 or Stability's editing models, are left out.

The optional "Available inference profile IDs" textarea narrows and relabels
these lists. Enter one model per line:

```
global.anthropic.claude-sonnet-4-5-20250929-v1:0|Claude Sonnet 4.5
amazon.titan-embed-text-v2:0|Amazon Titan Embeddings v2
```

The format is `profile_id|Label`. The label is optional. Each dropdown uses
only the lines that match its own live list: chat dropdowns ignore lines that
are not invokable inference profiles, and the embedding dropdown uses only lines
that are embedding models the plugin can call, and the image dropdown only
lines that are image models it can call. If no line matches, the whole live list is shown.
Leave the textarea empty to offer everything.

If a live list cannot be loaded (wrong credentials, missing permission, network
error), the plugin falls back to the textarea. For chat models it uses the
textarea list as it is. For embeddings it offers the Titan v2 default plus any
textarea lines of a supported embedding family. For images it offers Stable
Image Core (`stability.stable-image-core-v1:1`) plus any textarea lines of a
supported image family. A list that loaded but is empty is different: nothing is
offered, and the image settings show a notice (see Image generation).

The new-instance form pre-fills the textarea with example Claude 4.5 profile IDs
and a Titan embeddings line. Clear it if you want every available model offered.

The chat-model set also limits which models a developer may request through
`grade_response` (see below).

To see what Bedrock returns:

- Model browser: `/ai/provider/awsbr/models.php` (site administrators only).
  It lists the profile ID, status and name, and has a "Refresh from Bedrock"
  button. Add `?providerid=<id>` to browse a specific instance.
- CLI, from the Moodle root:
  `php ai/provider/awsbr/cli/list_models.php [--refresh] [--providerid=<id>]`
  (Moodle 5.1 and later: `php public/ai/provider/awsbr/cli/list_models.php ...`).
  It prints the same list. `--refresh` bypasses the cache.

### 6. Actions and per-action settings

Enable each action you want on the instance. Settings by action:

| Setting | Applies to | Notes |
| --- | --- | --- |
| Model | all | Chosen from the model list. |
| System instruction | generate, summarise, explain | Sent with every request. |
| Max tokens | generate, summarise, explain, grade_response | Upper limit on generated tokens. Default 4096 for the core text actions, 2048 for `grade_response`. |
| Temperature | generate, summarise, explain | Default 0.7. |
| Top-P | generate, summarise, explain | Default 1.0. |
| Effort | generate, summarise, explain, grade_response | Model default, Low, Medium or High. |
| Vector dimensions | embed_text | Titan v2 only: 256, 512 or 1024. |
| Image model | generate_image | Chosen from the image model list. Default `stability.stable-image-core-v1:1`. |
| Image generation region | generate_image | Optional AWS region code for image calls (empty = the instance's region). |
| Negative prompt | generate_image | Optional, up to 512 characters: what the images must not show. |
| CFG scale | generate_image | Optional, 1.1 to 10. Nova Canvas and Titan only. |

Temperature and top-P: newer Claude models (after Claude 4.6: Opus 4.7 and
later, Sonnet 5 and later, Haiku 5.5, Fable and Mythos models) do not accept
sampling parameters. For those models the plugin does not send temperature or
top-P at all, so those two settings have no effect. For older Claude models
only temperature is sent; top-P is used only when no temperature is set. For
non-Claude models both are sent.

Effort controls how much reasoning the model does before it answers. Lower
effort answers faster and uses fewer billed output tokens; higher effort helps
with hard problems. It is sent only to Claude 4.6 and later models and is
ignored for every other model. "Model default" leaves the choice to the model.

### 7. Rate limits

Each instance can enforce a per-user limit and a global limit, both in
requests per hour. Enable the limit and set a number greater than 0. A request
over the limit fails with error code 429 (`RateLimitExceeded`).

### 8. Proxy

Bedrock, Polly, Transcribe and S3 requests use Moodle's HTTP proxy settings
(Site administration > General > HTTP), including the bypass list and SOCKS5.
There is no separate proxy setting in this plugin.

### 9. Speech (optional)

The speech settings on the instance are separate from Bedrock because Polly and
Transcribe are different AWS services.

| Setting | Notes |
| --- | --- |
| Speech region | Region for Polly, Transcribe and S3 (default `eu-central-1`). The Polly generative engine is not available in every region. |
| Speech S3 bucket | Private bucket in the speech region, used for source media, transcripts and long synthesis output. |
| Polly engine | Generative (default), Neural or Standard. |
| Default Polly voice | Default `Ruth`. Must support the chosen engine and language in the speech region. |
| Speech language | Optional language code such as `en-US`, used for synthesis when the caller passes none. Blank lets Polly use the voice's own language. |

These settings only affect the developer helpers described below.

## Supported actions

| Action | Defined by | Purpose |
| --- | --- | --- |
| `generate_text` | Moodle core | Free-form text generation. Supports text input only through core; streaming and image input are available to developers (see below). |
| `summarise_text` | Moodle core | Summarise text. |
| `explain_text` | Moodle core | Explain text in simpler terms. |
| `generate_image` | Moodle core | Generate an image from a text description (Stability AI text-to-image models). Disabled after an upgrade; see Image generation. |
| `embed_text` | This plugin | Turn text into a vector. For developers. |
| `grade_response` | This plugin | Get a JSON answer that follows a JSON schema, for example to assess a response against a rubric. For developers. |

On upgrade from an older version, `grade_response` is enabled on existing
instances and `generate_image` is added to them disabled.

### Image generation

`generate_image` is a Moodle core action, so core placements (for example the
text editor's image tool) use it without extra code.

- **Opt in.** Image generation costs money. On upgrade the action is added to
  existing instances disabled; enable it in Site administration > AI > AI
  providers > your instance > Configure actions. New instances have it enabled
  like the other actions, so disable it there if you do not want it.
- **Models and regions.** Text-to-image models on Bedrock are offered in only
  a few AWS regions (today us-west-2: Stable Image Core, Stable Image Ultra and
  Stable Diffusion 3.5 Large; the editing models of other regions need an input
  image and are not offered). Amazon Nova Canvas and Amazon Titan Image
  Generator were retired by AWS and are never offered or used as defaults. Check
  AWS's regional availability page: https://docs.aws.amazon.com/bedrock/latest/userguide/models-region-compatibility.html
  Each model also needs model access granted in the Bedrock console.
  The default model is Stable Image Core (`stability.stable-image-core-v1:1`).
  If no model is set, the first model of the list is used.
- **Image generation region.** The per-action setting "Image generation
  region" (for example `us-west-2`) sends image calls, and lists image models,
  in that region. Empty means the instance's region. This has a data-residency
  implication: image prompts and generated images are processed in that region,
  which can differ from the instance's region and country. After changing it,
  save and reopen the settings to refresh the model list. The instance's
  fallback region is used for throttled image calls only when no image
  generation region is set.
- **Empty and error notices.** When the list loaded but the region has no
  text-to-image models, the settings show a warning that names the region and
  suggests another image generation region, and the dropdown offers no model.
  When the list could not be loaded (credentials, or a missing
  `bedrock:ListFoundationModels` permission), a different warning is shown and
  the dropdown falls back to the default model plus the textarea lines.
- **Settings.** Model, image generation region, negative prompt (what to keep out of the image) and CFG
  scale (how closely Nova Canvas and Titan follow the prompt). There is no seed
  setting, because a fixed seed would return the same image every time.
- **Quality and shape.** The person generating the image chooses quality and
  aspect ratio. Quality `hd` maps to Nova Canvas and Titan `premium` and to
  larger sizes: Nova Canvas 1024 x 1024, 1024 x 576 and 576 x 1024 (hd:
  1792 x 1792, 1792 x 1024, 1024 x 1792); Titan 768 x 768, 1152 x 768 and
  768 x 1152 (hd: 1024 x 1024, 1408 x 768, 768 x 1408). Stability models take
  the aspect ratios 1:1, 16:9 and 9:16 and have no quality option.
- **One image per request.** Always one image is requested, whatever the caller
  asks for.
- **Style is ignored.** Core's `vivid` / `natural` style has no reliable
  equivalent on Bedrock, so it is not sent. Describe the style in the prompt.
- **Watermark.** Like Moodle's other image providers, the image gets Moodle's
  AI watermark, and is stored in the requesting user's draft file area for the
  placement to move.
- **Refusals.** If the model's content filters block the request or the result,
  the action fails with the error `ContentFiltered` (code 400) and a fixed
  message that does not repeat the prompt.
- **Timeout.** Image calls wait up to 300 seconds for Bedrock; other calls
  keep the 120 second limit.

## For developers

Actions are run through Moodle's AI manager. The manager picks an enabled
provider that supports the action.

### embed_text

```php
use aiprovider_awsbr\aiactions\embed_text;

$action = new embed_text(
    contextid: $context->id,
    userid: $USER->id,
    prompttext: 'Text to embed',
);
$response = \core\di::get(\core_ai\manager::class)->process_action($action);

if ($response->get_success()) {
    $vector = $response->get_vector(); // array of floats.
    $dim    = $response->get_dim();
    $tokens = $response->get_tokens();
} else {
    debugging($response->get_errormessage());
}
```

- The default model is `amazon.titan-embed-text-v2:0`. Titan v2 vectors have 1024
  dimensions by default; the action setting can reduce them to 256 or 512.
  Titan output is normalised.
- Cohere Embed models (`cohere.embed-*`) are also supported. Text is sent as
  `search_document` and the vector size is fixed by the model. Cohere returns
  no token count, so `get_tokens()` is 0.
- Embedding models are called with `InvokeModel`. The `embed_text` model
  setting offers the instance's on-demand embedding models (see Models), so a
  model must be available in the instance's region.

### grade_response

```php
use aiprovider_awsbr\aiactions\grade_response;

$schema = [
    'type' => 'object',
    'properties' => [
        'score'    => ['type' => 'integer'],
        'feedback' => ['type' => 'string'],
    ],
    'required' => ['score', 'feedback'],
    'additionalProperties' => false,
];

$action = new grade_response(
    contextid: $context->id,
    userid: $USER->id,
    systemprompt: 'You are a strict marker. Use the rubric.',
    cachedprompt: $rubrictext,       // Stable prefix; cached where the SDK supports it.
    prompttext: $studentanswer,      // Changes on every call.
    outputschema: $schema,           // JSON Schema, top-level type "object".
    modelid: '',                     // '' = the action's configured model.
    maxtokens: 0,                    // 0 = the action's configured max tokens.
);
$response = \core\di::get(\core_ai\manager::class)->process_action($action);

if ($response->get_success()) {
    $result = $response->get_result(); // Decoded JSON, as an array.
    $data   = $response->get_response_data(); // Also: token counts, stopreason, id.
} else {
    $error = $response->get_error(); // Truncated, InvalidJson, ModelNotAllowed, ...
}
```

- The constructor throws a `coding_exception` if the system prompt or prompt
  text is empty, or if the schema's top-level `type` is not `object`.
- The answer uses Bedrock's native structured output (`json_schema`). The
  plugin does not validate the result against your schema. Do that in your
  code.
- Limit on schemas: `minItems` and `maxItems` accept only 0 or 1. The plugin
  does not rewrite the schema, so enforce larger item counts yourself.
- `cachedprompt` is put first in the user message and `systemprompt` and
  `cachedprompt` are marked as prompt-cache points. With the older AWS SDK in
  Moodle 5.0 cache points are not supported and are omitted, so calls work but
  are not cached.
- Temperature is sent as 0 for models that accept it.
- Model override: pass `modelid` to use a model other than the configured one.
  It must be in the instance's available model set (live list, narrowed by the
  textarea) or be the action's configured model. Otherwise nothing is sent to
  AWS and the call fails with error `ModelNotAllowed` (code 400).
- Errors specific to this action:

| Error | Code | Meaning |
| --- | --- | --- |
| `Truncated` | 422 | The model reached the token limit before finishing. Raise `maxtokens`. |
| `InvalidJson` | 422 | The reply was not a JSON document. |
| `ModelNotAllowed` | 400 | The requested model is not available on this instance. |

Other failures use the AWS error name (`AccessDeniedException`,
`ValidationException`, `ThrottlingException`, ...) with an HTTP-style code.

### Streaming

Streaming bypasses the AI manager. Build the processor yourself with a provider
instance and a core `generate_text` action:

```php
use core_ai\aiactions\generate_text;

$manager   = \core\di::get(\core_ai\manager::class);
$providers = $manager->get_providers_for_actions([generate_text::class], true);
$provider  = null;
foreach ($providers[generate_text::class] as $candidate) {
    if ($candidate instanceof \aiprovider_awsbr\provider) {
        $provider = $candidate;
        break;
    }
}

$action    = new generate_text($context->id, $USER->id, 'Tell me a story.');
$processor = new \aiprovider_awsbr\process_generate_text_stream($provider, $action);

foreach ($processor->stream() as $event) {
    switch ($event['type']) {
        case 'text_delta':
            echo $event['text'];
            break;
        case 'usage':
            // $event['tokens_in'], $event['tokens_out'].
            break;
        case 'finish':
            // $event['stop_reason'].
            break;
        case 'error':
            // $event['message'], $event['code'].
            break;
    }
}
```

`stream()` is a generator. It yields these events:

| Event | Keys |
| --- | --- |
| text_delta | `type`, `text` |
| usage | `type`, `tokens_in`, `tokens_out` (only if Bedrock reports usage) |
| finish | `type`, `stop_reason` |
| error | `type`, `message`, `code` (the stream ends after an error) |

On throttling the stream is retried once in the fallback region, if one is set.
Streaming calls are not recorded in Moodle's AI action log, and rate limits are
not applied.

### Image input

The processor can send one PNG or JPEG with the prompt. Core's `generate_text`
action has no image field, so this only works in code that builds the processor
itself, with an action object that has an `imagepath` property:

```php
$action = new class ($context->id, $USER->id, 'Describe this image.', $path)
    extends \core_ai\aiactions\generate_text {
    public function __construct(int $contextid, int $userid, string $prompttext, protected string $imagepath) {
        parent::__construct($contextid, $userid, $prompttext);
    }
};
$processor = new \aiprovider_awsbr\process_generate_text($provider, $action);
$result = $processor->process();
```

Only PNG and JPEG are sent, up to 3.75 MB and 8000 px on either side. A file
that does not meet this is skipped without an error and the request goes out as
text only.

### Speech helpers

`\aiprovider_awsbr\speech` wraps Polly, Transcribe and S3 using the instance's
speech settings. These are a developer API only; they are not core AI actions
and do not appear in the AI action settings. Every method returns an array with
`success`; on failure it also has `errorcode` and `errormessage`.

```php
$speech = new \aiprovider_awsbr\speech($provider);

$out = $speech->synthesize('Hello, world.');             // ['audio' => binary, 'contenttype' => ...]
$out = $speech->start_long_synthesis($text, 'tts/job1/'); // ['taskid', 'outputuri', 'status']
$out = $speech->get_synthesis_task($taskid);

$speech->put_object_file('media/lecture.mp4', $storedfileorpath, 'video/mp4');
$out = $speech->start_transcription('job-1', 's3://bucket/media/lecture.mp4', ['languagecode' => 'en-US']);
$out = $speech->get_transcription('job-1');               // ['status', 'transcripturi', ...]
$voices = $speech->list_voices('en-US', 'generative'); // ['voices' => [id => name]]
```

| Method | Purpose |
| --- | --- |
| `synthesize($text, $opts)` | Short text to audio with Polly. Options: `voiceid`, `engine`, `languagecode`, `outputformat` (default mp3). |
| `start_long_synthesis`, `get_synthesis_task` | Polly asynchronous task; output goes to the speech bucket. |
| `start_transcription`, `get_transcription` | Transcribe job on an S3 object. Options include `languagecode`, `mediaformat`, `outputkey`, `subtitles` (VTT). |
| `put_object`, `put_object_file`, `get_object`, `delete_object`, `presigned_get_url` | S3 helpers. `put_object_file` streams large files and uses multipart upload from 16 MB. |
| `list_voices($languagecode, $engine)` | Polly voices for a language and engine. |
| `bucket()` | The configured bucket name. |

## Privacy

Data sent to AWS: the prompt text (including the system instruction and any
context the calling feature adds), the description of an image to generate, any
attached image, and the model's generated reply or image travel to Amazon Bedrock in the instance's region (image requests: the image generation region, if set). Cross-Region
inference profiles may process requests in other AWS Regions. Speech helpers
send text, audio and media files to Polly, Transcribe and your S3 bucket. Review
AWS's data handling terms for the services and regions you use.

Data stored in Moodle by this plugin:

- `aiprovider_awsbr_grade_resp` (one row per `grade_response` call): model,
  token counts (including cache reads and writes), stop reason, AWS request ID,
  a SHA-256 hash of the prompt, and the time. The prompt text and the
  model's answer are not stored.
- `generate_image` calls (the description, size and quality) are stored by
  Moodle's own AI subsystem, which has its own privacy provider; the generated
  image is a file in the user's draft area. This plugin stores nothing extra.
- `aiprovider_awsbr_embed_text` (one row per `embed_text` call): the embedded
  text (first 4000 characters), vector size, token count, model and AWS
  request ID. The vector itself is not stored.
- The core text actions are logged by Moodle's own AI subsystem, which has its
  own privacy provider.

The plugin's privacy provider declares the data sent to AWS and declares and
exports/deletes the two tables above. Temporary assumed-role credentials and the
model list are kept in Moodle's application cache.

## Limitations

- Image generation is text to image only: no editing, variations or image
  input, and one image per request.
- The speech helpers are a developer API, not core AI actions. No Moodle AI
  placement uses them.
- Streaming, image input and `embed_text`/`grade_response` are developer-facing:
  core AI placements do not call them.
- The plugin only calls models that Bedrock exposes through the Converse API
  (text) or `InvokeModel` (embeddings, with Titan and Cohere v3 request
  formats; images, with Nova Canvas, Titan Image Generator and Stability AI
  request formats).

## Support

Report bugs and request features at
https://github.com/SATS-Seminary/moodle-aiprovider_awsbr/issues. See
[CHANGELOG.md](CHANGELOG.md) for release notes.

## License

GNU GPL v3 or later. Copyright 2026 South African Theological Seminary.
