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

namespace aiprovider_awsbr\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

/**
 * Tests for the aiprovider_awsbr privacy provider.
 *
 * @package    aiprovider_awsbr
 * @copyright  2026 South African Theological Seminary (ict@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\aiprovider_awsbr\privacy\provider::class)]
final class provider_test extends provider_testcase {
    /**
     * Add one of the plugin's rows and the core register row that links it to a user.
     *
     * @param string $actionname The action name: embed_text or grade_response.
     * @param int $userid The user the action was run for.
     * @param \context $context The context the action was run in.
     * @param string $provider The provider name stored in the register row.
     * @return int The id of the plugin's row.
     */
    private function add_action(
        string $actionname,
        int $userid,
        \context $context,
        string $provider = 'aiprovider_awsbr',
    ): int {
        global $DB;
        if ($actionname === 'embed_text') {
            $id = $DB->insert_record('aiprovider_awsbr_embed_text', (object) [
                'prompt' => 'Secret text of user ' . $userid, 'vectordim' => 1024, 'prompttokens' => 12,
                'model' => 'amazon.titan-embed-text-v2:0', 'responseid' => 'req-' . $userid, 'fingerprint' => 'fp',
            ]);
        } else {
            $id = $DB->insert_record('aiprovider_awsbr_grade_resp', (object) [
                'model' => 'global.anthropic.claude-sonnet-4-5', 'prompttokens' => 100, 'completiontokens' => 50,
                'cachereadtokens' => 10, 'cachewritetokens' => 5, 'stopreason' => 'end_turn',
                'responseid' => 'req-' . $userid, 'prompthash' => str_repeat('a', 64), 'timecreated' => time(),
            ]);
        }
        $DB->insert_record('ai_action_register', (object) [
            'actionname' => $actionname, 'actionid' => $id, 'success' => 1, 'userid' => $userid,
            'contextid' => $context->id, 'provider' => $provider, 'errorcode' => null, 'error' => null,
            'errormessage' => null, 'timecreated' => time(), 'timecompleted' => time(),
            'model' => 'm', 'prompttokens' => 1, 'completiontokens' => 1, 'courseid' => 0,
        ]);
        return $id;
    }

    /**
     * Two users, each with both kinds of row, in two contexts; user 1 also has one in the other context.
     *
     * @return array [user1, user2, context1, context2]
     */
    private function setup_data(): array {
        $this->resetAfterTest();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $context1 = \context_course::instance($course1->id);
        $context2 = \context_course::instance($course2->id);

        $this->add_action('embed_text', $user1->id, $context1);
        $this->add_action('grade_response', $user1->id, $context1);
        $this->add_action('embed_text', $user1->id, $context2);
        $this->add_action('embed_text', $user2->id, $context1);
        $this->add_action('grade_response', $user2->id, $context1);
        return [$user1, $user2, $context1, $context2];
    }

    /**
     * Count the plugin's rows.
     *
     * @return int[] Number of embed_text rows, number of grade_response rows.
     */
    private function counts(): array {
        global $DB;
        return [$DB->count_records('aiprovider_awsbr_embed_text'), $DB->count_records('aiprovider_awsbr_grade_resp')];
    }

    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('aiprovider_awsbr'));
        $items = $collection->get_collection();

        $names = array_map(static fn($item) => $item->get_name(), $items);
        $this->assertEqualsCanonicalizing(
            ['aiprovider_awsbr_embed_text', 'aiprovider_awsbr_grade_resp', 'aws_bedrock', 'aws_speech'],
            $names
        );
        foreach ($items as $item) {
            $this->assertNotEmpty(get_string($item->get_summary(), 'aiprovider_awsbr'));
            foreach ($item->get_privacy_fields() as $field => $stringid) {
                $this->assertNotEmpty(get_string($stringid, 'aiprovider_awsbr'), $field);
            }
        }
        // Every column of both tables is described.
        global $DB;
        foreach ($items as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\database_table) {
                $columns = array_diff(array_keys($DB->get_columns($item->get_name())), ['id']);
                $this->assertEqualsCanonicalizing($columns, array_keys($item->get_privacy_fields()), $item->get_name());
            }
        }
    }

    public function test_get_contexts_for_userid(): void {
        [$user1, $user2, $context1, $context2] = $this->setup_data();
        $stranger = $this->getDataGenerator()->create_user();

        $this->assertEqualsCanonicalizing(
            [$context1->id, $context2->id],
            provider::get_contexts_for_userid($user1->id)->get_contextids()
        );
        $this->assertEquals([$context1->id], provider::get_contexts_for_userid($user2->id)->get_contextids());
        $this->assertSame([], provider::get_contexts_for_userid($stranger->id)->get_contextids());
    }

    public function test_rows_of_another_provider_are_ignored(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_course::instance($this->getDataGenerator()->create_course()->id);
        // A register row with the same action name but from another provider plugin.
        $this->add_action('embed_text', $user->id, $context, 'aiprovider_other');

        $this->assertSame([], provider::get_contexts_for_userid($user->id)->get_contextids());
        $userlist = new userlist($context, 'aiprovider_awsbr');
        provider::get_users_in_context($userlist);
        $this->assertSame([], $userlist->get_userids());

        provider::delete_data_for_all_users_in_context($context);
        $this->assertSame([1, 0], $this->counts());
    }

    public function test_get_users_in_context(): void {
        [$user1, $user2, $context1, $context2] = $this->setup_data();

        $userlist = new userlist($context1, 'aiprovider_awsbr');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$user1->id, $user2->id], $userlist->get_userids());

        $userlist = new userlist($context2, 'aiprovider_awsbr');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$user1->id], $userlist->get_userids());
    }

    public function test_export_user_data(): void {
        [$user1, $user2, $context1, $context2] = $this->setup_data();

        $contextlist = new approved_contextlist($user1, 'aiprovider_awsbr', [$context1->id]);
        provider::export_user_data($contextlist);

        $writer = writer::with_context($context1);
        $this->assertTrue($writer->has_any_data());
        $embed = $writer->get_data([get_string('pluginname', 'aiprovider_awsbr'), 'embed_text',
            $this->first_row_id('aiprovider_awsbr_embed_text', $user1->id, $context1)]);
        $this->assertSame('Secret text of user ' . $user1->id, $embed->prompt);
        $this->assertSame('amazon.titan-embed-text-v2:0', $embed->model);
        $grade = $writer->get_data([get_string('pluginname', 'aiprovider_awsbr'), 'grade_response',
            $this->first_row_id('aiprovider_awsbr_grade_resp', $user1->id, $context1)]);
        $this->assertSame(100, (int) $grade->prompttokens);
        $this->assertSame(str_repeat('a', 64), $grade->prompthash);

        // Nothing for the other context (not approved), nor for the other user.
        $this->assertFalse(writer::with_context($context2)->has_any_data());
        $serialised = json_encode($writer->get_data([get_string('pluginname', 'aiprovider_awsbr'), 'embed_text',
            $this->first_row_id('aiprovider_awsbr_embed_text', $user2->id, $context1)]));
        $this->assertStringNotContainsString('user ' . $user2->id, (string) $serialised);
    }

    /**
     * The id of a user's plugin row of an action in a context.
     *
     * @param string $table The plugin table.
     * @param int $userid The user.
     * @param \context $context The context.
     * @return int
     */
    private function first_row_id(string $table, int $userid, \context $context): int {
        global $DB;
        $actionname = $table === 'aiprovider_awsbr_embed_text' ? 'embed_text' : 'grade_response';
        return (int) $DB->get_field('ai_action_register', 'actionid', [
            'actionname' => $actionname, 'userid' => $userid, 'contextid' => $context->id,
        ], IGNORE_MULTIPLE);
    }

    public function test_delete_data_for_all_users_in_context(): void {
        [$user1, $user2, $context1, $context2] = $this->setup_data();

        provider::delete_data_for_all_users_in_context($context1);

        // Only the embed_text row of user 1 in context 2 is left.
        $this->assertSame([1, 0], $this->counts());
        $this->assertEquals([$context2->id], provider::get_contexts_for_userid($user1->id)->get_contextids());
        $this->assertSame([], provider::get_contexts_for_userid($user2->id)->get_contextids());
    }

    public function test_delete_data_for_user(): void {
        global $DB;
        [$user1, $user2, $context1, $context2] = $this->setup_data();

        provider::delete_data_for_user(new approved_contextlist($user1, 'aiprovider_awsbr', [$context1->id]));

        // User 1 lost only the context 1 rows; user 2 and the context 2 row are untouched.
        $this->assertSame([2, 1], $this->counts());
        $this->assertEquals([$context2->id], provider::get_contexts_for_userid($user1->id)->get_contextids());
        $this->assertEquals([$context1->id], provider::get_contexts_for_userid($user2->id)->get_contextids());
        // The register rows belong to core and are not touched.
        $this->assertSame(5, $DB->count_records('ai_action_register'));
    }

    public function test_delete_data_for_users(): void {
        [$user1, $user2, $context1, $context2] = $this->setup_data();

        provider::delete_data_for_users(new approved_userlist($context1, 'aiprovider_awsbr', [$user2->id]));

        $this->assertSame([2, 1], $this->counts());
        $this->assertSame([], provider::get_contexts_for_userid($user2->id)->get_contextids());
        $this->assertEqualsCanonicalizing(
            [$context1->id, $context2->id],
            provider::get_contexts_for_userid($user1->id)->get_contextids()
        );
    }

    /**
     * The delete works whether core's privacy provider ran before or after ours.
     *
     * Core blanks the content of its own tables and keeps the register rows, so
     * our rows are still found after core ran.
     */
    public function test_delete_after_core_privacy_provider(): void {
        [$user1, $user2, $context1, $context2] = $this->setup_data();

        \core_ai\privacy\provider::delete_data_for_user(
            new approved_contextlist($user1, 'core_ai', [$context1->id, $context2->id])
        );
        $this->assertSame([3, 2], $this->counts(), 'core leaves the rows of this plugin alone');

        provider::delete_data_for_user(new approved_contextlist($user1, 'aiprovider_awsbr', [$context1->id, $context2->id]));
        $this->assertSame([1, 1], $this->counts());
    }
}
