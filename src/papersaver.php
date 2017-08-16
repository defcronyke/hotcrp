<?php
// papersaver.php -- HotCRP helper for mapping requests to JSON
// HotCRP is Copyright (c) 2008-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperSaver {
    static private $list = [];

    static function register($prio, PaperSaver $saver) {
        self::$list[] = [$prio, count(self::$list), $saver];
        usort(self::$list, function ($a, $b) {
            if ($a[0] != $b[0])
                return $a[0] - $b[0];
            else
                return $a[1] - $b[1];
        });
    }
    static function apply_all(Contact $user, $prow, $opj, Qrequest $qreq, $action) {
        $pj = PaperStatus::clone_json($opj);
        if (!isset($pj->pid))
            $pj->pid = -1;
        foreach (self::$list as $fn)
            $fn[2]->apply($user, $pj, $prow, $opj, $qreq, $action);
        return $pj;
    }
    static function diffs_all(Contact $user, $pj1, $pj2) {
        $diffs = [];
        foreach (self::$list as $fn)
            $fn[2]->diffs($diffs, $user, $pj1, $pj2);
        return $diffs;
    }

    function apply(Contact $user, $pj, $prow, $opj, Qrequest $qreq, $action) {
    }
    function diffs(&$diffs, Contact $user, $pj1, $pj2) {
    }

    static function json_encode_nonempty($j) {
        $j = json_encode_db($j);
        if ($j === "{}")
            $j = "null";
        return $j;
    }

    static function replace_contacts($pj, $qreq) {
        $pj->contacts = array();
        foreach ($qreq as $k => $v)
            if (str_starts_with($k, "contact_")) {
                $email = html_id_decode(substr($k, 8));
                $pj->contacts[] = $email;
            } else if (str_starts_with($k, "newcontact_email")
                       && trim($v) !== ""
                       && trim($v) !== "Email") {
                $suffix = substr($k, strlen("newcontact_email"));
                $email = trim($v);
                $name = $qreq["newcontact_name$suffix"];
                if ($name === "Name")
                    $name = "";
                $pj->contacts[] = (object) ["email" => $email, "name" => $name];
            }
    }
}

class Default_PaperSaver extends PaperSaver {
    function apply(Contact $user, $pj, $prow, $opj, Qrequest $qreq, $action) {
        $admin = $prow ? $user->can_administer($prow) : $user->privChair;

        // Title, abstract, collaborators
        foreach (array("title", "abstract", "collaborators") as $k)
            if (isset($qreq[$k]))
                $pj->$k = UnicodeHelper::remove_f_ligatures($qreq[$k]);

        // Authors
        $bad_author = ["name" => "Name", "email" => "Email", "aff" => "Affiliation"];
        $authors = array();
        foreach ($qreq as $k => $v)
            if (preg_match('/\Aau(name|email|aff)(\d+)\z/', $k, $m)
                && ($v = simplify_whitespace($v)) !== ""
                && $v !== $bad_author[$m[1]]) {
                $au = $authors[$m[2]] = (get($authors, $m[2]) ? : (object) array());
                $x = ($m[1] == "aff" ? "affiliation" : $m[1]);
                $au->$x = $v;
            }
        // some people are idiots
        foreach ($authors as $au)
            if (isset($au->affiliation) && validate_email($au->affiliation)) {
                $aff = $au->affiliation;
                if (!isset($au->email)) {
                    $au->email = $aff;
                    unset($au->affiliation);
                } else if (!validate_email($au->email)) {
                    if (!isset($au->name) || strpos($au->name, " ") === false) {
                        $au->name = trim(get($au, "name", "") . " " . $au->email);
                        $au->email = $aff;
                        unset($au->affiliation);
                    } else {
                        $au->affiliation = $au->email;
                        $au->email = $aff;
                    }
                }
            }
        if (!empty($authors)) {
            ksort($authors, SORT_NUMERIC);
            $pj->authors = array_values($authors);
        }

        // Contacts
        if ($qreq->setcontacts || $qreq->has_contacts)
            PaperSaver::replace_contacts($pj, $qreq);
        else if (!$opj)
            $pj->contacts = array($user);

        // Status
        if ($action === "submit")
            $pj->submitted = true;
        else if ($action === "final")
            $pj->final_submitted = $pj->submitted = true;
        else
            $pj->submitted = false;

        // Paper upload
        if ($qreq->has_file("paperUpload")) {
            if ($action === "final")
                $pj->final = DocumentInfo::make_file_upload($pj->pid, DTYPE_FINAL, $qreq->file("paperUpload"));
            else if ($action === "update" || $action === "submit")
                $pj->submission = DocumentInfo::make_file_upload($pj->pid, DTYPE_SUBMISSION, $qreq->file("paperUpload"));
        }

        // Blindness
        if ($action !== "final" && $user->conf->subBlindOptional())
            $pj->nonblind = !$qreq->blind;

        // Topics
        if ($qreq->has_topics) {
            $pj->topics = (object) array();
            foreach ($user->conf->topic_map() as $tid => $tname)
                if (+$qreq["top$tid"] > 0)
                    $pj->topics->$tname = true;
        }

        // Options
        if (!isset($pj->options))
            $pj->options = (object) [];
        foreach ($user->conf->paper_opts->option_list() as $o)
            if ($qreq["has_opt$o->id"]
                && (!$o->final || $action === "final")) {
                $okey = $o->abbreviation();
                $pj->options->$okey = $o->parse_request(get($pj->options, $okey), $qreq, $user, $pj);
            }
        if (!count(get_object_vars($pj->options)))
            unset($pj->options);

        // PC conflicts
        if ($user->conf->setting("sub_pcconf")
            && ($action !== "final" || $admin)
            && $qreq->has_pcconf) {
            $pj->pc_conflicts = (object) array();
            foreach ($user->conf->pc_members() as $pcid => $pc) {
                $ctype = Conflict::constrain_editable($qreq["pcc$pcid"], $admin);
                if ($ctype) {
                    $email = $pc->email;
                    $pj->pc_conflicts->$email = Conflict::$type_names[$ctype];
                }
            }
        }
    }

