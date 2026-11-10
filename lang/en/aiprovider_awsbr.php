<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Language strings for aiprovider_awsbr.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AWS Bedrock AI Provider';

// Settings.
$string['authmode'] = 'Authentication mode';
$string['authmode_help'] = 'Choose how this plugin authenticates to AWS Bedrock. "Bedrock API key" is the simplest and uses a bearer token from the Bedrock console. "Instance role" is recommended for production EC2/ECS deployments. "Static access key + secret" uses classic IAM credentials.';
$string['authmode_api_key'] = 'Bedrock API key (bearer token)';
$string['authmode_static'] = 'Static access key + secret';
$string['authmode_instance_role'] = 'IAM instance role';
$string['authmode_assume_role'] = 'Assume STS role';
$string['apikey'] = 'Bedrock API key';
$string['apikey_help'] = 'A long-term Bedrock API key (bearer token) generated from the AWS Bedrock console. Typically starts with "ABSK". Moodle saves provider settings as plain text in the database, so the key is not encrypted at rest; anyone with access to the database or a backup can read it. To avoid storing a key, use the IAM instance role or Assume STS role mode instead. The plugin sends this as an Authorization: Bearer header on every Bedrock request.';
$string['accesskeyid'] = 'AWS access key ID';
$string['secretaccesskey'] = 'AWS secret access key';
$string['assumerolearn'] = 'STS role ARN to assume';
$string['assumerolesessionname'] = 'STS session name';
$string['region'] = 'AWS region';
$string['region_help'] = 'The AWS region whose Bedrock endpoint receives requests, for example us-east-1 or eu-west-1. Models and inference profiles differ per region. Cross-Region inference profiles may process requests in other AWS regions.';
$string['fallbackregion'] = 'Fallback region';
$string['fallbackregion_help'] = 'Optional. If a request is throttled in the primary region, retry once against this region.';
$string['models_page_title'] = 'Bedrock models';
$string['models_page_heading'] = 'Available Bedrock models';
$string['models_intro'] = 'These are the inference profiles that are actually invokable for the selected provider instance (its region and credentials). Use any of these IDs as a model in Configure actions or as a prompt model preference. The list is cached; use Refresh to re-query Bedrock after provisioning new models.';
$string['models_refresh'] = 'Refresh from Bedrock';
$string['models_refreshed'] = 'Refreshed the Bedrock model list — {$a} invokable model(s) available.';
$string['models_browse_link'] = 'Browse / refresh available models';
$string['models_browse_desc'] = 'Optional. The model dropdowns auto-populate from this live list; fill the box above only to curate a subset or set custom labels.';
$string['models_col_id'] = 'Inference profile ID';
$string['models_col_status'] = 'Status';
$string['models_col_name'] = 'Name';
$string['models_count'] = '{$a} invokable model(s).';
$string['models_empty'] = 'No inference profiles were returned. Check credentials, region, and the bedrock:ListInferenceProfiles permission.';
$string['models_no_provider'] = 'No enabled AWS Bedrock provider was found.';
$string['availablemodels'] = 'Available inference profile IDs (optional)';
$string['availablemodels_help'] = 'Optional. Model dropdowns now auto-populate from the live list of invokable Bedrock inference profiles for your configured region, so you no longer need to maintain this by hand. Leave it blank to offer every available profile. To restrict the choices to a curated subset — and/or give them friendlier labels — list them here, one per line, format: profile_id|Display label. Entries that are not actually invokable are ignored, so a stale ID can never reach a dropdown.';
$string['enableuserratelimit'] = 'Enable per-user rate limit';
$string['userratelimit'] = 'Per-user request limit (per hour)';
$string['enableglobalratelimit'] = 'Enable global rate limit';
$string['globalratelimit'] = 'Global request limit (per hour)';

