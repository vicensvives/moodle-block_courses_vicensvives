<?php

require_once($CFG->dirroot . '/course/lib.php');

class block_courses_vicensvives extends block_list {
    function init() {
        $this->title = get_string('pluginname', 'block_courses_vicensvives');
    }

    function instance_allow_multiple() {
        return false;
    }

    function has_config() {
        return true;
    }

    function instance_config_save($data, $nolongerused = false) {
        echo "esto no se cuando se ejecuta";die;
        if (empty($data->quizid)) {
            $data->quizid = $this->get_owning_quiz();
        }
        parent::instance_config_save($data);
    }

    function get_content() {
        global $CFG, $USER, $DB, $OUTPUT;

        if($this->content !== NULL) {
            return $this->content;
        }

        $this->content = new stdClass;
        $this->content->items = array();
        $this->content->icons = array();
        $this->content->footer = '';

        $icon  = '<img src="' . $OUTPUT->pix_url('i/course') . '" class="icon" alt="" />&nbsp;';

        // El profesor ve de sus cursos los filtrados de VV.
        // Sólo profesores!!
        $contextcat = context_coursecat::instance($CFG->block_courses_vicensvives_defaultcategory);
//        print_object($contextcat);
//        print_object(has_capability('moodle/course:create', $context));
//        echo isloggedin()." and !".isguestuser()." and !".is_siteadmin()." and ".has_capability('moodle/course:create', $context);
        if (isloggedin() and !isguestuser() and !is_siteadmin()
            // and !(has_capability('moodle/course:update', context_system::instance()))
            and has_capability('moodle/course:create', $contextcat)
        ) {
            // Just print My Courses.

            if (! $mycourses = enrol_get_my_courses(null, 'visible DESC, fullname ASC') and !has_capability('moodle/course:create', $contextcat)) {
                return $this->content;
            }

            // Filtrar mycourses con sólo los de VV.
            $courses = $mycourses;

            $i = 0;
            foreach ($courses as $course) {
                // Falta filtrar VV.
                $coursecontext = context_course::instance($course->id); // Eliminamos alumnos de esta manera.
                if (has_capability('moodle/course:update', $coursecontext) && $i < $CFG->block_courses_vicensvives_maxcourses ) {
                    $linkcss = $course->visible ? "" : " class=\"dimmed\" ";
                    $this->content->items[]="<a $linkcss title=\"" . format_string($course->shortname, true, array('context' => $coursecontext)) . "\" ".
                               "href=\"$CFG->wwwroot/course/view.php?id=$course->id\">".$icon.format_string($course->fullname). "</a>";
                    $i++;
                } else if ($i >= $CFG->block_courses_vicensvives_maxcourses) {
                    $this->content->items[]="<a title=\"".get_string('show_more', 'block_courses_vicensvives')."\" ".
                        "href=\"$CFG->wwwroot/blocks/courses_vicensvives/courses.php\">".get_string('show_more', 'block_courses_vicensvives')."</a>";
                    break;
                }
            }
            if (has_capability('moodle/course:create', $contextcat)) {
            $this->content->footer = "<a title=\"".get_string('addcourse', 'block_courses_vicensvives')."\" ".
                "href=\"$CFG->wwwroot/blocks/courses_vicensvives/books.php\">".get_string('addcourse', 'block_courses_vicensvives')."</a>";
            }

            $this->title = get_string('pluginname', 'block_courses_vicensvives');
//            if ($this->content->items) { // make sure we don't return an empty list.
                return $this->content;
//            }
        }
        // Administradores.
        if (is_siteadmin()
            // and has_capability('moodle/course:update', context_system::instance())
        ) {
            $this->content->items[]="<a title=\"".get_string('show_courses', 'block_courses_vicensvives')."\" ".
                "href=\"$CFG->wwwroot/blocks/courses_vicensvives/courses.php\">".get_string('show_courses', 'block_courses_vicensvives')."</a>";

            $this->content->items[]="<a title=\"".get_string('addcourse', 'block_courses_vicensvives')."\" ".
                               "href=\"$CFG->wwwroot/blocks/courses_vicensvives/books.php\">".get_string('addcourse', 'block_courses_vicensvives')."</a>";
        }

        return $this->content;
    }

}


