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
 * Authentication Plugin:
 *
 * Checks against an external database.
 *
 * @package    courses_vicensvives
 * @author     CV&A Consulting
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 */

require('../../config.php');
require_once("$CFG->dirroot/blocks/courses_vicensvives/locallib.php");

if (!isloggedin() or isguestuser()) {
    require_login();
}
require_login(0, false);

$returnurl = new moodle_url('/blocks/courses_vicensvives/courses.php');
//$returnurl = new moodle_url('/index.php/');
$PAGE->set_url($returnurl);

$context = context_system::instance();

$PAGE->set_context($context);

$PAGE->set_pagelayout('standard');

$PAGE->set_title(get_string('courses', 'block_courses_vicensvives'));
$PAGE->set_heading(get_string('courses', 'block_courses_vicensvives'));

$viewcourses = new moodle_url('/blocks/courses_vicensvives/courses.php');
$PAGE->navbar->add(get_string('blocks'));
$PAGE->navbar->add(get_string('pluginname', 'block_courses_vicensvives'));
$PAGE->navbar->add(get_string('courses', 'block_courses_vicensvives'), $viewcourses);

echo $OUTPUT->header();

require_once($CFG->dirroot.'/lib/resourcelib.php');

$url = $CFG->wwwroot.'/blocks/courses_vicensvives/commander.php';

// El profesor ve de sus cursos los filtrados de VV.
// Sólo profesores!!

$renderer = $PAGE->get_renderer('block_courses_vicensvives');

if (isloggedin() and !isguestuser() and !(has_capability('moodle/course:update', context_system::instance()))) {
    // Just print My Courses.
    if (! $mycourses = enrol_get_my_courses(null, 'visible DESC, fullname ASC')) {
        redirect($CFG->wwwroot, get_string('nohaycursos', 'block_courses_vicensvives'), 10);
    }

    // Filtrar mycourses con sólo los de VV.
    foreach ($mycourses as $course) {
        if ($course->format != 'vv') {
            continue;
        }
        $coursecontext = context_course::instance($course->id);
        if ($course->visible == 1 || has_capability('moodle/course:viewhiddencourses', $coursecontext)) {
            echo $renderer->course_info_box($course);
        }
    }
}

// Administradores.
if (has_capability('moodle/course:update', context_system::instance())) {
    $conditions = array('format' => 'vv');
    $fields = 'id, fullname, visible, summary, summaryformat';
    $courses = $DB->get_records('course', $conditions, 'sortorder', $fields);

    echo $OUTPUT->single_button(new moodle_url('/blocks/courses_vicensvives/books.php'), get_string('addcourse',
        'block_courses_vicensvives'));

    foreach ($courses as $course) {
        $coursecontext = context_course::instance($course->id);
        if ($course->visible == 1 || has_capability('moodle/course:viewhiddencourses', $coursecontext)) {
            echo $renderer->course_info_box($course);
        }
    }
}

echo $OUTPUT->footer();