// Privacy provider metadata.
$string['privacy:metadata:aws_bedrock'] = 'Requests to AWS Bedrock are sent for AI inference. Cross-Region inference profiles may process requests in other AWS regions than the one configured.';
$string['privacy:metadata:aws_bedrock:prompt'] = 'The prompt text (including any system instruction and assembled user context), and the description and negative description of an image to generate.';
$string['privacy:metadata:aws_bedrock:image'] = 'Image content when attached for multimodal inference.';
$string['privacy:metadata:aws_bedrock:response'] = 'Generated text returned by the AI model, and the generated image. Generated images are stored in the requesting user\'s draft file area and are covered by Moodle\'s own AI privacy provider and file storage.';
$string['privacy:metadata:aws_speech'] = 'Text, audio and media are sent to Amazon Polly (text to speech), Amazon Transcribe (speech to text) and Amazon S3 (working storage) when a calling plugin uses the speech services. They run in the configured speech region.';
$string['privacy:metadata:aws_speech:text'] = 'Text to be spoken, and the transcripts returned.';
$string['privacy:metadata:aws_speech:audio'] = 'Audio or video files to be transcribed, and the generated audio.';
$string['privacy:metadata:aiprovider_awsbr_embed_text'] = 'One row for each embed_text request. It is linked to a user and a context through the Moodle AI action register.';
$string['privacy:metadata:aiprovider_awsbr_embed_text:prompt'] = 'The text that was embedded (the first 4000 characters).';
$string['privacy:metadata:aiprovider_awsbr_embed_text:vectordim'] = 'The number of dimensions of the resulting vector.';
$string['privacy:metadata:aiprovider_awsbr_embed_text:prompttokens'] = 'The number of tokens billed for the request.';
$string['privacy:metadata:aiprovider_awsbr_embed_text:model'] = 'The embedding model that was used.';
$string['privacy:metadata:aiprovider_awsbr_embed_text:responseid'] = 'The AWS request ID of the call.';
$string['privacy:metadata:aiprovider_awsbr_embed_text:fingerprint'] = 'The model fingerprint or version marker.';
$string['privacy:metadata:aiprovider_awsbr_grade_resp'] = 'One row for each grade_response request: token counts and a hash of the prompt, never the prompt text. It is linked to a user and a context through the Moodle AI action register.';
$string['privacy:metadata:aiprovider_awsbr_grade_resp:model'] = 'The model that was used.';
$string['privacy:metadata:aiprovider_awsbr_grade_resp:prompttokens'] = 'The number of input tokens.';
$string['privacy:metadata:aiprovider_awsbr_grade_resp:completiontokens'] = 'The number of output tokens.';
$string['privacy:metadata:aiprovider_awsbr_grade_resp:cachereadtokens'] = 'The number of input tokens read from the prompt cache.';
$string['privacy:metadata:aiprovider_awsbr_grade_resp:cachewritetokens'] = 'The number of input tokens written to the prompt cache.';
$string['privacy:metadata:aiprovider_awsbr_grade_resp:stopreason'] = 'Why the model stopped generating.';
$string['privacy:metadata:aiprovider_awsbr_grade_resp:responseid'] = 'The AWS request ID of the call.';
$string['privacy:metadata:aiprovider_awsbr_grade_resp:prompthash'] = 'A SHA-256 hash of the prompts, which cannot be turned back into text.';
$string['privacy:metadata:aiprovider_awsbr_grade_resp:timecreated'] = 'The time the row was created.';

// Actions.
$string['action_generate_text'] = 'Generate text';
$string['action_summarise_text'] = 'Summarise text';
$string['action_explain_text'] = 'Explain text';
$string['action_embed_text'] = 'Embed text (for retrieval / similarity search)';
$string['action_embed_text_desc'] = 'Convert text into a dense vector (1024-dim for Amazon Titan Text Embeddings v2; Cohere Embed v3 models are also supported) for retrieval and similarity search. Embedding is non-generative and cheap. Embedding models are not offered in every AWS region, so a second provider instance in another region can serve this action.';
$string['action_grade_response'] = 'Grade response (structured output)';
$string['action_grade_response_desc'] = 'Ask a model to answer with a JSON document that follows a JSON schema supplied by the caller (native structured output), for example to assess a student response against a rubric. The caller supplies the prompts and may override the model with one of the available models.';

// Defaults.
$string['systeminstruction_generate_text'] = 'You are a helpful assistant. Answer the user\'s question concisely and accurately.';
$string['systeminstruction_summarise_text'] = 'Summarise the following text concisely while preserving the key information.';
$string['systeminstruction_explain_text'] = 'Explain the following text in clearer, simpler terms.';

