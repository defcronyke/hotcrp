<?php
// test/setup.php -- HotCRP helper file to initialize tests
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

require_once(preg_replace('/\/test\/[^\/]+/', '/src/siteloader.php', __FILE__));
define("HOTCRP_OPTIONS", SiteLoader::find("test/options.php"));
define("HOTCRP_TESTHARNESS", true);
ini_set("error_log", "");
ini_set("log_errors", "0");
ini_set("display_errors", "stderr");
ini_set("assert.exception", "1");
require_once(SiteLoader::find("src/init.php"));
$Conf->set_opt("disablePrintEmail", true);
$Conf->set_opt("postfixEOL", "\n");

function die_hard($message) {
    fwrite(STDERR, $message);
    exit(1);
}

// Record mail in MailChecker.
class MailChecker {
    static public $print = false;
    static public $preps = [];
    static public $messagedb = [];
    static function send_hook($fh, $prep) {
        $prep->landmark = "";
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $trace) {
            if (isset($trace["file"]) && preg_match(',/test\d,', $trace["file"])) {
                if (str_starts_with($trace["file"], SiteLoader::$root)) {
                    $trace["file"] = substr($trace["file"], strlen(SiteLoader::$root) + 1);
                }
                $prep->landmark = $trace["file"] . ":" . $trace["line"];
                break;
            }
        }
        self::$preps[] = $prep;
        if (self::$print) {
            fwrite(STDOUT, "********\n"
                   . "To: " . join(", ", $prep->to) . "\n"
                   . "Subject: " . str_replace("\r", "", $prep->subject) . "\n"
                   . ($prep->landmark ? "X-Landmark: $prep->landmark\n" : "") . "\n"
                   . $prep->body);
        }
        return false;
    }
    static function check0() {
        xassert_eqq(count(self::$preps), 0);
        self::$preps = [];
    }
    static function check_db($name = null) {
        if ($name) {
            xassert(isset(self::$messagedb[$name]));
            xassert_eqq(count(self::$preps), count(self::$messagedb[$name]));
            $mdb = self::$messagedb[$name];
        } else {
            xassert(!empty(self::$preps));
            $last_landmark = null;
            $mdb = [];
            foreach (self::$preps as $prep) {
                xassert($prep->landmark);
                $landmark = $prep->landmark;
                for ($delta = 0; $delta < 10 && !isset(self::$messagedb[$landmark]); ++$delta) {
                    $colon = strpos($prep->landmark, ":");
                    $landmark = substr($prep->landmark, 0, $colon + 1)
                        . (intval(substr($prep->landmark, $colon + 1), 10)
                           + ($delta & 1 ? ($delta + 1) / 2 : -$delta / 2 - 1));
                }
                if (isset(self::$messagedb[$landmark])) {
                    if ($landmark !== $last_landmark) {
                        $mdb = array_merge($mdb, self::$messagedb[$landmark]);
                        $last_landmark = $landmark;
                    }
                } else {
                    trigger_error("Found no database messages near {$prep->landmark}\n", E_USER_WARNING);
                }
            }
        }
        $haves = [];
        foreach (self::$preps as $prep) {
            $haves[] = "To: " . join(", ", $prep->to) . "\n"
                . "Subject: " . str_replace("\r", "", $prep->subject)
                . "\n\n" . $prep->body;
        }
        sort($haves);
        $wants = [];
        foreach ($mdb as $m) {
            $wants[] = preg_replace('/^X-Landmark:.*?\n/m', "", $m[0]) . $m[1];
        }
        sort($wants);
        foreach ($wants as $i => $want) {
            ++Xassert::$n;
            $have = isset($haves[$i]) ? $haves[$i] : "";
            if ($have === $want
                || preg_match("=\\A" . str_replace('\\{\\{\\}\\}', ".*", preg_quote($want)) . "\\z=", $have)) {
                ++Xassert::$nsuccess;
            } else {
                fwrite(STDERR, "Mail assertion failure: " . var_export($have, true) . " !== " . var_export($want, true) . "\n");
                $havel = explode("\n", $have);
                foreach (explode("\n", $want) as $j => $wantl) {
                    if (!isset($havel[$j])
                        || ($havel[$j] !== $wantl
                            && !preg_match("=\\A" . str_replace('\\{\\{\\}\\}', ".*", preg_quote($wantl, "#\"")) . "\\z=", $havel[$j]))) {
                        fwrite(STDERR, "... line " . ($j + 1) . " differs near " . $havel[$j] . "\n"
                               . "... expected " . $wantl . "\n");
                        break;
                    }
                }
                trigger_error("Assertion failed at " . assert_location() . "\n", E_USER_WARNING);
            }
        }
        self::$preps = [];
    }
    static function clear() {
        self::$preps = [];
    }
    static function add_messagedb($text) {
        preg_match_all('/^\*\*\*\*\*\*\*\*(.*)\n([\s\S]*?\n)(?=^\*\*\*\*\*\*\*\*|\z)/m', $text, $ms, PREG_SET_ORDER);
        foreach ($ms as $m) {
            $m[1] = trim($m[1]);
            $nlpos = strpos($m[2], "\n\n");
            $nlpos = $nlpos === false ? strlen($m[2]) : $nlpos + 2;
            $header = substr($m[2], 0, $nlpos);
            $body = substr($m[2], $nlpos);
            if ($m[1] === ""
                && preg_match('/\nX-Landmark:\s*(\S+)/', $header, $mx)) {
                $m[1] = $mx[1];
            }
            if ($m[1] !== "") {
                if (!isset(self::$messagedb[$m[1]])) {
                    self::$messagedb[$m[1]] = [];
                }
                if (trim($m[2]) !== "") {
                    self::$messagedb[$m[1]][] = [$header, $body];
                }
            }
        }
    }
}
MailChecker::add_messagedb(file_get_contents(SiteLoader::find("test/emails.txt")));
$Conf->add_hook((object) ["event" => "send_mail", "function" => "MailChecker::send_hook", "priority" => 1000]);

