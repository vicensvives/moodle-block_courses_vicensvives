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

require_once($CFG->dirroot.'/blocks/courses_vicensvives/lib/vicensvives.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->libdir.'/gradelib.php');

class courses_vicensvives_add_book {

    var $book;
    var $progress;
    var $current = 0;
    var $total;
    var $course;
    var $updatedunits = array();

    private function __construct($bookid, $course, progress_bar $progress) {
        $this->course = $course;
        $this->progress = $progress;
        if ($this->progress) {
            $this->progress->create();
        }
        $ws = new vicensvives_ws();
        $this->book = $ws->book($bookid);
        if (!$this->book) {
            throw new moodle_exception('booknotfetched', 'block_courses_vicensvives');
        }
        $this->total = $this->progress_total(!$course);
        $this->update_progress();
    }

    static function create($bookid, progress_bar $progress=null) {
        $addbook = new self($bookid, null, $progress);
        $courseid = $addbook->create_course();
        $addbook->create_course_content();
        return $courseid;
    }

    static function enrol_user($courseid, $userid) {
        global $DB;

        if (!$role = $DB->get_record('role', array('shortname'=>'editingteacher'))) {
            return get_string('editingteachernotexist', 'block_courses_vicensvives');
        }

        // Matriculación manual.
        if (!enrol_is_enabled('manual')) {
            return get_string('manualnotenable', 'block_courses_vicensvives');
        }

        $context = context_course::instance($courseid);
        if (user_has_role_assignment($userid, $role->id, $context->id)) {
            return;
        }

        $manual = enrol_get_plugin('manual');
        $instances = enrol_get_instances($courseid, false);
        foreach ($instances as $instance) {
            if ($instance->enrol === 'manual') {
                $manual->enrol_user($instance, $userid, $role->id);
                break;
            }
        }
    }

    static function update($courseid, progress_bar $progress=null) {
        global $DB;

        $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

        $bookid = null;
        preg_match('/^vv-(\d+)-/', $course->idnumber, $match);
        if ($match) {
            $bookid = (int) $match[1];
        }

        $addbook = new self($bookid, $course, $progress);
        $addbook->create_course_content();

        return $addbook->updatedunits;
    }

    private function create_course() {
        global $CFG, $DB;

        $courseobj =  new stdClass();
        $courseobj->category = $CFG->block_courses_vicensvives_defaultcategory;

        // Asignación de un shortname y idnumber no utilitzados
        for ($n = 1; ; $n++) {
            $shortname = $this->book->shortname . '_' . $n;
            $idnumber = 'vv-' . $this->book->idBook . '-' . $n . '-' . $this->book->subject;
            $select = 'shortname = ? OR idnumber = ?';
            $params = array($shortname, $idnumber);
            if (!$DB->record_exists_select('course', $select, $params)) {
                break;
            }
        }
        $courseobj->shortname = $shortname;
        $courseobj->fullname = $this->book->fullname;
        $courseobj->idnumber = $idnumber;
        $courseobj->summary = $this->book->authors;
        // Comprobar q existe el formato de curso personalizado para aplicarlo.
        $courseobj->format = 'topics';
        $courseformats = get_plugin_list('format');
        if (isset($courseformats['vv'])) {
            $courseobj->format = 'vv';
        }
        // De esta manera no crearemos el foro de noticias.
        $courseobj->newsitems = 0;

        $this->course = create_course($courseobj);

        $this->update_progress();

        return $this->course->id;
    }

    private function create_course_content() {
        global $DB;

        $roleid = $DB->get_field('role', 'id', array('shortname' => 'user'));

        $sectionnum = 1;
        foreach ($this->book->units as $unit) {
            $items = $this->get_section_items($unit);
            $sectionname = $unit->label . '. ' . $unit->name;
            $section = $this->setup_section($sectionnum, $sectionname, $items);
            $this->set_num_sections($sectionnum);
            $this->update_progress();
            $beforemod = null;
            foreach (array_reverse($items) as $item) {
                $cm = $this->get_cm($item, $section);

                if ($cm) {
                    // Actualizamos idnumber si ha cambiado (etiquetas y enlaces creadas con una versión anterior)
                    if ($cm->idnumber != $item['idnumber']) {
                        $DB->set_field('course_modules', 'idnumber', $item['idnumber'], array('id' => $cm->id));
                    }
                } else {
                    // Nueva actividad
                    $cm = $this->create_mod($item, $section);

                    // Añade a la sección
                    $seq = $section->sequence ? explode(',', $section->sequence) : array();
                    if ($beforemod) {
                        $index = array_search($beforemod->id, $seq);
                        array_splice($seq, $index, 0, array($cm->id));
                    } else {
                        $seq[] = $cm->id;
                    }
                    $section->sequence = implode(',', $seq);
                    $DB->set_field('course_sections', 'sequence', $section->sequence, array('id' => $section->id));

                    // Denegación del permiso de edición de la actividad
                    $context = context_module::instance($cm->id);
                    assign_capability('moodle/course:manageactivities', CAP_PROHIBIT, $roleid, $context);

                    $this->updatedunits[$unit->id] = $unit;
                }

                $beforemod = $cm;
                $this->update_progress();
            }

            $sectionnum++;
        }

        rebuild_course_cache($this->course->id);
        get_fast_modinfo($this->course);
        grade_regrade_final_grades($this->course->id);
        $this->update_progress();
    }

    // Esta función es diferente para cada versión de Moodle
    private function create_mod($item, $section) {
        global $CFG, $DB;

        $module = $DB->get_record('modules', array('name' => $item['modname']), '*', MUST_EXIST);
        $context = get_context_instance(CONTEXT_COURSE, $this->course->id);

        $modlib = "$CFG->dirroot/mod/$module->name/lib.php";
        if (file_exists($modlib)) {
            include_once($modlib);
        } else {
            print_error('modulemissingcode', '', '', $modlib);
        }

        $fromform = new stdClass();
        $fromform->course = $this->course->id;
        $fromform->module = $module->id;
        $fromform->modulename = $module->name;
        $fromform->visible = true;
        $fromform->name = $item['name'];
        $fromform->intro = $item['name'];
        $fromform->introformat = 0;
        $fromform->groupingid = $this->course->defaultgroupingid;
        $fromform->groupmembersonly = 0;
        $fromform->section = $section->section;
        $fromform->instance = '';
        $fromform->completion = COMPLETION_DISABLED;
        $fromform->completionview = COMPLETION_VIEW_NOT_REQUIRED;
        $fromform->completionusegrade = null;
        $fromform->completiongradeitemnumber = null;
        $fromform->groupmode = 0; // Do not set groupmode.
        $fromform->instance     = '';
        $fromform->coursemodule = '';
        $fromform->cmidnumber = $item['idnumber'];

        foreach ($item['params'] as $key => $value) {
            $fromform->$key = $value;
        }

        $addinstancefunction    = $fromform->modulename."_add_instance";
        $updateinstancefunction = $fromform->modulename."_update_instance";

        // first add course_module record because we need the context
        $newcm = new stdClass();
        $newcm->course           = $this->course->id;
        $newcm->module           = $fromform->module;
        $newcm->instance         = 0; // not known yet, will be updated later (this is similar to restore code)
        $newcm->visible          = $fromform->visible;
        $newcm->visibleold       = $fromform->visible;
        $newcm->groupmode        = $fromform->groupmode;
        $newcm->groupingid       = $fromform->groupingid;
        $newcm->groupmembersonly = $fromform->groupmembersonly;
        $newcm->showdescription = 0;

        if (!$fromform->coursemodule = add_course_module($newcm)) {
            print_error('cannotaddcoursemodule');
        }

        $returnfromfunc = $addinstancefunction($fromform, null);

        if (!$returnfromfunc or !is_number($returnfromfunc)) {
            // undo everything we can
            $modcontext = get_context_instance(CONTEXT_MODULE, $fromform->coursemodule);
            delete_context(CONTEXT_MODULE, $fromform->coursemodule);
            $DB->delete_records('course_modules', array('id'=>$fromform->coursemodule));

            if (!is_number($returnfromfunc)) {
                print_error('invalidfunction', '', course_get_url($course, $section->section));
            } else {
                print_error('cannotaddnewmodule', '', course_get_url($course, $section->section), $fromform->modulename);
            }
        }

        $fromform->instance = $returnfromfunc;

        $DB->set_field('course_modules', 'instance', $returnfromfunc, array('id'=>$fromform->coursemodule));

        // course_modules and course_sections each contain a reference
        // to each other, so we have to update one of them twice.
        $fromform->id = $fromform->coursemodule;

        $DB->set_field('course_modules', 'section', $section->id, array('id'=>$fromform->coursemodule));

        // make sure visibility is set correctly (in particular in calendar)
        // note: allow them to set it even without moodle/course:activityvisibility
        set_coursemodule_visible($fromform->coursemodule, $fromform->visible);

        if (isset($fromform->cmidnumber)) { //label
            // set cm idnumber - uniqueness is already verified by form validation
            set_coursemodule_idnumber($fromform->coursemodule, $fromform->cmidnumber);
        }

        // sync idnumber with grade_item
        if ($grade_item = grade_item::fetch(array('itemtype'=>'mod', 'itemmodule'=>$fromform->modulename,
                                                  'iteminstance'=>$fromform->instance, 'itemnumber'=>0, 'courseid'=>$this->course->id))) {
            if ($grade_item->idnumber != $fromform->cmidnumber) {
                $grade_item->idnumber = $fromform->cmidnumber;
                $grade_item->update();
            }
        }

        return $DB->get_record('course_modules', array('id' => $fromform->coursemodule), '*', MUST_EXIST);
    }

    private function get_cm($item, $section) {
        global $DB;

        $conditions = array('course' => $this->course->id, 'idnumber' => $item['idnumber']);
        $cm = $DB->get_record('course_modules', $conditions);
        if ($cm) {
            return $cm;
        }

        // Anteriormente las etiquetas no tenían idnumber asignado, buscamos la etiqueta por nombre.
        if ($item['modname'] == 'label') {
            $sql = 'SELECT cm.*
                    FROM {course_modules} cm
                    JOIN {modules} m ON m.id = cm.module
                    JOIN {label} l ON l.id = cm.instance
                    WHERE cm.course = :course
                    AND cm.section = :section
                    AND m.name = :modname
                    AND l.name = :name';
            $params = array(
                'course' => $this->course->id,
                'section' => $section->id,
                'modname' => 'label',
                'name' => $item['name'],
            );
            $records = $DB->get_records_sql($sql, $params, 0, 1);
            if ($records) {
                return reset($records);
            }
        }

        // Anteriormente los enlaces tenían el idnumber con un formato diferente
        if ($item['modname'] == 'url') {
            if (preg_match('/^.+_(link_.+)$/', $item['idnumber'], $match)) {
                $conditions = array('course' => $this->course->id, 'idnumber' => $match[1]);
                $cm = $DB->get_record('course_modules', $conditions);
                if ($cm) {
                    return $cm;
                }
            }
        }

        return null;
    }

    private function get_section_items($unit) {
        $items = array();

        foreach ($unit->sections as $section) {
            $items[] = array(
                'idnumber' => $this->book->idBook . '_label_' . $section->id,
                'name' => '[' . $section->label . '] ' . $section->name,
                'modname' => 'label',
                'params' => array(),
            );

            if (!empty($section->lti)) {
                $items[] = array(
                    'idnumber' => $this->book->idBook . '_section_' . $section->id,
                    'name' => $section->lti->activityName,
                    'modname' => 'lti',
                    'params' => $this->lti_params($section->lti),
                );
            }

            if (!empty($section->questions)) {
                foreach ($section->questions as $question) {
                    $items[] = array(
                        'idnumber' => $this->book->idBook . '_question_' . $question->id,
                        'name' => $question->lti->activityName,
                        'modname' => 'lti',
                        'params' => $this->lti_params($question->lti),
                    );
                }
            }
            if (!empty($section->links)) {
                foreach ($section->links as $link) {
                    $items[] = array(
                        'idnumber' => $this->book->idBook . '_link_' . $link->id,
                        'name' => $link->name,
                        'modname' => 'url',
                        'params' => array(
                            'externalurl' => $link->url,
                            'intro' => $link->summary,
                            'display' => 0,
                        ),
                    );
                }
            }
            if (!empty($section->documents)) {
                foreach ($section->documents as $document) {
                    $items[] = array(
                        'idnumber' => $this->book->idBook . '_document_' . $document->id,
                        'name' => $document->lti->activityName,
                        'modname' => 'lti',
                        'params' => $this->lti_params($document->lti),
                    );
                }
            }
        }

        return $items;
    }

    private function lti_params($lti) {
        $params = array(
            'toolurl' => $lti->launchURL,
            'instructorchoicesendname' => true,
            'instructorchoicesendemailaddr' => true,
            'launchcontainer' => 4, // window
        );
        if (isset($lti->activityDescription))  {
            $params['intro'] = $lti->activityDescription;
        }
        if (isset($lti->consumerKey)) {
            $params['resourcekey'] = $lti->consumerKey;
        }
        if (isset($lti->sharedSecret)) {
            $params['password'] = $lti->sharedSecret;
        }
        if (isset($lti->customParameters)) {
            $params['instructorcustomparameters'] = $lti->customParameters;
        }
        if (isset($lti->acceptGrades)) {
            $params['instructorchoiceacceptgrades'] = (int) $lti->acceptGrades;
        }
        return $params;
    }

    private function progress_total($createcourse=true) {
        $total = 1; // fetch book content
        $total += ($createcourse ? 1 : 0); // create course
        foreach ($this->book->units as $unit) {
            $total++; // setup section
            foreach ($unit->sections as $section) {
                $total++; // section label
                if (!empty($section->lti)) {
                    $total++; // section lti
                }
                $total += count($section->questions);
                $total += count($section->links);
                $total += count($section->documents);
            }
        }
        $total++; // rebuild course cache
        return $total;
    }

    // Esta función es diferente para cada versión de Moodle
    private function set_num_sections($sectionnum) {
        global $DB;

        if ($sectionnum > $this->course->numsections) {
            $this->course->numsections = $sectionnum;
            $DB->set_field('course', 'numsections', $sectionnum, array('id' => $this->course->id));
        }
    }

    private function setup_section($sectionnum, $name, array $items=null) {
        global $DB;

        $section = null;

        // Búsqueda de la sección basada en los elementos ya creados
        if ($items) {
            $idnumbers = array();
            foreach ($items as $item) {
                $idnumbers[] = $item['idnumber'];
            }

            list($idnumbersql, $params) = $DB->get_in_or_equal($idnumbers, SQL_PARAMS_NAMED);
            $sql = "SELECT s.*
                    FROM {course_modules} cm
                    JOIN {course_sections} s ON s.id = cm.section
                    WHERE cm.course = :courseid AND cm.idnumber $idnumbersql
                    ORDER BY s.section";
            $params['courseid'] = $this->course->id;
            $sections = $DB->get_records_sql($sql, $params, 0, 1);

            if ($sections) {
                $section = reset($sections);
            }
        }

        // No existe ningún elemento, buscamos la primera sección vacía
        if (!$section) {
            $sections = $DB->get_records('course_sections', array('course' => $this->course->id), 'section');
            $nextsectionnum = 1;
            foreach ($sections as $section) {
                $nextsectionnum = $section->section + 1;
                if ($section->section > 0 and $section->sequence == '') {
                    break;
                }
            }
            // Si no existe ninguna sección vacía, creamos una nueva
            if (!$section or $section->section == 0 or $section->sequence != '') {
                $section = new stdClass();
                $section->course = $this->course->id;
                $section->section = $nextsectionnum;
                $section->summary = '';
                $section->summaryformat = FORMAT_HTML;
                $section->sequence = '';
                $section->id = $DB->insert_record('course_sections', $section);
            }
        }

        // Actualización del nombre de la sección
        $section->name = $name;
        $DB->set_field('course_sections', 'name', $name, array('id' => $section->id ));

        // Reordenación de las secciones
        if ($sectionnum != $section->section) {
            move_section_to($this->course, $section->section, $sectionnum);
            return $DB->get_record('course_sections', array('id' => $section->id));
        }

        return $section;
    }

    private function update_progress($msg='') {
        $this->current++;
        if ($this->progress) {
            $this->progress->update($this->current, $this->total, $msg);
        }
    }
}
