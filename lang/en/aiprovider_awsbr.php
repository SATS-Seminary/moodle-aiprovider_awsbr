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
$string['apikey_help'] = 'A long-term Bedrock API key (bearer token) generated from the AWS Bedrock console. Typically starts with "ABSK". Stored encrypted at rest. Plugin sends this as an Authorization: Bearer header on every Bedrock request.';
$string['accesskeyid'] = 'AWS access key ID';
$string['secretaccesskey'] = 'AWS secret access key';
$string['assumerolearn'] = 'STS role ARN to assume';
$string['assumerolesessionname'] = 'STS session name';
$string['region'] = 'AWS region';
$string['region_help'] = 'Default af-south-1 (Cape Town). Cross-Region inference profiles route actual computation to AWS commercial regions automatically.';
$string['fallbackregion'] = 'Fallback region';
$string['fallbackregion_help'] = 'Optional. If a request is throttled in the primary region, retry once against this region.';
$string['models_page_title'] = 'Bedrock models';
$string['models_page_heading'] = 'Available Bedrock models';
$string['models_intro'] = 'These are the inference profiles that are actually invokable for the configured region. Use any of these IDs as a model in Configure actions or as a prompt model preference. The list is cached; use Refresh to re-query Bedrock after provisioning new models.';
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
$string['privacy:metadata:aws_bedrock'] = 'Requests to AWS Bedrock are sent for AI inference. Cross-Region inference may route processing to AWS commercial regions outside South Africa.';
$string['privacy:metadata:aws_bedrock:prompt'] = 'The prompt text (including any system instruction and assembled user context).';
$string['privacy:metadata:aws_bedrock:image'] = 'Image content when attached for multimodal inference.';
$string['privacy:metadata:aws_bedrock:response'] = 'Generated text returned by the AI model.';
$string['privacy:metadata:ai_action_register'] = 'Records of AI actions are stored in Moodle\'s standard AI audit table with userid, action, model, and token counts.';

// Actions.
$string['action_generate_text'] = 'Generate text';
$string['action_summarise_text'] = 'Summarise text';
$string['action_explain_text'] = 'Explain text';
$string['action_embed_text'] = 'Embed text (for retrieval / similarity search)';
$string['action_embed_text_desc'] = 'Convert text into a dense vector (1024-dim for Titan v2) used by the knowledge-base retrieval pipeline. Embedding is non-generative and cheap. Bind this action to the eu-west-1 provider instance because Amazon Titan Text Embeddings v2 is not available in af-south-1.';

// Defaults.
$string['systeminstruction_generate_text'] = 'You are a helpful assistant. Answer the user\'s question concisely and accurately.';
$string['systeminstruction_summarise_text'] = 'Summarise the following text concisely while preserving the key information.';
$string['systeminstruction_explain_text'] = 'Explain the following text in clearer, simpler terms.';

// ---- Strings added by stream A1 + A2 + A4 --------------------------------

// Settings headings.
$string['credentials_heading'] = 'AWS Credentials';
$string['credentials_heading_desc'] = 'Configure how this provider authenticates to AWS Bedrock.';
$string['region_heading'] = 'AWS Region';
$string['models_heading'] = 'Inference Models';
$string['ratelimit_heading'] = 'Rate Limiting';

// Credentials detail strings.
$string['accesskeyid_desc'] = 'Your AWS IAM access key ID. Only required when using "Static access key + secret" authentication.';
$string['secretaccesskey_desc'] = 'Your AWS IAM secret access key. Stored encrypted. Only required for static key authentication.';
$string['assumerolearn_desc'] = 'The full ARN of the IAM role to assume via STS, e.g. arn:aws:iam::123456789012:role/MoodleBedrock.';
$string['assumerolesessionname_desc'] = 'The STS session name used when assuming the role. Visible in CloudTrail logs.';

// Rate limit detail strings.
$string['enableuserratelimit_desc'] = 'Limit the number of requests each user can make to the Bedrock provider every hour.';
$string['userratelimit_desc'] = 'Maximum number of requests per user per hour. Only applied when the per-user limit is enabled.';
$string['enableglobalratelimit_desc'] = 'Limit the total number of requests across all users every hour.';
$string['globalratelimit_desc'] = 'Maximum number of site-wide requests per hour. Only applied when the global limit is enabled.';

