<?php

namespace block_courses_vicensvives\event;

class webservice_called extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    public static function get_name() {
        return get_string('webservicecalled', 'block_courses_vicensvives');
    }

    public function get_description() {
        return 'Se ha llamado el webservice: ' . $this->get_info();
    }

    public function get_legacy_logdata() {
        $info = $this->get_info();

        if (strlen($info) > 255) {
            $info = substr($info, 0, 252) . '...';
        }

        return array($this->courseid, 'vicensvives', 'webservice', $this->other['script'], $info);
    }

    private function get_info() {
        $method = strtoupper($this->other['method']);
        $path = $this->other['path'];
        $status = $this->other['status'];
        $info = "$method $path => $status";
        if (!empty($this->other['message'])) {
            $info .= ' ' . $this->other['message'];
        }
        return $info;
    }
}