function setup_assignments($assignments, Contact $user) {
    if (is_array($assignments)) {
        $assignments = join("\n", $assignments);
    }
    $assignset = new AssignmentSet($user, true);
    $assignset->parse($assignments);
    if (!$assignset->execute()) {
        die_hard("* Failed to run assignments:\n" . join("\n", $assignset->message_texts(true)) . "\n");
    }
}

function setup_initialize_database() {
    global $Conf, $Admin;
    // Initialize from an empty database.
    if (!$Conf->dblink->multi_query(file_get_contents(SiteLoader::find("src/schema.sql")))) {
        die_hard("* Can't reinitialize database.\n" . $Conf->dblink->error . "\n");
    }
    do {
        if (($result = $Conf->dblink->store_result())) {
            $result->free();
        } else if ($Conf->dblink->errno) {
            break;
        }
    } while ($Conf->dblink->more_results() && $Conf->dblink->next_result());
    if ($Conf->dblink->errno) {
        die_hard("* Error initializing database.\n" . $Conf->dblink->error . "\n");
    }

    // No setup phase.
    $Conf->qe_raw("delete from Settings where name='setupPhase'");
    $Conf->qe_raw("insert into Settings set name='options', value=1, data='[{\"id\":1,\"name\":\"Calories\",\"abbr\":\"calories\",\"type\":\"numeric\",\"position\":1,\"display\":\"default\"}]'");
    $Conf->load_settings();

    // Contactdb.
    if (($cdb = $Conf->contactdb())) {
        if (!$cdb->multi_query(file_get_contents(SiteLoader::find("test/cdb-schema.sql")))) {
            die_hard("* Can't reinitialize contact database.\n" . $cdb->error);
        }
        while ($cdb->more_results()) {
            $cdb->next_result();
        }
        $cdb->query("insert into Conferences set dbname='" . $cdb->real_escape_string($Conf->dbname) . "'");
    }

    // Create initial administrator user.
    $Admin = Contact::create($Conf, null, ["email" => "chair@_.com", "name" => "Jane Chair"]);
    $Admin->save_roles(Contact::ROLE_ADMIN | Contact::ROLE_CHAIR | Contact::ROLE_PC, $Admin);

    // Load data.
    $json = json_decode(file_get_contents(SiteLoader::find("test/db.json")));
    if (!$json) {
        die_hard("* test/testdb.json error: " . json_last_error_msg() . "\n");
    }
    $us = new UserStatus($Conf->root_user());
    $ok = true;
    foreach ($json->contacts as $c) {
        $us->notify = in_array("pc", $c->roles ?? []);
        $user = $us->save($c);
        if ($user) {
            MailChecker::check_db("create-{$c->email}");
        } else {
            fwrite(STDERR, "* failed to create user $c->email\n");
            $ok = false;
        }
    }
    foreach ($json->papers as $p) {
        $ps = new PaperStatus($Conf);
        if (!$ps->save_paper_json($p)) {
            $t = join("", array_map(function ($mx) {
                return "    {$mx->field}: {$mx->message}\n";
            }, $ps->message_list()));
            $id = isset($p->_id_) ? "#{$p->_id_} " : "";
            fwrite(STDERR, "* failed to create paper {$id}{$p->title}:\n" . htmlspecialchars_decode($t) . "\n");
            $ok = false;
        }
    }
    if (!$ok) {
        exit(1);
    }

    setup_assignments($json->assignments_1, $Admin);
}

