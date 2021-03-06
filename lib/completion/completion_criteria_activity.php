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
 * Course completion critieria - completion on activity completion
 *
 * @package   moodlecore
 * @copyright 2009 Catalyst IT Ltd
 * @author    Aaron Barnes <aaronb@catalyst.net.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_criteria_activity extends completion_criteria {

    /**
     * Criteria type constant
     * @var int
     */
    public $criteriatype = COMPLETION_CRITERIA_TYPE_ACTIVITY;

    /**
     * Finds and returns a data_object instance based on params.
     * @static abstract
     *
     * @param array $params associative arrays varname=>value
     * @return object data_object instance or false if none found.
     */
    public static function fetch($params) {
        $params['criteriatype'] = COMPLETION_CRITERIA_TYPE_ACTIVITY;
        return self::fetch_helper('course_completion_criteria', __CLASS__, $params);
    }

    /**
     * Add appropriate form elements to the critieria form
     * @access  public
     * @param   object  $mform  Moodle forms object
     * @param   mixed   $data   optional
     * @return  void
     */
    public function config_form_display(&$mform, $data = null) {
        $mform->addElement('checkbox', 'criteria_activity['.$data->id.']', ucfirst(self::get_mod_name($data->module)).' - '.$data->name);

        if ($this->id) {
            $mform->setDefault('criteria_activity['.$data->id.']', 1);
        }
    }

    /**
     * Update the criteria information stored in the database
     * @access  public
     * @param   array   $data   Form data
     * @return  void
     */
    public function update_config(&$data) {
        global $DB;

        if (!empty($data->criteria_activity) && is_array($data->criteria_activity)) {

            $this->course = $data->id;

            foreach (array_keys($data->criteria_activity) as $activity) {

                $module = $DB->get_record('course_modules', array('id' => $activity));
                $this->module = self::get_mod_name($module->module);
                $this->moduleinstance = $activity;
                $this->id = NULL;
                $this->insert();
            }
        }
    }

    /**
     * Get module instance module type
     * @static
     * @access  public
     * @param   int     $type   Module type id
     * @return  string
     */
    public static function get_mod_name($type) {
        static $types;

        if (!is_array($types)) {
            global $DB;
            $types = $DB->get_records('modules');
        }

        return $types[$type]->name;
    }

    /**
     * Get module instance
     * @access  public
     * @return  object|false
     */
    public function get_mod_instance() {
        global $DB;

        return $DB->get_record_sql(
            "
                SELECT
                    m.*
                FROM
                    {{$this->module}} m
                INNER JOIN
                    {course_modules} cm
                 ON cm.id = {$this->moduleinstance}
                AND m.id = cm.instance
            "
        );
    }

    /**
     * Review this criteria and decide if the user has completed
     * @access  public
     * @param   object  $completion     The user's completion record
     * @param   boolean $mark           Optionally set false to not save changes to database
     * @return  boolean
     */
    public function review($completion, $mark = true) {
        global $DB;

        $course = $DB->get_record('course', array('id' => $completion->course));
        $cm = $DB->get_record('course_modules', array('id' => $this->moduleinstance));
        $info = new completion_info($course);

        $data = $info->get_data($cm, false, $completion->userid);

        // If the activity is complete
        if (in_array($data->completionstate, array(COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS))) {
            if ($mark) {
                $completion->mark_complete();
            }

            return true;
        }

        return false;
    }

    /**
     * Return criteria title for display in reports
     * @access  public
     * @return  string
     */
    public function get_title() {
        return get_string('activitiescompleted', 'completion');
    }

    /**
     * Return a more detailed criteria title for display in reports
     * @access  public
     * @return  string
     */
    public function get_title_detailed() {
        global $DB;
        $module = $DB->get_record('course_modules', array('id' => $this->moduleinstance));
        $activity = $DB->get_record($this->module, array('id' => $module->instance));

        return shorten_text(urldecode($activity->name));
    }

    /**
     * Return criteria type title for display in reports
     * @access  public
     * @return  string
     */
    public function get_type_title() {
        return get_string('activities', 'completion');
    }

    /**
     * Find user's who have completed this criteria
     * @access  public
     * @return  void
     */
    public function cron() {
        global $DB;

        // Get all users who meet this criteria
        $sql = '
            SELECT DISTINCT
                c.id AS course,
                cr.timeend AS date,
                cr.id AS criteriaid,
                ra.userid AS userid,
                mc.timemodified AS timecompleted
            FROM
                {course_completion_criteria} cr
            INNER JOIN
                {course} c
             ON cr.course = c.id
            INNER JOIN
                {context} con
             ON con.instanceid = c.id
            INNER JOIN
                {role_assignments} ra
              ON ra.contextid = con.id
            INNER JOIN
                {course_modules_completion} mc
             ON mc.coursemoduleid = cr.moduleinstance
            AND mc.userid = ra.userid
            LEFT JOIN
                {course_completion_crit_compl} cc
             ON cc.criteriaid = cr.id
            AND cc.userid = ra.userid
            WHERE
                cr.criteriatype = '.COMPLETION_CRITERIA_TYPE_ACTIVITY.'
            AND con.contextlevel = '.CONTEXT_COURSE.'
            AND c.enablecompletion = 1
            AND cc.id IS NULL
            AND (
                mc.completionstate = '.COMPLETION_COMPLETE.'
             OR mc.completionstate = '.COMPLETION_COMPLETE_PASS.'
                )
        ';

        // Loop through completions, and mark as complete
        $rs = $DB->get_recordset_sql($sql);
        foreach ($rs as $record) {

            $completion = new completion_criteria_completion((array)$record);
            $completion->mark_complete($record->timecompleted);
        }
        $rs->close();
    }

    /**
     * Return criteria progress details for display in reports
     * @access  public
     * @param   object  $completion     The user's completion record
     * @return  array
     */
    public function get_details($completion) {
        global $DB, $CFG;

        // Get completion info
        $course = new stdClass();
        $course->id = $completion->course;
        $info = new completion_info($course);

        $module = $DB->get_record('course_modules', array('id' => $this->moduleinstance));
        $data = $info->get_data($module, false, $completion->userid);

        $activity = $DB->get_record($this->module, array('id' => $module->instance));

        $details = array();
        $details['type'] = $this->get_title();
        $details['criteria'] = '<a href="'.$CFG->wwwroot.'/mod/'.$this->module.'/view.php?id='.$this->moduleinstance.'">'.$activity->name.'</a>';

        // Build requirements
        $details['requirement'] = array();

        if ($module->completion == 1) {
            $details['requirement'][] = get_string('markingyourselfcomplete', 'completion');
        } elseif ($module->completion == 2) {
            if ($module->completionview) {
                $details['requirement'][] = get_string('viewingactivity', 'completion', $this->module);
            }

            if ($module->completiongradeitemnumber) {
                $details['requirement'][] = get_string('achievinggrade', 'completion');
            }
        }

        $details['requirement'] = implode($details['requirement'], ', ');

        $details['status'] = '';

        return $details;
    }
}
