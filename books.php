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
require_once($CFG->dirroot.'/lib/resourcelib.php');
require_once($CFG->dirroot.'/lib/tablelib.php');
require_once($CFG->dirroot.'/lib/formslib.php');
require_once($CFG->dirroot.'/blocks/courses_vicensvives/locallib.php');


class books_form extends moodleform {

    public function definition() {
        global $CFG;

        $mform = $this->_form;
        $levels = $this->_customdata['levels'];
        $subjects = $this->_customdata['subjects'];

        $mform->addElement('header', 'search', get_string('search'));
        if (method_exists($mform, 'setExpanded')) {
            $mform->setExpanded('search', false);
        }

        $string = get_string('fullname', 'block_courses_vicensvives');
        $mform->addElement('text', 'fullname', $string);
        $mform->setType('fullname', PARAM_TEXT);

        $string = get_string('subject', 'block_courses_vicensvives');
        $mform->addElement('select', 'idSubject', $string, $subjects);
        $mform->setType('idSubject', PARAM_INT);

        $string = get_string('idLevel', 'block_courses_vicensvives');
        $mform->addElement('select', 'idlevel', $string, $levels);
        $mform->setType('idlevel', PARAM_INT);

        $string = get_string('isbn', 'block_courses_vicensvives');
        $mform->addElement('text', 'isbn', $string);
        $mform->setType('isbn', PARAM_TEXT);

        $buttonarray = array();
        $buttonarray[] = &$mform->createElement('submit', 'search', get_string('search'));
        $buttonarray[] = &$mform->createElement('cancel', 'reset', get_string('reset'));
        $mform->addGroup($buttonarray, 'buttonar', '', array(' '), false);
    }
}

function str_contains($haystack, $needle) {
    // Normaliza el texto a carñacteres ASCII en mínuscula y sin espacios duplicados
    $normalize = function($text) {
        $text = textlib::specialtoascii($text);
        $text = textlib::strtolower($text);
        $text = preg_replace('/\s+/', ' ', trim($text));
        return $text;
    };
    $haystack = $normalize($haystack);
    $needle = $normalize($needle);
    return textlib::strpos($haystack, $needle) !== false;
}

require_login(null, false);

$context = context_coursecat::instance($CFG->block_courses_vicensvives_defaultcategory);
require_capability('moodle/course:create', $context);

$baseurl = new moodle_url('/blocks/courses_vicensvives/books.php');

$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('books', 'block_courses_vicensvives'));
$PAGE->set_heading(get_string('books', 'block_courses_vicensvives'));

$viewbooks = new moodle_url('/blocks/courses_vicensvives/books.php');
$PAGE->navbar->add(get_string('blocks'));
$PAGE->navbar->add(get_string('pluginname', 'block_courses_vicensvives'));
$PAGE->navbar->add(get_string('books', 'block_courses_vicensvives'), $viewbooks);

$ws = new vicensvives_ws();

$lang = $CFG->lang == 'ca' ? 'ca' : 'es';

try {
    $levels = array(0 => '');
    foreach ($ws->levels($lang) as $level) {
        $levels[$level->idLevel] = $level->shortname;
    }

    $subjects = array(0 => '');
    foreach ($ws->subjects($lang) as $subject) {
        $subjects[$subject->idSubject] = $subject->name;
    }

    $all_books = $ws->books();

} catch (vicensvives_ws_error $e) {
    echo $OUTPUT->header();
    echo html_writer::tag('p', $e->getMessage(), array('class' => 'alert alert-error'));
    echo $OUTPUT->footer();
    exit;
}

$filtered_books = array();

$customdata = array('levels' => $levels, 'subjects' => $subjects);
$form = new books_form(null, $customdata, 'get');

if ($form->is_cancelled()) {
    redirect($baseurl);
} elseif ($data = $form->get_data()) {

    foreach ($all_books as $book) {
        if (!empty($data->fullname) and !str_contains($book->fullname, $data->fullname)) {
            continue;
        }
        if (!empty($data->idSubject) and $book->idSubject != $data->idSubject) {
            continue;
        }
        if (!empty($data->idlevel) and $book->idLevel != $data->idlevel) {
            continue;
        }
        if (!empty($data->isbn) and !str_contains($book->isbn, $data->isbn)) {
            continue;
        }
        $filtered_books[] = $book;
    }
} else {
    $filtered_books = $all_books;
}


echo $OUTPUT->header();
echo $OUTPUT->heading('');

$form->display();

if ($filtered_books) {
    $a = array('total' => count($all_books), 'found' => count($filtered_books));
    $string = get_string('searchresult', 'block_courses_vicensvives', $a);
} else {
    $string = get_string('searchempty', 'block_courses_vicensvives', count($all_books));
}
echo $OUTPUT->heading($string);

$table = new flexible_table('vicensvives_books');
$table->define_baseurl($baseurl);
$table->define_columns(array('name', 'subject', 'level', 'isbn', 'actions'));
$table->set_attribute('class', 'vicensvives_books');
$table->column_class('actions', 'vicensvives_actions');
$table->define_headers(array(
    get_string('fullname', 'block_courses_vicensvives'),
    get_string('subject', 'block_courses_vicensvives'),
    get_string('idLevel', 'block_courses_vicensvives'),
    get_string('isbn', 'block_courses_vicensvives'),
    get_string('actions','block_courses_vicensvives'),
));
$table->sortable(true, 'name');
$table->no_sorting('actions');
$table->pagesize(50, count($filtered_books));
$table->setup();

$rows = array();
foreach ($filtered_books as $book) {
    $row = new stdClass;
    $row->name = $book->fullname;
    $row->subject = isset($subjects[$book->idSubject]) ? $subjects[$book->idSubject] : '';
    $row->level = isset($levels[$book->idLevel]) ? $levels[$book->idLevel] : '';
    $row->isbn = $book->isbn;
    $params = array('bookid' => $book->idBook, 'sesskey' => sesskey());
    $addurl = new moodle_url('/blocks/courses_vicensvives/addbook.php', $params);
    $title = get_string('addcourse', 'block_courses_vicensvives');
    $icon = html_writer::empty_tag('img', array('src' => $OUTPUT->pix_url('t/addfile'), 'alt' => $title));
    $row->actions = html_writer::link($addurl, $icon, array('title' => $title));
    $rows[] = $row;
}

// Ordenación
foreach ($table->get_sort_columns() as $column => $order) {
    collatorlib::asort_objects_by_property($rows, $column);
    if ($order == SORT_DESC) {
        $rows = array_reverse($rows);
    }
    break; // Ordenación sólo por el primer criterio
}

// Paginación
$rows = array_slice($rows, $table->get_page_start(), $table->get_page_size());

foreach ($rows as $row) {
    $table->add_data(array($row->name, $row->subject, $row->level, $row->isbn, $row->actions));
}

$table->finish_output();

echo $OUTPUT->footer();
