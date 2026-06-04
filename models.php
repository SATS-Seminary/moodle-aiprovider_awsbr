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
 * Bedrock model browser — lists the inference profiles that are actually
 * invokable for the configured provider, with a UI "Refresh from Bedrock"
 * action. The admin-UI alternative to the list_models CLI.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/ai/provider/awsbr/models.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('models_page_title', 'aiprovider_awsbr'));
$PAGE->set_heading(get_string('models_page_heading', 'aiprovider_awsbr'));

$catalog = \aiprovider_awsbr\model_catalog::discover();

// Refresh action — re-query Bedrock and repopulate the cache.
if (optional_param('refresh', 0, PARAM_BOOL) && confirm_sesskey()) {
    $count = 0;
    if ($catalog !== null) {
        try {
            $count = count($catalog->list(true));
        } catch (\Throwable $e) {
            debugging('awsbr models: refresh failed — ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
    redirect(
        $PAGE->url,
        get_string('models_refreshed', 'aiprovider_awsbr', $count),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('models_page_heading', 'aiprovider_awsbr'));

if ($catalog === null) {
    echo $OUTPUT->notification(get_string('models_no_provider', 'aiprovider_awsbr'), 'notifyproblem');
    echo $OUTPUT->footer();
    die;
}

echo html_writer::tag('p', get_string('models_intro', 'aiprovider_awsbr'));

// Refresh button.
$refreshurl = new moodle_url($PAGE->url, ['refresh' => 1, 'sesskey' => sesskey()]);
echo html_writer::div(
    $OUTPUT->single_button($refreshurl, get_string('models_refresh', 'aiprovider_awsbr'), 'get'),
    'mb-3'
);

$models = $catalog->list();

if (empty($models)) {
    echo $OUTPUT->notification(get_string('models_empty', 'aiprovider_awsbr'), 'notifywarning');
    echo $OUTPUT->footer();
    die;
}

$table = new html_table();
$table->head = [
    get_string('models_col_id', 'aiprovider_awsbr'),
    get_string('models_col_status', 'aiprovider_awsbr'),
    get_string('models_col_name', 'aiprovider_awsbr'),
];
$table->attributes['class'] = 'generaltable';
foreach ($models as $m) {
    $table->data[] = [
        html_writer::tag('code', s($m['id'])),
        s($m['status']),
        s($m['name']),
    ];
}
echo html_writer::table($table);
echo html_writer::tag('p', get_string('models_count', 'aiprovider_awsbr', count($models)), ['class' => 'text-muted']);

echo $OUTPUT->footer();