// Settings headings.
$string['credentials_heading'] = 'AWS Credentials';
$string['credentials_heading_desc'] = 'Configure how this provider authenticates to AWS Bedrock.';
$string['region_heading'] = 'AWS Region';
$string['models_heading'] = 'Inference Models';
$string['speech_heading'] = 'Speech (Polly / Transcribe)';
$string['speech_heading_desc'] = 'Optional text-to-speech (Amazon Polly) and speech-to-text (Amazon Transcribe). These are separate AWS services, not Bedrock: they require IAM credentials (above), NOT a Bedrock API key, and an S3 bucket for working storage. Leave blank if unused.';
$string['speechregion'] = 'Speech region';
$string['speechregion_help'] = 'AWS region for Polly/Transcribe. Set separately from the Bedrock region because Polly\'s high-quality GENERATIVE engine is only in some regions (it is not offered in every region). The IAM profile must grant Polly/Transcribe in this region.';
$string['speechs3bucket'] = 'Speech S3 bucket';
$string['speechs3bucket_help'] = 'An S3 bucket (in the speech region) for source media, transcripts and generated audio. The IAM profile needs s3:PutObject/GetObject on it; keep it private.';
$string['speechengine'] = 'Polly engine';
$string['speechengine_help'] = 'Synthesis engine. Generative is the highest quality and is recommended where available.';
$string['speechengine:generative'] = 'Generative (best quality)';
$string['speechengine:neural'] = 'Neural';
$string['speechengine:standard'] = 'Standard';
$string['speechvoice'] = 'Default Polly voice';
$string['speechlanguage'] = 'Speech language (optional)';
$string['speechlanguage_help'] = 'Optional language code for synthesis, for example en-US. Leave it blank to let Polly use the language of the voice. Set it for a bilingual voice, or when a caller passes no language of its own.';
$string['speechvoice_help'] = 'Default Polly voice ID for synthesis, for example Ruth (an en-US generative voice). Must support the selected engine in the speech region. The voice sets the spoken language unless a language is set below or passed by the caller.';
$string['ratelimit_heading'] = 'Rate Limiting';

// Credentials detail strings.
$string['accesskeyid_desc'] = 'Your AWS IAM access key ID. Only required when using "Static access key + secret" authentication.';
$string['secretaccesskey_desc'] = 'Your AWS IAM secret access key. Moodle saves it as plain text in the database (not encrypted at rest); prefer the IAM instance role or Assume STS role mode where you can. Only required for static key authentication.';
$string['assumerolearn_desc'] = 'The full ARN of the IAM role to assume via STS, e.g. arn:aws:iam::123456789012:role/MoodleBedrock.';
$string['assumerolesessionname_desc'] = 'The STS session name used when assuming the role. Visible in CloudTrail logs.';

// Rate limit detail strings.
$string['enableuserratelimit_desc'] = 'Limit the number of requests each user can make to the Bedrock provider every hour.';
$string['userratelimit_desc'] = 'Maximum number of requests per user per hour. Only applied when the per-user limit is enabled.';
$string['enableglobalratelimit_desc'] = 'Limit the total number of requests across all users every hour.';
$string['globalratelimit_desc'] = 'Maximum number of site-wide requests per hour. Only applied when the global limit is enabled.';

// Per-action form strings (generate_text, summarise_text, explain_text share the same form).
$string['action:generate_text:model'] = 'Model';
$string['action:generate_text:model_help'] = 'Select the Bedrock inference profile to use for this action. The list comes from this provider instance: the inference profiles available in its region, narrowed by its Available inference profile IDs setting if that is filled in.';
$string['action:generate_text:model_none'] = '— No models configured —';
$string['action:generate_text:systeminstruction'] = 'System instruction';
$string['action:generate_text:systeminstruction_help'] = 'This instruction is sent to the AI model along with the user\'s prompt. Editing this instruction is not recommended unless absolutely required.';
$string['action:generate_text:maxtokens'] = 'Max tokens';
$string['action:generate_text:maxtokens_help'] = 'Maximum number of tokens the model may generate in a single response. Higher values allow longer outputs but cost more.';
$string['action:generate_text:temperature'] = 'Temperature';
$string['action:generate_text:temperature_help'] = 'Controls randomness. 0.0 = deterministic; 1.0 = most varied. Recommended: 0.5–0.8 for most use cases. Not sent to Claude models after the 4.6 generation (Opus 4.7+, Sonnet 5+, Haiku 5.5, Fable), which do not accept it.';
$string['action:generate_text:topp'] = 'Top-P';
$string['action:generate_text:topp_help'] = 'Nucleus sampling threshold. 1.0 = consider all tokens. Lower values concentrate the distribution. Usually leave at 1.0 if adjusting temperature. Not sent to Claude models after the 4.6 generation (Opus 4.7+, Sonnet 5+, Haiku 5.5, Fable), which do not accept it.';

