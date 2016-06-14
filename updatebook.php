<?php

define('NO_OUTPUT_BUFFERING', true);

require_once('../../config.php');
require_once($CFG->dirroot . '/blocks/courses_vicensvives/locallib.php');

@set_time_limit(0);
raise_memory_limit(MEMORY_HUGE);

$courseid = required_param('id', PARAM_INT);

require_login(null, false);

$context = context_coursecat::instance($CFG->block_courses_vicensvives_defaultcategory);
require_capability('moodle/course:create', $context);

require_sesskey();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/courses_vicensvives/updatebook.php'));

$PAGE->set_title(get_string('updateingcourse', 'block_courses_vicensvives'));
$PAGE->set_heading(get_string('updateingcourse', 'block_courses_vicensvives'));

echo $OUTPUT->header();

$progress = new progress_bar();
$updatedunits = courses_vicensvives_add_book::update($courseid, $progress);

if ($updatedunits) {
    echo $OUTPUT->heading(get_string('updatedunits', 'block_courses_vicensvives'), 3);
    echo html_writer::start_tag('ul', array('class' => 'vicensives_newunits'));
    foreach ($updatedunits as $unit) {
        echo html_writer::tag('li', html_writer::tag('strong', $unit->label . '.') . ' ' . $unit->name);
    }
    echo html_writer::end_tag('ul');
} else {
    echo $OUTPUT->heading(get_string('noupdatedunits', 'block_courses_vicensvives'), 3);
}

$strredirect = get_string('redirectcourse', 'block_courses_vicensvives');
$urlcourse = new moodle_url('/course/view.php', array('id' => $courseid));
echo $OUTPUT->notification($strredirect, 'redirectmessage');
echo '<div class="continuebutton">(<a href="'. $urlcourse .'">'. get_string('continue') .'</a>)</div>';

echo $OUTPUT->footer();