    function diffs(&$diffs, Contact $user, $pj1, $pj2) {
        if (!$pj2) {
            $diffs["new"] = true;
            return;
        }

        foreach (array("title", "abstract", "collaborators") as $k)
            if (get_s($pj1, $k) !== get_s($pj2, $k))
                $diffs[$k] = true;

        if (!$this->same_authors($pj1, $pj2))
            $diffs["authors"] = true;

        if (self::json_encode_nonempty(get($pj1, "topics"))
            !== self::json_encode_nonempty(get($pj2, "topics")))
            $diffs["topics"] = true;

        $opt1 = get($pj1, "options", (object) []);
        $opt2 = get($pj2, "options", (object) []);
        foreach ($user->conf->paper_opts->option_list() as $o) {
            $oabbr = $o->abbreviation();
            if (isset($opt1->$oabbr)) {
                $same = isset($opt2->$oabbr)
                    && json_encode_db($opt1->$oabbr) === json_encode_db($opt2->$oabbr);
            } else
                $same = !isset($opt2->$oabbr);
            if (!$same)
                $diffs[$oabbr] = true;
        }

        if ($user->conf->subBlindOptional()
            && !get($pj1, "nonblind") !== !get($pj2, "nonblind"))
            $diffs["nonblind"] = true;

        $pcc1 = self::json_encode_nonempty(get($pj1, "pc_conflicts"));
        $pcc2 = self::json_encode_nonempty(get($pj2, "pc_conflicts"));
        if ($pcc1 !== $pcc2)
            $diffs["pc_conflicts"] = true;

        if (json_encode(get($pj1, "submission")) !== json_encode(get($pj2, "submission")))
            $diffs["submission"] = true;
        if (json_encode(get($pj1, "final")) !== json_encode(get($pj2, "final")))
            $diffs["final"] = true;
    }

    private function same_authors($pj, $opj) {
        $pj_ct = count(get($pj, "authors"));
        $opj_ct = count(get($opj, "authors"));
        if ($pj_ct != $opj_ct)
            return false;
        for ($i = 0; $i != $pj_ct; ++$i)
            if (get($pj->authors[$i], "email") !== get($opj->authors[$i], "email")
                || get_s($pj->authors[$i], "affiliation") !== get_s($opj->authors[$i], "affiliation")
                || Text::name_text($pj->authors[$i]) !== Text::name_text($opj->authors[$i]))
                return false;
        return true;
    }
}

PaperSaver::register(0, new Default_PaperSaver);
