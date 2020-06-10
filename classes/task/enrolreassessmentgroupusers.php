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
 * A scheduled task for scripted database integrations - category creation.
 *
 * @package    local_createcohorttables - template
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_enrolreassessmentgroupusers\task;
use stdClass;
use coursecat;

defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot.'/local/extdb/classes/task/extdb.php');
require_once($CFG->dirroot.'/group/lib.php');

/**
 * A scheduled task for scripted external database integrations.
 *
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrolreassessmentgroupusers extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'local_enrolreassessmentgroupusers');
    }

    /**
     * Run sync.
     */
    public function execute() {

        global $CFG, $DB;
        $externaldb = new \local_extdb\extdb();
        $name = $externaldb->get_name();
        $externaldbtype = $externaldb->get_config('dbtype');
        $externaldbhost = $externaldb->get_config('dbhost');
        $externaldbname = $externaldb->get_config('dbname');
        $externaldbencoding = $externaldb->get_config('dbencoding');
        $externaldbsetupsql = $externaldb->get_config('dbsetupsql');
        $externaldbsybasequoting = $externaldb->get_config('dbsybasequoting');
        $externaldbdebugdb = $externaldb->get_config('dbdebugdb');
        $externaldbuser = $externaldb->get_config('dbuser');
        $externaldbpassword = $externaldb->get_config('dbpass');

        // Tables relating to reassessment groups in the integrations table.
        $usrenrolgrouptab = get_string('usr_data_student_assessment', 'local_enrolreassessmentgroupusers');
        $grouptab = get_string('reassessmentgrouptable', 'local_enrolreassessmentgroupusers');

        // SB Code Specific to plugin needs changing.
        // Database connection and setup checks.
        // Check connection and label Db/Table in cron output for debugging if required.
        if (!$externaldbtype) {
            echo 'Database not defined.<br>';
            return 0;
        } else {
            echo 'Database: ' . $externaldbtype . '<br>';
        }
        // Check remote assessments table - usr_data_assessments.
        if (!$usrenrolgrouptab) {
            echo 'Levels Table not defined.<br>';
            return 0;
        } else {
            echo 'Levels Table: ' . $usrenrolgrouptab . '<br>';
        }
        // Check remote student grades table - usr_data_student_assessments.
        if (!$grouptab) {
            echo 'Categories Table not defined.<br>';
            return 0;
        } else {
            echo 'Categories Table: ' . $grouptab . '<br>';
        }

        // SB end of checks for custom plugin.

        // DB check.
        echo 'Starting connection...<br>';

        // Report connection error if occurs.
        if (!$extdb = $externaldb->db_init(
            $externaldbtype,
            $externaldbhost,
            $externaldbuser,
            $externaldbpassword,
            $externaldbname)) {
            echo 'Error while communicating with external database <br>';
            return 1;
        }

        // Blank arrays initialised for groups and users.
        $groups = array();
        $users = array();
        // Query to get all reassessment groups.
        $groupdata = $DB->get_records_sql("SELECT * FROM {groups} WHERE name LIKE 'LE6905%/20%-R'");
        // Query to get all user records.
        $moodleusers = $DB->get_records_sql("SELECT * FROM {user} WHERE confirmed = 1 AND deleted != 1 
                                                AND email LIKE '%@connect.glos.ac.uk'");
        // Gets the groups from the reassessment group table in the integrations database.
        $sql = "SELECT * FROM " . $grouptab;
        if ($rs = $extdb->Execute($sql)) {
            if (!$rs->EOF) {
                while ($fields = $rs->FetchRow()) {
                    $fields = array_change_key_case($fields, CASE_LOWER);
                    $fields = $externaldb->db_decode($fields);
                    $groups[] = $fields;
                }
            }
            $rs->Close();
        } else {
            echo 'Error reading data from the external catlevel table, ' . $grouptab . '<br>';
            return 4;
            // Report error if required.
            $extdb->Close();
        }
        // single array of users student numbers for later comparison with student's with reassessment.
        $mu = array();
        $muid = array();
        foreach ($moodleusers as $m){
            $mu[] = $m->username;
            $muid[$m->username] = $m->id;
            }
        // Loops through the reassessment groups from the integrations database.
        foreach ($groups as $group) {
            $users = [];
            $sql = "SELECT DISTINCT student_code FROM " . $usrenrolgrouptab;
            if ($rs = $extdb->Execute($sql)) {
                if (!$rs->EOF) {
                    while ($fields = $rs->FetchRow()) {
                        $fields = array_change_key_case($fields, CASE_LOWER);
                        $fields = $externaldb->db_decode($fields);
                        $users[] = $fields;
                    }
                }
                $rs->Close();
            } else {
                // Report error if required.
                $extdb->Close();
                echo 'Error reading data from the external catlevel table, ' . $usrenrolgrouptab . '<br>';
                return 4;
            }
            // Loops through the moodle reassessment groups and compares these.
            foreach ($groupdata as $data) {
                $groupid = $data->id;
                echo "\n" .'groupid : ' .$groupid ."\n";
                // If the group exists in both the integrations reassessment table and the groups table then go on
                // to check the users.
                if ($data->name == $group['group_name']) {
                    // Loop through the users from the integrations table.
                    foreach ($users as $user) {
                        // pad student numbers less than seven characters in length with leading zeros
                        $stu_idnumber = $user['student_code'];
                        while (strlen($stu_idnumber) < 7){
                            $stu_idnumber = '0' . $stu_idnumber;
                        }
                        if (strlen($stu_idnumber) != 7 ) {
                            echo 'Not 7 char: ' . $stu_idnumber;
                        }
                        // Add the s to the start of the student code as required to match with username
                        // in user table in moodle.
                        $user['student_code'] = "s" . $stu_idnumber;
                        echo "\n" .'user if in array : ' .$user['student_code'] ."\n";
                        // Loop through moodle users.
                        // Checks to see if the user exists in both the integrations database student
                        // assessment table and the moodle user table.
                        // echo 'in_array : ' .in_array($user['student_code'], $mu) .' student_code : ' .$user['student_code'] .' $mu : ';
                        // print_r($mu);
                        // echo "\n";
                        if (in_array($user['student_code'], $mu)) {
                            // Store the user id from moodle in the user variable.
                            // echo 'in_array : ' .$user['student_code'] .' | ' .$mu ."\n";
                            $username = $user['student_code'];
                            $user['id'] = $muid[$username];
                            echo "\n" .'user if in array : ' .$username ."\n";
                            // Check to make sure user id is not empty.
                            if (!(empty($user['id'] ) )) {
                                // Additional check to make sure neither group id or user id are set to null.
                                if ($groupid != null && $user['id'] != null) {
                                    // Convert both numbers to integer in case these are strings.
                                    $data->id = intval($data->id);
                                    $user['id'] = intval($user['id']);
                                    echo "\n" .'user if not empty : ' .$user[id] ."\n";
                                    // User moodle built in function to add group members to the required groups by
                                    // passing in the group id as data->id and the user id as $user['id'].
                                    groups_add_member($data->id, $user['id']);
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
