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

        $fromform = new stdClass();
        $fromform->module = $DB->get_field('modules', 'id', array('name' => $item['modname']));
        $fromform->modulename = $item['modname'];
        $fromform->visible = true;
        $fromform->cmidnumber = $item['idnumber'];
        $fromform->name = $item['name'];
        $fromform->intro = $item['name'];
        $fromform->introformat = 0;
        $fromform->availablefrom = 0;
        $fromform->availableuntil = 0;
        $fromform->showavailability = 0;
        $fromform->conditiongradegroup = array();
        $fromform->conditionfieldgroup = array();
        $fromform->conditioncompletiongroup = array();

        foreach ($item['params'] as $key => $value) {
            $fromform->$key = $value;
        }

        courses_vicensvives_add_moduleinfo($fromform, $this->course, $section);

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

        $conditions = array('courseid' => $this->course->id, 'sectionid' => 0, 'name' => 'numsections');
        $numsections = (int) $DB->get_field('course_format_options', 'value', $conditions);
        if ($sectionnum > $numsections) {
            $DB->set_field('course_format_options', 'value', $sectionnum, $conditions);
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

// Función basa en add_moduleinfo, con estas diferencias:
//  - Se pasa el registro de sección para evitar consultar la base de datos
//  - Se ha eliminado código que procesa parámetros no utilitzados
//  - No se llama rebuild_course_cache (se llama una sola vez al final)
//  - No se llama grade_regrade_final_grades (se llama una sola vez al final)
//  - No añade el módulo a la sección, se hace posteriormente
function courses_vicensvives_add_moduleinfo($moduleinfo, $course, $section) {
    global $DB, $CFG;

    require_once("$CFG->dirroot/course/modlib.php");

    // Attempt to include module library before we make any changes to DB.
    include_modulelib($moduleinfo->modulename);

    $moduleinfo->course = $course->id;
    $moduleinfo = set_moduleinfo_defaults($moduleinfo);

    $moduleinfo->groupmode = 0; // Do not set groupmode.

    // First add course_module record because we need the context.
    $newcm = new stdClass();
    $newcm->course           = $course->id;
    $newcm->module           = $moduleinfo->module;
    $newcm->instance         = 0; // Not known yet, will be updated later (this is similar to restore code).
    $newcm->visible          = $moduleinfo->visible;
    $newcm->visibleold       = $moduleinfo->visible;
    if (isset($moduleinfo->cmidnumber)) {
        $newcm->idnumber         = $moduleinfo->cmidnumber;
    }
    $newcm->groupmode        = $moduleinfo->groupmode;
    $newcm->groupingid       = $moduleinfo->groupingid;
    $newcm->groupmembersonly = $moduleinfo->groupmembersonly;
    $newcm->showdescription = 0;

    // From this point we make database changes, so start transaction.
    $transaction = $DB->start_delegated_transaction();

    $newcm->added = time();
    $newcm->section = $section->id;
    if (!$moduleinfo->coursemodule = $DB->insert_record("course_modules", $newcm)) {
        print_error('cannotaddcoursemodule');
    }

    $addinstancefunction    = $moduleinfo->modulename."_add_instance";
    try {
        $returnfromfunc = $addinstancefunction($moduleinfo, null);
    } catch (moodle_exception $e) {
        $returnfromfunc = $e;
    }
    if (!$returnfromfunc or !is_number($returnfromfunc)) {
        // Undo everything we can. This is not necessary for databases which
        // support transactions, but improves consistency for other databases.
        $modcontext = context_module::instance($moduleinfo->coursemodule);
        context_helper::delete_instance(CONTEXT_MODULE, $moduleinfo->coursemodule);
        $DB->delete_records('course_modules', array('id'=>$moduleinfo->coursemodule));

        if ($e instanceof moodle_exception) {
            throw $e;
        } else if (!is_number($returnfromfunc)) {
            print_error('invalidfunction', '', course_get_url($course, $section->section));
        } else {
            print_error('cannotaddnewmodule', '', course_get_url($course, $section->section), $moduleinfo->modulename);
        }
    }

    $moduleinfo->instance = $returnfromfunc;

    $DB->set_field('course_modules', 'instance', $returnfromfunc, array('id'=>$moduleinfo->coursemodule));

    // Update embedded links and save files.
    $modcontext = context_module::instance($moduleinfo->coursemodule);

    $hasgrades = plugin_supports('mod', $moduleinfo->modulename, FEATURE_GRADE_HAS_GRADE, false);
    $hasoutcomes = plugin_supports('mod', $moduleinfo->modulename, FEATURE_GRADE_OUTCOMES, true);

    // Sync idnumber with grade_item.
    if ($hasgrades && $grade_item = grade_item::fetch(array('itemtype'=>'mod', 'itemmodule'=>$moduleinfo->modulename,
                 'iteminstance'=>$moduleinfo->instance, 'itemnumber'=>0, 'courseid'=>$course->id))) {
        if ($grade_item->idnumber != $moduleinfo->cmidnumber) {
            $grade_item->idnumber = $moduleinfo->cmidnumber;
            $grade_item->update();
        }
    }

    $transaction->allow_commit();

    return $moduleinfo;
}