setup_initialize_database();

class Xassert {
    static public $n = 0;
    static public $nsuccess = 0;
    static public $nerror = 0;
    static public $emap = array(E_ERROR => "PHP Fatal Error",
                                E_WARNING => "PHP Warning",
                                E_NOTICE => "PHP Notice",
                                E_USER_ERROR => "PHP Error",
                                E_USER_WARNING => "PHP Warning",
                                E_USER_NOTICE => "PHP Notice");
    static public $disabled = 0;
}

function xassert_error_handler($errno, $emsg, $file, $line) {
    if ((error_reporting() || $errno != E_NOTICE) && Xassert::$disabled <= 0) {
        if (($e = Xassert::$emap[$errno] ?? null)) {
            $emsg = "$e:  $emsg";
        } else {
            $emsg = "PHP Message $errno:  $emsg";
        }
        fwrite(STDERR, "$emsg in $file on line $line\n");
        ++Xassert::$nerror;
    }
}

set_error_handler("xassert_error_handler");

function assert_location() {
    return caller_landmark("{^(?:x?assert|MailChecker::check)}");
}

/** @return bool */
function xassert($x, $description = "") {
    ++Xassert::$n;
    if ($x) {
        ++Xassert::$nsuccess;
    } else {
        trigger_error("Assertion" . ($description ? " " . $description : "") . " failed at " . assert_location() . "\n", E_USER_WARNING);
    }
    return !!$x;
}

/** @return void */
function xassert_exit() {
    $ok = Xassert::$nsuccess
        && Xassert::$nsuccess == Xassert::$n
        && !Xassert::$nerror;
    echo ($ok ? "* " : "! "), plural(Xassert::$nsuccess, "test"), " succeeded out of ", Xassert::$n, " tried.\n";
    if (Xassert::$nerror > Xassert::$n - Xassert::$nsuccess) {
        $nerror = Xassert::$nerror - (Xassert::$n - Xassert::$nsuccess);
        echo "! ", plural($nerror, "other error"), ".\n";
    }
    exit($ok ? 0 : 1);
}

