<?php
// NEW-SCHOOLAPP/include/result_helper.php

if (!defined('PORTAL_PATH')) exit;

class ResultHelper {
    /**
     * Get student's full name
     */
    public static function studentName($pdo, $admission_no, $school_id) {
        $stmt = $pdo->prepare("SELECT lastname, firstname, othername FROM sm_students WHERE school_id_number = ? AND admission_number = ? LIMIT 1");
        $stmt->execute([$school_id, $admission_no]);
        $row = $stmt->fetch();
        if ($row) {
            $parts = array_filter([$row['lastname'], $row['firstname'], $row['othername']]);
            return implode(' ', $parts);
        }
        return 'N/A';
    }

    /**
     * Get class name
     */
    public static function studentClassName($pdo, $class_code, $school_id) {
        $stmt = $pdo->prepare("SELECT class_name FROM sm_classes WHERE school_id_number = ? AND numeric_class_name = ? LIMIT 1");
        $stmt->execute([$school_id, $class_code]);
        return $stmt->fetchColumn() ?: 'N/A';
    }

    /**
     * Get term name
     */
    public static function termName($pdo, $term_id, $school_id) {
        $stmt = $pdo->prepare("SELECT term_name FROM sm_terms WHERE school_id_number = ? AND id_number = ? LIMIT 1");
        $stmt->execute([$school_id, $term_id]);
        return $stmt->fetchColumn() ?: 'N/A';
    }

    /**
     * Get subject name
     */
    public static function subjectName($pdo, $subject_code, $school_id) {
        $stmt = $pdo->prepare("SELECT subject_name FROM sm_subjects WHERE school_id_number = ? AND subject_code = ? LIMIT 1");
        $stmt->execute([$school_id, $subject_code]);
        $name = $stmt->fetchColumn();
        return $name ? "$name ($subject_code)" : "N/A";
    }

    /**
     * Get grade letter or remark comment based on score
     */
    public static function getScoreGrade($pdo, $score, $type, $school_id) {
        $stmt = $pdo->prepare("SELECT grade_name, grade_comment, mark_from, mark_upto FROM sm_grades WHERE school_id_number = ?");
        $stmt->execute([$school_id]);
        $rules = $stmt->fetchAll();
        
        foreach ($rules as $rule) {
            if ($score >= (int)$rule['mark_from'] && $score <= (int)$rule['mark_upto']) {
                return ($type === 'grade') ? $rule['grade_name'] : $rule['grade_comment'];
            }
        }
        return 'N/A';
    }

    /**
     * Automatic gender-adjusted principal remarks based on average score
     */
    public static function principalRemark($gender, $average_score) {
        $g = strtolower(trim($gender));
        if ($g === 'male') {
            $pronoun1 = 'He';
            $pronoun2 = 'His';
        } elseif ($g === 'female') {
            $pronoun1 = 'She';
            $pronoun2 = 'Her';
        } else {
            $pronoun1 = 'He/She';
            $pronoun2 = 'His/Her';
        }

        if ($average_score >= 70 && $average_score <= 100) {
            return "$pronoun1 performs independent work with confidence and focus.";
        } elseif ($average_score >= 60 && $average_score < 70) {
            return "$pronoun1 is focused during classroom activities and willingly participates in class discussions.";
        } elseif ($average_score >= 50 && $average_score < 60) {
            return "$pronoun1 is an active participant in class.";
        } elseif ($average_score >= 45 && $average_score < 50) {
            return "$pronoun1 needs frequent reminders to be attentive during class.";
        } elseif ($average_score >= 40 && $average_score < 45) {
            return "$pronoun1 needs to improve on " . strtolower($pronoun2) . " performance.";
        } else {
            return "$pronoun2 result is not impressive, " . strtolower($pronoun1) . " needs to improve.";
        }
    }

    /**
     * Check if a student has outstanding fee payments for a class & session
     */
    public static function checkPayment($pdo, $admission_no, $class_code, $session, $school_id) {
        // Fetch required fees for the class & session
        $stmt = $pdo->prepare("SELECT fee_type_id FROM sm_fee_lists WHERE school_id_number = ? AND numeric_class_name = ? AND session = ?");
        $stmt->execute([$school_id, $class_code, $session]);
        $required_fees = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($required_fees)) {
            return ['fee_status' => true, 'fee_overdue' => []];
        }

        // Check paid fee records
        $placeholders = implode(',', array_fill(0, count($required_fees), '?'));
        $query = "SELECT fee_type_id FROM sm_fee_payment_lists 
                  WHERE school_id_number = ? AND admission_number = ? AND numeric_class_name = ? AND session = ? 
                  AND fee_type_id IN ($placeholders) AND status = 'paid'";
        $stmtPay = $pdo->prepare($query);
        $params = array_merge([$school_id, $admission_no, $class_code, $session], $required_fees);
        $stmtPay->execute($params);
        $paid_fees = $stmtPay->fetchAll(PDO::FETCH_COLUMN);

        $unpaid_fee_ids = array_diff($required_fees, $paid_fees);

        if (empty($unpaid_fee_ids)) {
            return ['fee_status' => true, 'fee_overdue' => []];
        }

        // Fetch names of unpaid fee types
        $placeholdersUnpaid = implode(',', array_fill(0, count($unpaid_fee_ids), '?'));
        $stmtNames = $pdo->prepare("SELECT fee_name FROM sm_fee_types WHERE school_id_number = ? AND id_number IN ($placeholdersUnpaid)");
        $stmtNames->execute(array_merge([$school_id], array_values($unpaid_fee_ids)));
        $unpaid_names = $stmtNames->fetchAll(PDO::FETCH_COLUMN);

        return ['fee_status' => false, 'fee_overdue' => $unpaid_names];
    }
}
