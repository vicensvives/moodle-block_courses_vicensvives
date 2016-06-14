<?php

define('NO_OUTPUT_BUFFERING', true);

require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/courses_vicensvives/locallib.php');

@set_time_limit(0);
raise_memory_limit(MEMORY_HUGE);

$bookid = required_param('bookid', PARAM_INT);

require_login(null, false);

$context = context_coursecat::instance($CFG->block_courses_vicensvives_defaultcategory);
require_capability('moodle/course:create', $context);

require_sesskey();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/courses_vicensvives/addbook.php'));

$PAGE->set_title(get_string('creatingcourse', 'block_courses_vicensvives'));
$PAGE->set_heading(get_string('creatingcourse', 'block_courses_vicensvives'));

echo $OUTPUT->header();

$progress = new progress_bar();
$courseid = courses_vicensvives_add_book::create($bookid, $progress);
$strredirect = courses_vicensvives_add_book::enrol_user($courseid, $USER->id);
$strredirect .= ' '.get_string('redirectcourse', 'block_courses_vicensvives');
$urlcourse = new moodle_url('/course/view.php', array('id' => $courseid));
echo $OUTPUT->notification($strredirect, 'redirectmessage');
echo '<div class="continuebutton">(<a href="'. $urlcourse .'">'. get_string('continue') .'</a>)</div>';

echo $OUTPUT->footer();