$string['action:summarise_text:model'] = 'Model';
$string['action:summarise_text:model_help'] = 'Select the Bedrock inference profile to use for this action.';
$string['action:summarise_text:model_none'] = '— No models configured —';
$string['action:summarise_text:systeminstruction'] = 'System instruction';
$string['action:summarise_text:systeminstruction_help'] = 'This instruction is sent to the AI model along with the user\'s prompt. Editing this instruction is not recommended unless absolutely required.';
$string['action:summarise_text:maxtokens'] = 'Max tokens';
$string['action:summarise_text:maxtokens_help'] = 'Maximum number of tokens the model may generate in a single response.';
$string['action:summarise_text:temperature'] = 'Temperature';
$string['action:summarise_text:temperature_help'] = 'Controls randomness. 0.0 = deterministic; 1.0 = most varied. Not sent to Claude models after the 4.6 generation (Opus 4.7+, Sonnet 5+, Haiku 5.5, Fable), which do not accept it.';
$string['action:summarise_text:topp'] = 'Top-P';
$string['action:summarise_text:topp_help'] = 'Nucleus sampling threshold. Not sent to Claude models after the 4.6 generation (Opus 4.7+, Sonnet 5+, Haiku 5.5, Fable), which do not accept it.';

$string['action:explain_text:model'] = 'Model';
$string['action:explain_text:model_help'] = 'Select the Bedrock inference profile to use for this action.';
$string['action:explain_text:model_none'] = '— No models configured —';
$string['action:explain_text:systeminstruction'] = 'System instruction';
$string['action:explain_text:systeminstruction_help'] = 'This instruction is sent to the AI model along with the user\'s prompt. Editing this instruction is not recommended unless absolutely required.';
$string['action:explain_text:maxtokens'] = 'Max tokens';
$string['action:explain_text:maxtokens_help'] = 'Maximum number of tokens the model may generate in a single response.';
$string['action:explain_text:temperature'] = 'Temperature';
$string['action:explain_text:temperature_help'] = 'Controls randomness. 0.0 = deterministic; 1.0 = most varied. Not sent to Claude models after the 4.6 generation (Opus 4.7+, Sonnet 5+, Haiku 5.5, Fable), which do not accept it.';
$string['action:explain_text:topp'] = 'Top-P';
$string['action:explain_text:topp_help'] = 'Nucleus sampling threshold. Not sent to Claude models after the 4.6 generation (Opus 4.7+, Sonnet 5+, Haiku 5.5, Fable), which do not accept it.';
$string['effort'] = 'Effort';
$string['effort_default'] = 'Model default';
$string['effort_help'] = 'How much reasoning the model does before answering. Lower effort uses fewer (billed) output tokens and answers faster; higher effort helps with hard problems. Applies to Claude 4.6 and later models; ignored for other models. Model default leaves it to the model (medium on Claude Opus 5.5 and Haiku 5.5, high on most others).';
$string['effort_high'] = 'High';
$string['effort_low'] = 'Low';
$string['effort_medium'] = 'Medium';

// Embed text strings.
$string['action:embed_text:model'] = 'Embedding model';
$string['action:embed_text:model_help'] = 'Select the Bedrock embedding model to use. The default is Amazon Titan Text Embeddings v2 (1024-dim), which is the cheapest option and supports multilingual text. Cohere Embed Multilingual v3 offers higher retrieval quality at approximately 5× the cost.';
$string['action:embed_text:model_default'] = 'Amazon Titan Text Embeddings v2 (1024-dim)';
$string['action:embed_text:model_none'] = '— No models configured —';
$string['action:embed_text:dimensions'] = 'Vector dimensions (Titan v2 only)';
$string['action:embed_text:dimensions_help'] = 'Amazon Titan v2 supports configurable output dimensions: 256, 512, or 1024. Lower dimensions reduce storage and cosine-search cost at a small quality penalty. Leave at the default (1024) unless you have measured the trade-off. This setting has no effect on Cohere models.';
$string['action:embed_text:dimensions_default'] = '— Model default (1024) —';

