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

namespace core_grades;

use grade_item;

/**
 * Tests grade_import_lib functions.
 *
 * @package   core_grades
 * @category  test
 * @copyright 2015 Adrian Greeve <adrian@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU Public License
 */
final class importlib_test extends \advanced_testcase {

    /**
     * Load required test libraries
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once("{$CFG->dirroot}/grade/import/lib.php");
    }

    /**
     * Import grades into 'grade_import_values' table. This is done differently in the various import plugins,
     * so there is no direct API to call.
     *
     * @param array $data Information to be inserted into the table.
     * @return int The insert ID of the sql statement.
     */
    private function import_grades($data) {
        global $DB, $USER;
        $graderecord = new \stdClass();
        $graderecord->importcode = $data['importcode'];
        if (isset($data['itemid'])) {
            $graderecord->itemid = $data['itemid'];
        }
        $graderecord->userid = $data['userid'];
        if (isset($data['importer'])) {
            $graderecord->importer = $data['importer'];
        } else {
            $graderecord->importer = $USER->id;
        }
        if (isset($data['finalgrade'])) {
            $graderecord->finalgrade = $data['finalgrade'];
        } else {
            $graderecord->finalgrade = rand(0, 100);
        }
        if (isset($data['feedback'])) {
            $graderecord->feedback = $data['feedback'];
        }
        if (isset($data['importonlyfeedback'])) {
            $graderecord->importonlyfeedback = $data['importonlyfeedback'];
        } else {
            $graderecord->importonlyfeedback = false;
        }
        if (isset($data['newgradeitem'])) {
            $graderecord->newgradeitem = $data['newgradeitem'];
        }
        return $DB->insert_record('grade_import_values', $graderecord);
    }

    /**
     * Tests for importing grades from an external source.
     *
     * @covers ::grade_import_commit
     */
    public function test_grade_import_commit() {
        global $USER, $DB, $CFG;
        $this->resetAfterTest();

        $importcode = get_new_importcode();
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', array('course' => $course->id));
        $itemname = $assign->name;
        $modulecontext = \context_module::instance($assign->cmid);
        // The generator returns a dummy object, lets get the real assign object.
        $assign = new \assign($modulecontext, false, false);
        $cm = $assign->get_course_module();

        // Enrol users in the course.
        $this->getDataGenerator()->enrol_user($user1->id, $course->id);
        $this->getDataGenerator()->enrol_user($user2->id, $course->id);

        // Enter a new grade into an existing grade item.
        $gradeitem = \grade_item::fetch(array('courseid' => $course->id, 'itemtype' => 'mod'));

        // Keep this value around for a test further down.
        $originalgrade = 55;
        $this->import_grades(array(
            'importcode' => $importcode,
            'itemid' => $gradeitem->id,
            'userid' => $user1->id,
            'finalgrade' => $originalgrade
        ));

        $status = grade_import_commit($course->id, $importcode, false, false);
        $this->assertTrue($status);

        // Get imported grade_grade.
        $gradegrade = \grade_grade::fetch(array('itemid' => $gradeitem->id, 'userid' => $user1->id));
        $this->assertEquals($originalgrade, $gradegrade->finalgrade);
        // Overriden field will be a timestamp and will evaluate out to true.
        $this->assertTrue($gradegrade->is_overridden());

        // Create a new grade item and import into that.
        $importcode = get_new_importcode();
        $record = new \stdClass();
        $record->itemname = 'New grade item';
        $record->importcode = $importcode;
        $record->importer = $USER->id;
        $insertid = $DB->insert_record('grade_import_newitem', $record);

        $finalgrade = 75;
        $this->import_grades(array(
            'importcode' => $importcode,
            'userid' => $user1->id,
            'finalgrade' => $finalgrade,
            'newgradeitem' => $insertid
        ));

        $status = grade_import_commit($course->id, $importcode, false, false);
        $this->assertTrue($status);
        // Check that we have a new \grade_item.
        $gradeitem = \grade_item::fetch(array('courseid' => $course->id, 'itemtype' => 'manual'));
        $this->assertEquals($record->itemname, $gradeitem->itemname);
        // Grades were imported.
        $gradegrade = \grade_grade::fetch(array('itemid' => $gradeitem->id, 'userid' => $user1->id));
        $this->assertEquals($finalgrade, $gradegrade->finalgrade);
        // As this is a new item the grade has not been overridden.
        $this->assertFalse($gradegrade->is_overridden());

        // Import feedback only.
        $importcode = get_new_importcode();
        $gradeitem = \grade_item::fetch(array('courseid' => $course->id, 'itemtype' => 'mod'));

        $originalfeedback = 'feedback can be useful';
        $this->import_grades(array(
            'importcode' => $importcode,
            'userid' => $user1->id,
            'itemid' => $gradeitem->id,
            'feedback' => $originalfeedback,
            'importonlyfeedback' => true
        ));

        $status = grade_import_commit($course->id, $importcode, true, false);
        $this->assertTrue($status);
        $gradegrade = \grade_grade::fetch(array('itemid' => $gradeitem->id, 'userid' => $user1->id));
        // The final grade should be the same as the first record further up. We are only altering the feedback.
        $this->assertEquals($originalgrade, $gradegrade->finalgrade);
        $this->assertTrue($gradegrade->is_overridden());

        // Import grades only.
        $importcode = get_new_importcode();
        $gradeitem = \grade_item::fetch(array('courseid' => $course->id, 'itemtype' => 'mod'));

        $finalgrade = 60;
        $this->import_grades(array(
            'importcode' => $importcode,
            'userid' => $user1->id,
            'itemid' => $gradeitem->id,
            'finalgrade' => $finalgrade,
            'feedback' => 'feedback can still be useful'
        ));

        $status = grade_import_commit($course->id, $importcode, false, false);
        $this->assertTrue($status);
        $gradegrade = \grade_grade::fetch(array('itemid' => $gradeitem->id, 'userid' => $user1->id));
        $this->assertEquals($finalgrade, $gradegrade->finalgrade);
        // The final feedback should not have changed.
        $this->assertEquals($originalfeedback, $gradegrade->feedback);
        $this->assertTrue($gradegrade->is_overridden());

        // Check that printing of import status is correct.
        $importcode = get_new_importcode();
        $gradeitem = \grade_item::fetch(array('courseid' => $course->id, 'itemtype' => 'mod'));

        $this->import_grades(array(
            'importcode' => $importcode,
            'userid' => $user1->id,
            'itemid' => $gradeitem->id
        ));

        ob_start();
        $status = grade_import_commit($course->id, $importcode);
        $output = ob_get_contents();
        ob_end_clean();
        $this->assertTrue($status);
        $this->assertStringContainsString("++ Grade import success ++", $output);
    }

