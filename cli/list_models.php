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
 * List the Bedrock inference profiles that are actually invokable for the
 * configured aiprovider_awsbr provider — the valid set of model IDs.
 *
 * Usage:
 *   php ai/provider/awsbr/cli/list_models.php
 *   php ai/provider/awsbr/cli/list_models.php --refresh
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'refresh' => false],
    ['h' => 'help', 'r' => 'refresh'],
);

if ($options['help']) {
    cli_writeln("List invokable Bedrock inference profiles for aiprovider_awsbr.\n");
    cli_writeln("Options:");
    cli_writeln("  -r, --refresh   Bypass the cache and re-query Bedrock.");
    cli_writeln("  -h, --help      Show this help.");
    exit(0);
}

$catalog = \aiprovider_awsbr\model_catalog::discover();
if ($catalog === null) {
    cli_error('No enabled aiprovider_awsbr provider found.');
}

$models = $catalog->list((bool) $options['refresh']);

if (empty($models)) {
    cli_writeln('No inference profiles returned (check credentials, region, and bedrock:ListInferenceProfiles permission).');
    exit(1);
}

cli_writeln(sprintf("%-52s  %-8s  %s", 'INFERENCE PROFILE ID', 'STATUS', 'NAME'));
cli_writeln(str_repeat('-', 90));
foreach ($models as $m) {
    cli_writeln(sprintf("%-52s  %-8s  %s", $m['id'], $m['status'], $m['name']));
}
cli_writeln("\n" . count($models) . ' model(s). Use one of these IDs as a prompt model preference.');
exit(0);
