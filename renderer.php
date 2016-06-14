<?php

class block_courses_vicensvives_renderer extends plugin_renderer_base {

    function course_info_box($course) {
        global $CFG;

        $output = '';

        $context = context_course::instance($course->id);

        // Rewrite file URLs so that they are correct
        $course->summary = file_rewrite_pluginfile_urls($course->summary, 'pluginfile.php', $context->id, 'course', 'summary', NULL);

        $output .= html_writer::start_tag('div', array('class'=>'vicensvives_course clearfix'));
        $output .= html_writer::start_tag('div', array('class'=>'vicensvives_course_left'));
        $output .= html_writer::start_tag('h3', array('class'=>'vicensvives_course_name'));

        $linkhref = new moodle_url('/course/view.php', array('id'=>$course->id));

        $linktext = format_string($course->fullname);
        $linkparams = array('title'=>get_string('entercourse'));
        if (empty($course->visible)) {
            $linkparams['class'] = 'dimmed';
        }
        $output .= html_writer::link($linkhref, $linktext, $linkparams);
        $output .= html_writer::end_tag('h3');

        $output .= html_writer::start_tag('ul', array('class'=>'vicensvives_course_teachers'));
        foreach ($this->get_course_contacts($course) as $userid => $coursecontact) {
            $name = $coursecontact['rolename'].': '.
                    html_writer::link(new moodle_url('/user/view.php',
                            array('id' => $userid, 'course' => SITEID)),
                        $coursecontact['username']);
            $output .= html_writer::tag('li', $name);
        }
        $output .= html_writer::end_tag('ul');

        $output .= html_writer::end_tag('div'); // End of info div

        $context = context_coursecat::instance($CFG->block_courses_vicensvives_defaultcategory);
        if (has_capability('moodle/course:create', $context)) {
            $url = new moodle_url('/blocks/courses_vicensvives/updatebook.php', array('id' => $course->id));
            $label = get_string('update');
            $button = $this->single_button($url, $label);
            $output .= html_writer::tag('div', $button, array('class' => 'vicensvives_course_right'));
        }

        $output .= html_writer::start_tag('div', array('class'=>'vicensvives_course_right'));
        $options = new stdClass();
        $options->noclean = true;
        $options->para = false;
        $options->overflowdiv = true;
        if (!isset($course->summaryformat)) {
            $course->summaryformat = FORMAT_MOODLE;
        }
        $output .= format_text($course->summary, $course->summaryformat, $options,  $course->id);
        $output .= html_writer::end_tag('div'); // End of summary div

        $output .= html_writer::end_tag('div'); // End of coursebox div

        return $output;
    }

    // Esta función es diferente para cada versión de Moodle
    private function get_course_contacts($course) {
        global $CFG, $DB;

        $contacts = array();

        $context = context_course::instance($course->id);

        /// first find all roles that are supposed to be displayed
        if (!empty($CFG->coursecontact)) {
            $managerroles = explode(',', $CFG->coursecontact);
            $namesarray = array();
            $rusers = array();

            if (!isset($course->managers)) {
                $rusers = get_role_users($managerroles, $context, true,
                    'ra.id AS raid, u.id, u.username, u.firstname, u.lastname,
                     r.name AS rolename, r.sortorder, r.id AS roleid',
                    'r.sortorder ASC, u.lastname ASC');
            } else {
                //  use the managers array if we have it for perf reasosn
                //  populate the datastructure like output of get_role_users();
                foreach ($course->managers as $manager) {
                    $u = new stdClass();
                    $u = $manager->user;
                    $u->roleid = $manager->roleid;
                    $u->rolename = $manager->rolename;

                    $rusers[] = $u;
                }
            }

            /// Rename some of the role names if needed
            if (isset($context)) {
                $aliasnames = $DB->get_records('role_names', array('contextid'=>$context->id), '', 'roleid,contextid,name');
            }

            $canviewfullnames = has_capability('moodle/site:viewfullnames', $context);
            foreach ($rusers as $ra) {
                if (isset($contacts[$ra->id])) {
                    //  only display a user once with the higest sortorder role
                    continue;
                }

                if (isset($aliasnames[$ra->roleid])) {
                    $ra->rolename = $aliasnames[$ra->roleid]->name;
                }

                $fullname = fullname($ra, $canviewfullnames);
                $contacts[$ra->id] = array(
                    'rolename' => $ra->rolename,
                    'username' => fullname($ra, $canviewfullnames),
                );
            }

            if (!empty($namesarray)) {
                $output .= html_writer::start_tag('ul', array('class'=>'unlist vicensvives_course_teachers'));
                foreach ($namesarray as $name) {
                    $output .= html_writer::tag('li', $name);
                }
                $output .= html_writer::end_tag('ul');
            }
        }

        return $contacts;
    }
}