    /**
     * Test grade import commit for users who aren't enrolled on the target course
     *
     * @covers ::grade_import_commit
     */
    public function test_grade_import_commit_unenrolled_user(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Lionel', 'lastname' => 'Doe']);

        // Enter a new grade into an existing grade item.
        $gradeitem = grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod']);

        $importcode = get_new_importcode();
        $this->import_grades([
            'importcode' => $importcode,
            'itemid' => $gradeitem->id,
            'userid' => $user->id,
            'finalgrade' => 10,
        ]);

        ob_start();
        $status = grade_import_commit($course->id, $importcode);
        $output = ob_get_contents();
        ob_end_clean();

        // Assert commit succeeded and we didn't receive debugging about lack of name fields.
        $this->assertTrue($status);
        $this->assertStringContainsString('This import included the following grades for users not currently' .
            ' enrolled in this course', $output);
        $this->assertStringContainsString('User ' . fullname($user), $output);
        $this->assertDebuggingNotCalled();
    }

    /**
     * Test retrieving users included in impoty who aren't enrolled on the target course
     *
     * @covers ::get_unenrolled_users_in_import
     */
    public function test_get_unenrolled_users_in_import(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'idnumber' => 'gid101']);
        $user = $this->getDataGenerator()->create_user(['idnumber' => 'uid101']);

        // Enter a new grade into an existing grade item.
        $gradeitem = grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod']);

        $importcode = get_new_importcode();
        $importgradeid = $this->import_grades([
            'importcode' => $importcode,
            'itemid' => $gradeitem->id,
            'userid' => $user->id,
            'finalgrade' => 10,
        ]);

        $unenrolledusers = get_unenrolled_users_in_import($importcode, $course->id);
        $this->assertCount(1, $unenrolledusers);

        $unenrolleduser = reset($unenrolledusers);
        $this->assertEquals((object) [
            'id' => $importgradeid,
            'firstnamephonetic' => $user->firstnamephonetic,
            'lastnamephonetic' => $user->lastnamephonetic,
            'middlename' => $user->middlename,
            'alternatename' => $user->alternatename,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'useridnumber' => 'uid101',
            'gradeidnumber' => 'gid101',
        ], $unenrolleduser);
    }
}
