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
 * MFA management class.
 *
 * @package     tool_mfa
 * @author      Peter Burnett <peterburnett@catalyst-au.net>
 * @copyright   Catalyst IT
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_mfa;

defined('MOODLE_INTERNAL') || die();

class manager {

    /**
     * Displays a debug table with current factor information.
     */
    public static function display_debug_notification() {
        global $OUTPUT, $PAGE;

        if (!get_config('tool_mfa', 'debugmode')) {
            return;
        }

        $output = $OUTPUT->heading(get_string('debugmode:heading', 'tool_mfa'), 3);

        $table = new \html_table();
        $table->head = array(
            get_string('weight', 'tool_mfa'),
            get_string('factor', 'tool_mfa'),
            get_string('setup', 'tool_mfa'),
            get_string('achievedweight', 'tool_mfa'),
            get_string('status'),
        );
        $table->attributes['class'] = 'admintable generaltable';
        $table->colclasses = array(
            'text-right',
            '',
            '',
            'text-right',
            'text-center',
        );
        $factors = \tool_mfa\plugininfo\factor::get_enabled_factors();
        $userfactors = \tool_mfa\plugininfo\factor::get_active_user_factor_types();

        foreach ($factors as $factor) {

            $namespace = 'factor_'.$factor->name;
            $name = get_string('pluginname', $namespace);

            $achieved = $factor->get_state() == \tool_mfa\plugininfo\factor::STATE_PASS ? $factor->get_weight() : 0;
            $achieved = '+'.$achieved;

            // Setup.
            if ($factor->has_setup()) {
                $found = false;
                foreach ($userfactors as $userfactor) {
                    if ($userfactor->name == $factor->name) {
                        $found = true;
                    }
                }
                $setup = $found ? get_string('yes') : get_string('no');
            } else {
                $setup = get_string('na', 'tool_mfa');
            }

            // Status.
            $OUTPUT = $PAGE->get_renderer('tool_mfa');
            $state = $OUTPUT->get_state_badge($factor->get_state());

            $table->data[] = array(
                $factor->get_weight(),
                $name,
                $setup,
                $achieved,
                $state,
            );
        }

        $finalstate = self::passed_enough_factors()
            ? \tool_mfa\plugininfo\factor::STATE_PASS
            : \tool_mfa\plugininfo\factor::STATE_UNKNOWN;
        $table->data[] = array(
            '',
            '',
            '<b>' . get_string('overall', 'tool_mfa') . '</b>',
            self::get_total_weight(),
            $OUTPUT->get_state_badge($finalstate),
        );

        echo \html_writer::table($table);
    }

    /**
     * Returns the total weight from all factors currently enabled for user.
     *
     * @return int
     */
    public static function get_total_weight() {
        $totalweight = 0;
        $factors = \tool_mfa\plugininfo\factor::get_active_user_factor_types();

        foreach ($factors as $factor) {
            if ($factor->get_state() == \tool_mfa\plugininfo\factor::STATE_PASS) {
                $totalweight += $factor->get_weight();
            }
        }
        return $totalweight;
    }

    /**
     * Checks that provided factorid exists and belongs to current user.
     *
     * @param string $factorname
     * @param int $factorid
     * @param object $user
     * @return bool
     * @throws \dml_exception
     */
    public static function is_factorid_valid($factorname, $factorid, $user) {
        global $DB;
        $recordowner = $DB->get_field('factor_'.$factorname, 'userid', array('id' => $factorid));

        if (!empty($recordowner) && $recordowner == $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Function to display to the user that they cannot login, then log them out.
     * 
     * @return void
     */
    public static function cannot_login() {
        self::mfa_logout();
        print_error('error:notenoughfactors', 'tool_mfa', new moodle_url('/'));
    }

    /**
     * Logout user.
     *
     * @return void
     */
    public static function mfa_logout() {
        $authsequence = get_enabled_auth_plugins();
        foreach ($authsequence as $authname) {
            $authplugin = get_auth_plugin($authname);
            $authplugin->logoutpage_hook();
        }
        require_logout();
    }

    /**
     * Function to check the overall status of a user's authentication.
     * 
     * @return mixed a STATE variable from plugininfo
     */
    public static function check_status() {
        global $SESSION;
        
        // 1) check for any failures, if so, return state fail
        // 2) check passed enough factors, if so, return a pass
        // 3) else neutral state

        // 1
        $factors = \tool_mfa\plugininfo\factor::get_active_user_factor_types();
        $fail = false;
        foreach ($factors as $factor) {
            if ($factor->get_state() == \tool_mfa\plugininfo\factor::STATE_FAIL) {
                $fail = true;
            }
        }
        if ($fail) {
            return \tool_mfa\plugininfo\factor::STATE_FAIL;
        }

        // 2
        // Check if pass state is already set, else, set pass state
        if (isset($SESSION->tool_mfa_authenticated) && $SESSION->tool_mfa_authenticated) {
            return \tool_mfa\plugininfo\factor::STATE_PASS;
        } else if (self::passed_enough_factors()) {
            self::set_pass_state();
            return \tool_mfa\plugininfo\factor::STATE_PASS;
        }

        // 3
        return \tool_mfa\plugininfo\factor::STATE_NEUTRAL;
    }

    /**
     * Checks whether user has passed enough factors to be allowed in
     */
    public static function passed_enough_factors() {
        $totalweight = \tool_mfa\manager::get_total_weight();
        if ($totalweight >= 100) {
            return true;
        }

        return false;
    }

    public static function set_pass_state() {
        global $SESSION, $USER;
        $SESSION->tool_mfa_authenticated = true;

        $event = \tool_mfa\event\user_passed_mfa::user_passed_mfa_event($USER);
        $event->trigger();
    }
}