// Per-action form strings (generate_text, summarise_text, explain_text share the same form).
$string['action:generate_text:model'] = 'Model';
$string['action:generate_text:model_help'] = 'Select the Bedrock inference profile to use for this action. Profiles are managed in the provider\'s main settings page.';
$string['action:generate_text:model_none'] = '— No models configured —';
$string['action:generate_text:systeminstruction'] = 'System instruction';
$string['action:generate_text:systeminstruction_help'] = 'This instruction is sent to the AI model along with the user\'s prompt. Editing this instruction is not recommended unless absolutely required.';
$string['action:generate_text:maxtokens'] = 'Max tokens';
$string['action:generate_text:maxtokens_help'] = 'Maximum number of tokens the model may generate in a single response. Higher values allow longer outputs but cost more.';
$string['action:generate_text:temperature'] = 'Temperature';
$string['action:generate_text:temperature_help'] = 'Controls randomness. 0.0 = deterministic; 1.0 = most varied. Recommended: 0.5–0.8 for most use cases.';
$string['action:generate_text:topp'] = 'Top-P';
$string['action:generate_text:topp_help'] = 'Nucleus sampling threshold. 1.0 = consider all tokens. Lower values concentrate the distribution. Usually leave at 1.0 if adjusting temperature.';

$string['action:summarise_text:model'] = 'Model';
$string['action:summarise_text:model_help'] = 'Select the Bedrock inference profile to use for this action.';
$string['action:summarise_text:model_none'] = '— No models configured —';
$string['action:summarise_text:systeminstruction'] = 'System instruction';
$string['action:summarise_text:systeminstruction_help'] = 'This instruction is sent to the AI model along with the user\'s prompt. Editing this instruction is not recommended unless absolutely required.';
$string['action:summarise_text:maxtokens'] = 'Max tokens';
$string['action:summarise_text:maxtokens_help'] = 'Maximum number of tokens the model may generate in a single response.';
$string['action:summarise_text:temperature'] = 'Temperature';
$string['action:summarise_text:temperature_help'] = 'Controls randomness. 0.0 = deterministic; 1.0 = most varied.';
$string['action:summarise_text:topp'] = 'Top-P';
$string['action:summarise_text:topp_help'] = 'Nucleus sampling threshold.';

$string['action:explain_text:model'] = 'Model';
$string['action:explain_text:model_help'] = 'Select the Bedrock inference profile to use for this action.';
$string['action:explain_text:model_none'] = '— No models configured —';
$string['action:explain_text:systeminstruction'] = 'System instruction';
$string['action:explain_text:systeminstruction_help'] = 'This instruction is sent to the AI model along with the user\'s prompt. Editing this instruction is not recommended unless absolutely required.';
$string['action:explain_text:maxtokens'] = 'Max tokens';
$string['action:explain_text:maxtokens_help'] = 'Maximum number of tokens the model may generate in a single response.';
$string['action:explain_text:temperature'] = 'Temperature';
$string['action:explain_text:temperature_help'] = 'Controls randomness. 0.0 = deterministic; 1.0 = most varied.';
$string['action:explain_text:topp'] = 'Top-P';
$string['action:explain_text:topp_help'] = 'Nucleus sampling threshold.';

// A3 - embed_text strings.
$string['action:embed_text:model'] = 'Embedding model';
$string['action:embed_text:model_help'] = 'Select the Bedrock embedding model to use. The default is Amazon Titan Text Embeddings v2 (1024-dim), which is the cheapest option and supports multilingual text. Cohere Embed Multilingual v3 offers higher retrieval quality at approximately 5× the cost.';
$string['action:embed_text:model_none'] = '— No models configured —';
$string['action:embed_text:dimensions'] = 'Vector dimensions (Titan v2 only)';
$string['action:embed_text:dimensions_help'] = 'Amazon Titan v2 supports configurable output dimensions: 256, 512, or 1024. Lower dimensions reduce storage and cosine-search cost at a small quality penalty. Leave at the default (1024) unless you have measured the trade-off. This setting has no effect on Cohere models.';
$string['action:embed_text:dimensions_default'] = '— Model default (1024) —';

// Error strings (used by process_embed_text).
$string['error:embed_text:empty_vector'] = 'Bedrock returned an empty or malformed embedding vector.';
$string['error:embed_text:invalid_json'] = 'Bedrock InvokeModel returned a non-JSON response body.';
$string['error:embed_text:throttled'] = 'The embedding request was throttled in all configured regions.';

// A5 - Hook listener strings.
$string['proxy_note_heading'] = 'HTTP Proxy & Certificates';
$string['proxy_note_body'] = 'Bedrock requests inherit Moodle\'s main HTTP proxy and CA settings from Site administration → Server → HTTP.';

// Help strings for fields injected into the provider-instance form (required by addHelpButton).
$string['accesskeyid_help'] = 'Your AWS IAM access key ID. Only required when authentication mode is "Static access key + secret".';
$string['secretaccesskey_help'] = 'Your AWS IAM secret access key. Stored encrypted at rest. Only required for static-key auth.';
$string['assumerolearn_help'] = 'Full ARN of the IAM role to assume via STS. Example: arn:aws:iam::123456789012:role/MoodleBedrock.';