// Strings for the grade_response action.
$string['action:grade_response:model'] = 'Default model';
$string['action:grade_response:model_default'] = '— First available model —';
$string['action:grade_response:model_help'] = 'The Bedrock inference profile used when the caller does not choose one. A caller may only override it with a model that is listed in the available models.';
$string['action:grade_response:maxtokens'] = 'Max tokens';
$string['action:grade_response:maxtokens_help'] = 'Maximum number of tokens the model may generate, used when the caller does not set a limit. The structured answer is cut off, and the call fails, if it needs more.';

// Strings for the generate_image action.
$string['action:generate_image:model'] = 'Image model';
$string['action:generate_image:model_default'] = 'Amazon Nova Canvas';
$string['action:generate_image:model_help'] = 'The Bedrock image model used to generate images. The list comes from this provider instance: the on-demand image models of its region that this plugin can call (Amazon Nova Canvas, Amazon Titan Image Generator and Stability AI text-to-image models), narrowed by its Available inference profile IDs setting if that has lines for image models. Image models are not offered in every AWS region, and each needs model access in the AWS account. Quality and aspect ratio are chosen by the person generating the image; the style option is ignored because Bedrock has no equivalent.';
$string['action:generate_image:negativeprompt'] = 'Negative prompt';
$string['action:generate_image:negativeprompt_help'] = 'Optional. Describes what the images must not show, for example "text, watermark, blurry". Use plain terms of the things to avoid, not negations such as "no text". Sent with every request. Up to 512 characters.';
$string['action:generate_image:negativeprompt_toolong'] = 'The negative prompt can be at most {$a} characters long.';
$string['action:generate_image:cfgscale'] = 'CFG scale';
$string['action:generate_image:cfgscale_help'] = 'Optional. How strictly Amazon Nova Canvas and Amazon Titan follow the prompt, from 1.1 (more freedom) to 10 (strict). Leave it blank to use the model\'s default. It has no effect on Stability AI models.';
$string['action:generate_image:cfgscale_invalid'] = 'Enter a number from 1.1 to 10, or leave the field blank.';

// Error strings (used by process_generate_image).
$string['error:generate_image:content_filtered'] = 'The image could not be generated because the request or the result was blocked by the content filters of the image model. Try a different description.';
$string['error:generate_image:empty'] = 'The image model did not return an image.';
$string['error:generate_image:inference'] = 'The image model failed to generate an image. Try again.';
$string['error:generate_image:invalid_image'] = 'The image model returned data that is not a valid image.';
$string['error:generate_image:invalid_json'] = 'The image model returned a response that could not be read.';
$string['error:generate_image:no_model'] = 'No image generation model is available on this provider.';
$string['error:generate_image:no_user'] = 'Images can only be generated for a logged-in user.';
$string['error:generate_image:throttled'] = 'The image request was throttled in all configured regions.';
$string['error:generate_image:unsupported_model'] = 'The model {$a} is not a supported image generation model.';

// Error strings (used by process_embed_text).
$string['error:embed_text:empty_vector'] = 'Bedrock returned an empty or malformed embedding vector.';
$string['error:embed_text:invalid_json'] = 'Bedrock InvokeModel returned a non-JSON response body.';
$string['error:embed_text:throttled'] = 'The embedding request was throttled in all configured regions.';

// Hook listener strings.
$string['proxy_note_heading'] = 'HTTP Proxy & Certificates';
$string['proxy_note_body'] = 'Bedrock requests inherit Moodle\'s main HTTP proxy and CA settings from Site administration → Server → HTTP.';

// Help strings for fields injected into the provider-instance form (required by addHelpButton).
$string['accesskeyid_help'] = 'Your AWS IAM access key ID. Only required when authentication mode is "Static access key + secret".';
$string['secretaccesskey_help'] = 'Your AWS IAM secret access key. Moodle saves provider settings as plain text in the database, so the secret is not encrypted at rest; anyone with access to the database or a backup can read it. To avoid storing a secret, use the IAM instance role or Assume STS role mode. Only required for static key authentication.';
$string['assumerolearn_help'] = 'Full ARN of the IAM role to assume via STS. Example: arn:aws:iam::123456789012:role/MoodleBedrock.';
