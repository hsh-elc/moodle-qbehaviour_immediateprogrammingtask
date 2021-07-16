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
 * Question behaviour where the student can submit questions one at a
 * time for immediate feedback.
 *
 * @package    qbehaviour
 * @subpackage immediatefeedback
 * @copyright  2009 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Question behaviour for immediate feedback.
 *
 * Each question has a submit button next to it which the student can use to
 * submit it. Once the qustion is submitted, it is not possible for the
 * student to change their answer any more, but the student gets full feedback
 * straight away.
 *
 * @copyright  2009 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbehaviour_immediateprogrammingtask extends question_behaviour_with_save {

    const IS_ARCHETYPAL = true;

    public function is_compatible_question(question_definition $question) {
        return $question instanceof qtype_programmingtask_question;
    }

    public function can_finish_during_attempt() {
        return true;
    }

    public function get_min_fraction() {
        return $this->question->get_min_fraction();
    }

    public function get_expected_data() {
        if ($this->qa->get_state()->is_active()) {
            return array(
                'submit' => PARAM_BOOL,
            );
        }
        return parent::get_expected_data();
    }

    public function get_state_string($showcorrectness) {
        $state = $this->qa->get_state();
        if ($state == question_state::$todo) {
            return get_string('notcomplete', 'qbehaviour_immediatefeedback');
        } else {
            return parent::get_state_string($showcorrectness);
        }
    }

    public function get_right_answer_summary() {
        return $this->question->get_right_answer_summary();
    }

    public function process_action(question_attempt_pending_step $pendingstep) {
        if ($pendingstep->has_behaviour_var('comment')) {
            return $this->process_comment($pendingstep);
        } else if ($pendingstep->has_behaviour_var('submit')) {
            return $this->process_submit($pendingstep);
        } else if ($pendingstep->has_behaviour_var('finish')) {
            return $this->process_finish($pendingstep);
        } else if ($pendingstep->has_behaviour_var('gradingresult')) {
            return $this->process_gradingresult($pendingstep);
        } else if ($pendingstep->has_behaviour_var('graderunavailable')) {
            return $this->process_graderunavailable($pendingstep);
        } {
            return $this->process_save($pendingstep);
        }
    }

    public function summarise_action(question_attempt_step $step) {
        if ($step->has_behaviour_var('comment')) {
            return $this->summarise_manual_comment($step);
        } else if ($step->has_behaviour_var('finish')) {
            return get_string('finished', 'qbehaviour_immediateprogrammingtask',
                    get_string('gradingsummary', 'qbehaviour_immediateprogrammingtask'));
        } else if ($step->has_behaviour_var('submit')) {
            return get_string('submitted', 'question',
                    get_string('gradingsummary', 'qbehaviour_immediateprogrammingtask'));
        } else if ($step->has_behaviour_var('gradingresult')) {
            return get_string('graded', 'qbehaviour_immediateprogrammingtask',
                    get_string('gradedsummary', 'qbehaviour_immediateprogrammingtask'));
        } else if ($step->has_behaviour_var('graderunavailable')) {
            return get_string('grading', 'qbehaviour_immediateprogrammingtask',
                    get_string('graderunavailable', 'qbehaviour_immediateprogrammingtask'));
        } else {
            return $this->summarise_save($step);
        }
    }

    /**
     * Only differs from parent implementation in that it sets a  flag on the first execution and
     * doesn't keep this step if the flag has already been set. This is important in the face of regrades.
     * When a submission is regraded the comment and the mark refer to the old version of the grading result,
     * therefore we don't include the comment and the mark in the regrading.
     * @global type $DB
     * @param \question_attempt_pending_step $pendingstep
     * @return bool
     */
    public function process_comment(\question_attempt_pending_step $pendingstep): bool {
        global $DB;
        if ($DB->record_exists('question_attempt_step_data',
                        array('attemptstepid' => $pendingstep->get_id(), 'name' => '-_appliedFlag'))) {
            return question_attempt::DISCARD;
        }

        $parentreturn = parent::process_comment($pendingstep);

        $pendingstep->set_behaviour_var('_appliedFlag', '1');
        return $parentreturn;
    }

    public function process_submit(question_attempt_pending_step $pendingstep) {
        global $DB;

        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        if (!$this->is_complete_response($pendingstep)) {
            $pendingstep->set_state(question_state::$invalid);
        } else {
            $response = $pendingstep->get_qt_data();
            if ($this->question->enablefilesubmissions) {
                $questionfilesaver = $pendingstep->get_qt_var('answer');
                if ($questionfilesaver instanceof question_file_saver) {
                    $responsefiles = $questionfilesaver->get_files();
                } else {
                    // We are in a regrade.
                    $record = $DB->get_record('question_usages', array('id' => $this->qa->get_usage_id()), 'contextid');
                    $qubacontextid = $record->contextid;
                    $responsefiles = $pendingstep->get_qt_files('answer', $qubacontextid);
                }
            }
            $freetextanswers = [];
            if ($this->question->enablefreetextsubmissions) {
                $autogeneratenames = $this->question->ftsautogeneratefilenames;
                for ($i = 0; $i < $this->question->ftsmaxnumfields; $i++) {
                    $text = $response["answertext$i"];
                    if ($text == '') {
                        continue;
                    }
                    $record = $DB->get_record('qtype_programmingtask_fts',
                            ['questionid' => $this->question->id, 'inputindex' => $i]);
                    $filename = $response["answerfilename$i"] ?? '';        // By default use submitted filename.
                    // Overwrite filename if necessary.
                    if ($record) {
                        if ($record->presetfilename) {
                            $filename = $record->filename;
                        } else if ($filename == '') {
                            $tmp = $i + 1;
                            $filename = "File$tmp.txt";
                        }
                    } else if ($autogeneratenames || $filename == '') {
                        $tmp = $i + 1;
                        $filename = "File$tmp.txt";
                    }
                    $freetextanswers[$filename] = $text;
                }
            }

            $state = $this->question->grade_response_asynch($this->qa, $responsefiles ?? [], $freetextanswers);
            $pendingstep->set_state($state);
            $pendingstep->set_new_response_summary($this->question->summarise_response($response));
        }
        return question_attempt::KEEP;
    }

    public function process_finish(question_attempt_pending_step $pendingstep) {
        global $DB;

        if ($this->qa->get_state()->is_finished()) {
            return question_attempt::DISCARD;
        }

        $response = $this->qa->get_last_step()->get_qt_data();
        if (!$this->question->is_gradable_response($response)) {
            $pendingstep->set_state(question_state::$gaveup);
            $pendingstep->set_fraction($this->get_min_fraction());
        } else {

            if ($this->question->enablefilesubmissions) {
                // We are in a regrade.
                $record = $DB->get_record('question_usages', array('id' => $this->qa->get_usage_id()), 'contextid');
                $qubacontextid = $record->contextid;
                $responsefiles = $this->qa->get_last_qt_files('answer', $qubacontextid);
            }

            if ($this->question->enablefreetextsubmissions) {
                $autogeneratenames = $this->question->ftsautogeneratefilenames;
                for ($i = 0; $i < $this->question->ftsmaxnumfields; $i++) {
                    $text = $response["answertext$i"];
                    if ($text == '') {
                        continue;
                    }
                    $record = $DB->get_record('qtype_programmingtask_fts',
                            ['questionid' => $this->question->id, 'inputindex' => $i]);
                    $filename = $response["answerfilename$i"] ?? '';        // By default use submitted filename.
                    // Overwrite filename if necessary.
                    if ($record) {
                        if ($record->presetfilename) {
                            $filename = $record->filename;
                        } else if ($filename == '') {
                            $tmp = $i + 1;
                            $filename = "File$tmp.txt";
                        }
                    } else if ($autogeneratenames || $filename == '') {
                        $tmp = $i + 1;
                        $filename = "File$tmp.txt";
                    }
                    $freetextanswers[$filename] = $text;
                }
            }

            $state = $this->question->grade_response_asynch($this->qa, $responsefiles ?? [], $freetextanswers ?? []);
            $pendingstep->set_state($state);
            $pendingstep->set_new_response_summary($this->question->summarise_response($response));
            $pendingstep->set_fraction($this->get_min_fraction());
        }
        return question_attempt::KEEP;
    }

    public function process_save(question_attempt_pending_step $pendingstep) {
        $status = parent::process_save($pendingstep);
        if ($status == question_attempt::KEEP &&
                $pendingstep->get_state() == question_state::$complete) {
            $pendingstep->set_state(question_state::$todo);
        }
        return $status;
    }

    public function process_gradingresult(question_attempt_pending_step $pendingstep) {
        global $DB;

        $processdbid = $pendingstep->get_qt_var('gradeprocessdbid');
        $exists = $DB->record_exists('qtype_programmingtask_grprcs', ['id' => $processdbid]);
        if (!$exists) {
            // It's a regrade, discard this *old* result.
            return question_attempt::DISCARD;
        }

        $score = $pendingstep->get_qt_var('score');
        $fraction = $score / $this->qa->get_max_mark();

        $pendingstep->set_fraction($fraction);
        $pendingstep->set_state(question_state::graded_state_for_fraction($fraction));
        $pendingstep->set_new_response_summary($this->question->summarise_response($pendingstep->get_all_data()));

        // If this is the real result for a regrade we should update the quiz_overview_regrades table
        // to properly display the new result.
        $regraderecord = $DB->get_record('quiz_overview_regrades',
                ['questionusageid' => $this->qa->get_usage_id(), 'slot' => $this->qa->get_slot()]);
        if ($regraderecord) {
            $regraderecord->newfraction = $fraction;
            $DB->update_record('quiz_overview_regrades', $regraderecord);
        }

        return question_attempt::KEEP;
    }

    public function process_graderunavailable(question_attempt_pending_step $pendingstep) {
        global $DB;

        $processdbid = $pendingstep->get_qt_var('gradeprocessdbid');
        $exists = $DB->record_exists('qtype_programmingtask_grprcs', ['id' => $processdbid]);
        if (!$exists) {
            // It's a regrade, discard this old step.
            return question_attempt::DISCARD;
        }

        $pendingstep->set_state(question_state::$needsgrading);

        return question_attempt::KEEP;
    }

}
