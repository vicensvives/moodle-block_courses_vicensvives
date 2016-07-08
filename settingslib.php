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

require_once($CFG->libdir.'/adminlib.php');

/**
 * Parámetro HTML para comprovar la conexión con el webservice de Vicens Vives.
 */
class courses_vicensvives_setting_wscheck extends admin_setting {

    private $error;

    public function __construct() {
        parent::__construct('vicensvives_wscheck', '', '', null);
    }

    public function get_setting() {
        return true;
    }

    public function write_setting($data) {
        return '';
    }

    public function output_html($data, $query='') {
        $ws = new vicensvives_ws();
        try {
            $ws->books();
        } catch (vicensvives_ws_error $e) {
            return html_writer::tag('div', $e->getMessage(), array('class' => 'alert alert-error'));
        }
        return '';
    }
}

/**
 * Parámetro para configurar el web service de Moodle y enviar el token a Vicens Vives.
 */
class courses_vicensvives_setting_moodlews extends admin_setting_configcheckbox {

    const NAME = 'vicensvives_moodlews';
    const USERNAME = 'wsvicensvives';
    const FIRSTNAME = 'Web Service';
    const LASTNAME = 'Vicens Vives';
    const SERVICE = 'local_wsvicensvives';
    const PROTOCOL = 'rest';
    const ROLESHORTNAME = 'wsvicensvives';
    const ROLENAME = 'Web Service Vicens Vives';

    private static $capabilities = array(
        'webservice/rest:use',
        'moodle/grade:edit',
    );

    public function __construct($visiblename, $description) {
        parent::__construct(self::NAME, $visiblename, $description, '0', '1', '0');
    }

    public function get_setting() {
        // En versiones anteriores no se guardaba el estado del parámetro, pero se usaba un parámetro falso para indicar
        // si se había configurado o no. Si existe migramos al nuevo parámetro.
        if (get_config($this->plugin, $this->name . '_settingused') == '1') {
            unset_config($this->name . '_settingused', $this->plugin);
            $value = self::is_enabled() ? $this->yes : $this->no;
            set_config($this->name, $value);
            return $value;
        }

        $value = get_config($this->plugin, $this->name);
        if ($value == $this->yes and !self::is_enabled()) {
            $value = $this->no;
        }
        return $value;
    }

    public function write_setting($data) {
        if ((string) $data === $this->yes) {
            $error = self::enable();
            if ($error !== '') {
                $data = $this->no;
            }
        } else {
            $error = self::disable();
        }

        set_config($this->name, $data, $this->plugin);

        return $error;
    }

    public static function get_service() {
        global $DB;
        $conditions = array('component' => self::SERVICE);
        return $DB->get_record('external_services', $conditions);
    }

    private static function disable() {
        global $DB;

        $service = self::get_service();
        $userid = self::get_user_id();

        // Elimina el token
        if ($service and $userid) {
            $conditions = array('externalserviceid' => $service->id, 'userid' => $userid);
            $DB->delete_records('external_tokens', $conditions);
        }

        return '';
    }

    private static function enable() {
        global $CFG, $DB, $USER;

        require_once("$CFG->dirroot/user/lib.php");

        $context = context_system::instance();

        // Web services activados
        set_config('enablewebservices', true);

        // Protocolo REST
        $protocols = explode(',', get_config('core', 'webserviceprotocols'));
        if (!in_array(self::PROTOCOL, $protocols)) {
            $protocols[] = self::PROTOCOL;
            set_config('webserviceprotocols', trim(implode(',', $protocols), ','));
        }

        // Servicio
        $service = self::get_service();
        if (!$service->enabled) {
            $DB->set_field('external_services', 'enabled', 1, array('id' => $service->id));
        }

        // Usuario
        $userid = self::get_user_id();
        if (!$userid) {
            $user = new stdClass;
            $user->username = self::USERNAME;
            $user->firstname = self::FIRSTNAME;
            $user->lastname = self::LASTNAME;
            $user->mnethostid = $CFG->mnet_localhost_id;
            $user->auth = 'webservice';
            $user->confirmed = true;
            $userid = user_create_user($user, false);
        }

        // Rol
        $roleid = self::get_role_id();
        if (!$roleid) {
            $roleid = create_role(self::ROLENAME, self::ROLESHORTNAME, '');
            set_role_contextlevels($roleid, array(CONTEXT_SYSTEM));
        }
        foreach (self::$capabilities as $name) {
            assign_capability($name, CAP_ALLOW, $roleid, $context, true);
        }
        $context->mark_dirty();
        role_assign($roleid, $userid, $context->id);

        // Token
        $token = self::get_token($service, $userid);

        // Crea el token en activar
        if (!$token) {
            $token = md5(uniqid(rand(), 1));
            $record = new stdClass();
            $record->token = $token;
            $record->tokentype = EXTERNAL_TOKEN_PERMANENT;
            $record->userid = $userid;
            $record->externalserviceid = $service->id;
            $record->contextid = $context->id;
            $record->creatorid = $USER->id;
            $record->timecreated = time();
            $DB->insert_record('external_tokens', $record);
        }

        // Emvía el token a Vicens Vives
        try {
            $ws = new vicensvives_ws();
            $ws->send_token($token);
        } catch (vicensvives_ws_error $e) {
            if ($e->errorcode == 'wssitemismatch' or $e->errorcode == 'wsunknownerror') {
                return $e->getMessage();
            }
        }

        return '';
    }

    private static function get_role_id() {
        global $DB;
        $conditions = array('shortname' => self::ROLESHORTNAME);
        return $DB->get_field('role', 'id', $conditions);
    }

    private static function get_token($service, $userid) {
        global $DB;

        $context = context_system::instance();
        $conditions = array(
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
            'userid' => $userid,
            'externalserviceid' => $service->id,
            'contextid' => $context->id,
        );
        return $DB->get_field('external_tokens', 'token', $conditions);
    }

    private static function get_user_id() {
        global $CFG, $DB;
        $conditions = array(
            'username' => self::USERNAME,
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        );
        return $DB->get_field('user', 'id', $conditions);
    }

    private static function is_enabled() {
        global $CFG;

        // Web services activados
        if (empty($CFG->enablewebservices)) {
            return false;
        }

        // Protocolo REST
        $protocols = explode(',', get_config('core', 'webserviceprotocols'));
        if (!in_array(self::PROTOCOL, $protocols)) {
            return false;
        }

        // Servicio activado
        $service = self::get_service();
        if (!$service or !$service->enabled) {
            return false;
        }

        // Usuario
        $userid = self::get_user_id();
        if (!$userid) {
            return false;
        }

        // Rol
        $roleid = self::get_role_id();
        if (!$roleid) {
            return false;
        }
        $context = context_system::instance();
        $rolecaps = role_context_capabilities($roleid, $context);
        foreach (self::$capabilities as $name) {
            if (!isset($rolecaps[$name]) or $rolecaps[$name] != CAP_ALLOW) {
                return false;
            }
        }
        if (!user_has_role_assignment($userid, $roleid, $context->id)) {
            return false;
        }

        // Token
        if (!self::get_token($service, $userid)) {
            return false;
        }

        return true;
    }
}
