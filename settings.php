<?php

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot.'/blocks/courses_vicensvives/lib/vicensvives.php');

global $DB;
if ($ADMIN->fulltree) {

    require_once($CFG->dirroot.'/blocks/courses_vicensvives/settingslib.php');
    require_once($CFG->dirroot.'/blocks/courses_vicensvives/lib/vicensvives.php');

    $settings->add(new courses_vicensvives_setting_wscheck());

    $setting = new admin_setting_configtext('vicensvives_apiurl',
            get_string('apiurl', 'block_courses_vicensvives'), get_string('configapiurl', 'block_courses_vicensvives'),
            vicensvives_ws::WS_URL, PARAM_URL);
    $setting->set_updatedcallback('vicensvives_reset_token');
    $settings->add($setting);

    $setting = new admin_setting_configpasswordunmask('vicensvives_sharekey',
            get_string('sharekey', 'block_courses_vicensvives'), get_string('configsharekey', 'block_courses_vicensvives'), '');
    $setting->set_updatedcallback('vicensvives_reset_token');
    $settings->add($setting);

    $setting = new admin_setting_configpasswordunmask('vicensvives_sharepass',
            get_string('sharepass', 'block_courses_vicensvives'), get_string('configsharepass', 'block_courses_vicensvives'), '');
    $setting->set_updatedcallback('vicensvives_reset_token');
    $settings->add($setting);

    $selectnum = range(0, 100);
    $settings->add( new admin_setting_configselect('block_courses_vicensvives_maxcourses',
            get_string('maxcourses', 'block_courses_vicensvives'),
            get_string('configmaxcourses', 'block_courses_vicensvives'), 10, $selectnum));

    $settings->add(new admin_settings_coursecat_select('block_courses_vicensvives_defaultcategory',
            get_string('defaultcategory', 'block_courses_vicensvives'),
            get_string('configdefaultcategory', 'block_courses_vicensvives'), 1));

    $settings->add(new courses_vicensvives_setting_moodlews(
            get_string('configmoodlews', 'block_courses_vicensvives'),
            get_string('configmoodlewsdesc', 'block_courses_vicensvives')));
    $settings->add($setting);
}