/** @return bool */
function xassert_eqq($a, $b) {
    ++Xassert::$n;
    $ok = $a === $b;
    if ($ok) {
        ++Xassert::$nsuccess;
    } else {
        trigger_error("Assertion " . var_export($a, true) . " === " . var_export($b, true)
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
    }
    return $ok;
}

/** @return bool */
function xassert_neqq($a, $b) {
    ++Xassert::$n;
    $ok = $a !== $b;
    if ($ok) {
        ++Xassert::$nsuccess;
    } else {
        trigger_error("Assertion " . var_export($a, true) . " !== " . var_export($b, true)
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
    }
    return $ok;
}

/** @return bool */
function xassert_eq($a, $b) {
    ++Xassert::$n;
    $ok = $a == $b;
    if ($ok) {
        ++Xassert::$nsuccess;
    } else {
        trigger_error("Assertion " . var_export($a, true) . " == " . var_export($b, true)
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
    }
    return $ok;
}

/** @return bool */
function xassert_neq($a, $b) {
    ++Xassert::$n;
    $ok = $a != $b;
    if ($ok) {
        ++Xassert::$nsuccess;
    } else {
        trigger_error("Assertion " . var_export($a, true) . " != " . var_export($b, true)
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
    }
    return $ok;
}

/** @param ?list<mixed> $a
 * @param ?list<mixed> $b
 * @param bool $sort
 * @return bool */
function xassert_array_eqq($a, $b, $sort = false) {
    ++Xassert::$n;
    $problem = "";
    if ($a === null && $b === null) {
        // ok
    } else if (is_array($a) && is_array($b)) {
        if (count($a) !== count($b) && !$sort) {
            $problem = "size " . count($a) . " !== " . count($b);
        } else if (is_associative_array($a) || is_associative_array($b)) {
            $problem = "associative arrays";
        } else {
            if ($sort) {
                sort($a);
                sort($b);
            }
            for ($i = 0; $i < count($a) && $i < count($b) && !$problem; ++$i) {
                if ($a[$i] !== $b[$i]) {
                    $problem = "value {$i} differs, " . var_export($a[$i], true) . " !== " . var_export($b[$i], true);
                }
            }
            if (!$problem && count($a) !== count($b)) {
                $problem = "size " . count($a) . " !== " . count($b);
            }
        }
    } else {
        $problem = "different types";
    }
    if ($problem === "") {
        ++Xassert::$nsuccess;
    } else {
        trigger_error("Array assertion failed, $problem at " . assert_location() . "\n", E_USER_WARNING);
        if ($sort) {
            $aj = json_encode(array_slice($a, 0, 10));
            if (count($a) > 10) {
                $aj .= "...";
            }
            $bj = json_encode(array_slice($b, 0, 10));
            if (count($b) > 10) {
                $bj .= "...";
            }
            error_log("  " . $aj . " !== " . $bj);
        }
    }
    return $problem === "";
}

/** @return bool */
function xassert_match($a, $b) {
    ++Xassert::$n;
    $ok = is_string($a) && preg_match($b, $a);
    if ($ok) {
        ++Xassert::$nsuccess;
    } else {
        trigger_error("Assertion " . var_export($a, true) . " ~= " . $b
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
    }
    return $ok;
}

/** @return bool */
function xassert_int_list_eqq($a, $b) {
    ++Xassert::$n;
    $x = [];
    foreach ([$a, $b] as $ids) {
        $s = is_array($ids) ? join(" ", $ids) : $ids;
        $x[] = preg_replace_callback('/(\d+)-(\d+)/', function ($m) {
            return join(" ", range(+$m[1], +$m[2]));
        }, $s);
    }
    $ok = $x[0] === $x[1];
    if ($ok) {
        ++Xassert::$nsuccess;
    } else {
        trigger_error("Assertion " . $x[0] . " === " . $x[1]
                      . " failed at " . assert_location() . "\n", E_USER_WARNING);
    }
    return $ok;
}

/** @param Contact $user
 * @param string|array $query
 * @param string $cols
 * @return array<int,array> */
function search_json($user, $query, $cols = "id") {
    $pl = new PaperList("empty", new PaperSearch($user, $query));
    $pl->parse_view($cols);
    return $pl->text_json();
}

/** @param Contact $user
 * @param string|array $query
 * @param string $col
 * @return string */
function search_text_col($user, $query, $col = "id") {
    $pl = new PaperList("empty", new PaperSearch($user, $query));
    $pl->parse_view($col);
    $x = [];
    foreach ($pl->text_json() as $pid => $p) {
        $x[] = $pid . " " . $p[$col] . "\n";
    }
    return join("", $x);
}

/** @param Contact $user
 * @return bool */
function assert_search_papers($user, $query, $result) {
    return xassert_int_list_eqq(array_keys(search_json($user, $query)), $result);
}

/** @param Contact $user
 * @return bool */
function assert_search_ids($user, $query, $result) {
    return xassert_int_list_eqq((new PaperSearch($user, $query))->paper_ids(), $result);
}

/** @return bool */
function assert_query($q, $b) {
    return xassert_eqq(join("\n", Dbl::fetch_first_columns($q)), $b);
}

/** @return int */
function tag_normalize_compare($a, $b) {
    $a_twiddle = strpos($a, "~");
    $b_twiddle = strpos($b, "~");
    $ax = ($a_twiddle > 0 ? substr($a, $a_twiddle + 1) : $a);
    $bx = ($b_twiddle > 0 ? substr($b, $b_twiddle + 1) : $b);
    if (($cmp = strcasecmp($ax, $bx)) == 0) {
        if (($a_twiddle > 0) != ($b_twiddle > 0)) {
            $cmp = ($a_twiddle > 0 ? 1 : -1);
        } else {
            $cmp = strcasecmp($a, $b);
        }
    }
    return $cmp;
}

/** @param PaperInfo $prow
 * @return string */
function paper_tag_normalize($prow) {
    $t = array();
    $pcm = $prow->conf->pc_members();
    foreach (explode(" ", $prow->all_tags_text()) as $tag) {
        if (($twiddle = strpos($tag, "~")) > 0
            && ($c = $pcm[(int) substr($tag, 0, $twiddle)] ?? null)) {
            $at = strpos($c->email, "@");
            $tag = ($at ? substr($c->email, 0, $at) : $c->email) . substr($tag, $twiddle);
        }
        if (strlen($tag) > 2 && substr($tag, strlen($tag) - 2) == "#0") {
            $tag = substr($tag, 0, strlen($tag) - 2);
        }
        if ($tag) {
            $t[] = $tag;
        }
    }
    usort($t, "tag_normalize_compare");
    return join(" ", $t);
}

/** @param Contact $who
 * @return bool */
function xassert_assign($who, $what, $override = false) {
    $assignset = new AssignmentSet($who, $override);
    $assignset->parse($what);
    $ok = $assignset->execute();
    xassert($ok);
    if (!$ok) {
        foreach ($assignset->message_texts() as $line) {
            fwrite(STDERR, "  $line\n");
        }
    }
    return $ok;
}

/** @param Contact $who
 * @return bool */
function xassert_assign_fail($who, $what, $override = false) {
    $assignset = new AssignmentSet($who, $override);
    $assignset->parse($what);
    return xassert(!$assignset->execute());
}

/** @param Contact $user
 * @param ?PaperInfo $prow
 * @return array */
function call_api($fn, $user, $qreq, $prow) {
    if (!($qreq instanceof Qrequest)) {
        $qreq = new Qrequest("POST", $qreq);
        $qreq->approve_token();
    }
    $jr = $user->conf->call_api($fn, $user, $qreq, $prow);
    return $jr->content;
}

/** @param int|PaperInfo $prow
 * @param Contact $user
 * @return ?ReviewInfo */
function fetch_review($prow, $user) {
    if (is_int($prow)) {
        $prow = $user->conf->checked_paper_by_id($prow, $user);
    }
    return $prow->fresh_review_of_user($user);
}

/** @param Contact $user
 * @return ?ReviewInfo */
function save_review($paper, $user, $revreq, $rrow = null) {
    $pid = is_object($paper) ? $paper->paperId : $paper;
    $prow = $user->conf->checked_paper_by_id($pid, $user);
    $rf = Conf::$main->review_form();
    $tf = new ReviewValues($rf);
    $tf->parse_web(new Qrequest("POST", $revreq), false);
    $tf->check_and_save($user, $prow, $rrow ?? fetch_review($prow, $user));
    foreach ($tf->problem_list() as $mx) {
        error_log("! {$mx->field}" . ($mx->message ? ": {$mx->message}" : ""));
    }
    return fetch_review($prow, $user);
}

/** @return Contact */
function user($email) {
    return Conf::$main->checked_user_by_email($email);
}

/** @return ?Contact */
function maybe_user($email) {
    return Conf::$main->user_by_email($email);
}

function xassert_paper_status(PaperStatus $ps, $maxstatus = MessageSet::INFO) {
    if (!xassert($ps->problem_status() <= $maxstatus)) {
        foreach ($ps->problem_list() as $mx) {
            error_log("! {$mx->field}" . ($mx->message ? ": {$mx->message}" : ""));
        }
    }
}

MailChecker::clear();
