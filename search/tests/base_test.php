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

namespace core_search;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/testable_core_search.php');
require_once($CFG->dirroot . '/search/tests/fixtures/mock_search_area.php');

/**
 * Search engine base unit tests.
 *
 * @package     core_search
 * @copyright   2017 Matt Porritt <mattp@catalyst-au.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class base_test extends \advanced_testcase {
    /**
     * @var \core_search::manager
     */
    protected $search = null;

    /**
     * @var Instace of core_search_generator.
     */
    protected $generator = null;

    /**
     * @var Instace of testable_engine.
     */
    protected $engine = null;

    public function setUp(): void {
        $this->resetAfterTest();
        set_config('enableglobalsearch', true);

        // Set \core_search::instance to the mock_search_engine as we don't require the search engine to be working to test this.
        $search = \testable_core_search::instance();

        $this->generator = self::getDataGenerator()->get_plugin_generator('core_search');
        $this->generator->setup();
    }

    public function tearDown(): void {
        // For unit tests before PHP 7, teardown is called even on skip. So only do our teardown if we did setup.
        if ($this->generator) {
            // Moodle DML freaks out if we don't teardown the temp table after each run.
            $this->generator->teardown();
            $this->generator = null;
        }
    }

    /**
     * Test base get search fileareas
     */
    public function test_get_search_fileareas_base() {

        $builder = $this->getMockBuilder('\core_search\base');
        $builder->disableOriginalConstructor();
        $stub = $builder->getMockForAbstractClass();

        $result = $stub->get_search_fileareas();

        $this->assertEquals(array(), $result);
    }

    /**
     * Test base attach files
     */
    public function test_attach_files_base() {
        $filearea = 'search';
        $component = 'mod_test';

        // Create file to add.
        $fs = get_file_storage();
        $filerecord = array(
                'contextid' => 1,
                'component' => $component,
                'filearea' => $filearea,
                'itemid' => 1,
                'filepath' => '/',
                'filename' => 'testfile.txt');
        $content = 'All the news that\'s fit to print';
        $file = $fs->create_file_from_string($filerecord, $content);

        // Construct the search document.
        $rec = new \stdClass();
        $rec->contextid = 1;
        $area = new \core_mocksearch\search\mock_search_area();
        $record = $this->generator->create_record($rec);
        $document = $area->get_document($record);

        // Create a mock from the abstract class,
        // with required methods stubbed.
        $builder = $this->getMockBuilder('\core_search\base');
        $builder->disableOriginalConstructor();
        $builder->onlyMethods(array('get_search_fileareas', 'get_component_name'));
        $stub = $builder->getMockForAbstractClass();
        $stub->method('get_search_fileareas')->willReturn(array($filearea));
        $stub->method('get_component_name')->willReturn($component);

        // Attach file to our test document.
        $stub->attach_files($document);

        // Verify file is attached.
        $files = $document->get_files();
        $file = array_values($files)[0];

        $this->assertEquals(1, count($files));
        $this->assertEquals($content, $file->get_content());
    }

    /**
     * Tests the base version (stub) of get_contexts_to_reindex.
     */
    public function test_get_contexts_to_reindex() {
        $area = new \core_mocksearch\search\mock_search_area();
        $this->assertEquals([\context_system::instance()],
                iterator_to_array($area->get_contexts_to_reindex(), false));
    }

    /**
     * Test default document icon.
     */
    public function test_get_default_doc_icon() {
        $basearea = $this->getMockBuilder('\core_search\base')
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $document = $this->getMockBuilder('\core_search\document')
            ->disableOriginalConstructor()
            ->getMock();

        $result = $basearea->get_doc_icon($document);

        $this->assertEquals('i/empty', $result->get_name());
        $this->assertEquals('moodle', $result->get_component());
    }

    /**
     * Test base search area category names.
     */
    public function test_get_category_names() {
        $builder = $this->getMockBuilder('\core_search\base');
        $builder->disableOriginalConstructor();
        $stub = $builder->getMockForAbstractClass();

        $expected = ['core-other'];
        $this->assertEquals($expected, $stub->get_category_names());
    }

    /**
     * Test getting all required search area setting names.
     */
    public function test_get_settingnames() {
        $expected = array('_enabled', '_indexingstart', '_indexingend', '_lastindexrun',
            '_docsignored', '_docsprocessed', '_recordsprocessed', '_partial');
        $this->assertEquals($expected, \core_search\base::get_settingnames());
    }
}
