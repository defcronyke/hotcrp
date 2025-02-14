<?php
// review.php -- HotCRP helper class for producing review forms and tables
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

// JSON schema for settings["review_form"]:
// {FIELD:{"name":NAME,"description":DESCRIPTION,"position":POSITION,
//         "display_space":ROWS,"visibility":VISIBILITY,
//         "options":[DESCRIPTION,...],"option_letter":LEVELCHAR}}

class ReviewFieldInfo {
    /** @var non-empty-string */
    public $id;
    /** @var non-empty-string */
    public $short_id;
    /** @var bool */
    public $has_options;
    /** @var ?non-empty-string */
    public $main_storage;
    /** @var ?non-empty-string */
    public $json_storage;

    /** @param bool $has_options
     * @param ?non-empty-string $main_storage
     * @param ?non-empty-string $json_storage
     * @phan-assert non-empty-string $id
     * @phan-assert non-empty-string $short_id */
    function __construct($id, $short_id, $has_options, $main_storage, $json_storage) {
        $this->id = $id;
        $this->short_id = $short_id;
        $this->has_options = $has_options;
        $this->main_storage = $main_storage;
        $this->json_storage = $json_storage;
    }
}

class ReviewField implements JsonSerializable {
    const VALUE_NONE = 0;
    const VALUE_SC = 1;
    const VALUE_REV_NUM = 2;
    const VALUE_STRING = 4;
    const VALUE_TRIM = 8;

    /** @var non-empty-string */
    public $id;
    /** @var non-empty-string */
    public $short_id;
    /** @var Conf */
    public $conf;
    /** @var string */
    public $name;
    /** @var string */
    public $name_html;
    /** @var ?string */
    public $description;
    /** @var ?string */
    public $_search_keyword;
    /** @var bool */
    public $has_options;
    /** @var array<mixed,string> */
    public $options;
    /** @var int */
    public $option_letter = 0;
    public $display_space;
    /** @var int */
    public $view_score;
    /** @var bool */
    public $displayed = false;
    /** @var ?int */
    public $display_order;
    /** @var string */
    public $option_class_prefix = "sv";
    /** @var int */
    public $round_mask = 0;
    /** @var ?string */
    public $exists_if;
    /** @var ?SearchTerm */
    private $_exists_search;
    /** @var bool */
    private $_need_exists_search;
    /** @var bool */
    public $required = false;
    /** @var ?non-empty-string */
    public $main_storage;
    /** @var ?non-empty-string */
    public $json_storage;
    private $_typical_score = false;

    static private $view_score_map = [
        "secret" => VIEWSCORE_ADMINONLY, "admin" => VIEWSCORE_REVIEWERONLY,
        "pc" => VIEWSCORE_PC,
        "audec" => VIEWSCORE_AUTHORDEC, "authordec" => VIEWSCORE_AUTHORDEC,
        "au" => VIEWSCORE_AUTHOR, "author" => VIEWSCORE_AUTHOR
    ];
    // Hard-code the database's `view_score` values as of January 2016
    static private $view_score_upgrade_map = [
        -2 => "secret", -1 => "admin", 0 => "pc", 1 => "au"
    ];
    static private $view_score_rmap = [
        VIEWSCORE_ADMINONLY => "secret", VIEWSCORE_REVIEWERONLY => "admin",
        VIEWSCORE_PC => "pc", VIEWSCORE_AUTHORDEC => "audec",
        VIEWSCORE_AUTHOR => "au"
    ];

    function __construct(ReviewFieldInfo $finfo, Conf $conf) {
        $this->id = $finfo->id;
        $this->short_id = $finfo->short_id;
        $this->has_options = $finfo->has_options;
        $this->main_storage = $finfo->main_storage;
        $this->json_storage = $finfo->json_storage;
        $this->conf = $conf;
    }
    static function make_template($has_options, Conf $conf) {
        $id = $has_options ? "s00" : "t00";
        return new ReviewField(new ReviewFieldInfo($id, $id, $has_options, null, null), $conf);
    }

    function assign($j) {
        $this->name = $j->name ?? "Field name";
        $this->name_html = htmlspecialchars($this->name);
        $this->description = $j->description ?? "";
        $this->display_space = $j->display_space ?? 0;
        if (!$this->has_options && $this->display_space < 3) {
            $this->display_space = 3;
        }
        $vis = $j->visibility ?? null;
        if ($vis === null) {
            $vis = $j->view_score ?? null;
            if (is_int($vis)) {
                $vis = self::$view_score_upgrade_map[$vis];
            }
        }
        $this->view_score = VIEWSCORE_PC;
        if (is_string($vis) && isset(self::$view_score_map[$vis])) {
            $this->view_score = self::$view_score_map[$vis];
        }
        if ($j->position ?? null) {
            $this->displayed = true;
            $this->display_order = $j->position;
        } else {
            $this->displayed = false;
            $this->display_order = null;
        }
        $this->round_mask = $j->round_mask ?? 0;
        if ($this->exists_if !== ($j->exists_if ?? null)) {
            $this->exists_if = $j->exists_if ?? null;
            $this->_exists_search = null;
            $this->_need_exists_search = ($this->exists_if ?? "") !== "";
        }
        if ($this->has_options) {
            $options = $j->options ?? [];
            $ol = $j->option_letter ?? 0;
            if ($ol && ctype_alpha($ol) && strlen($ol) == 1) {
                $this->option_letter = ord($ol) + count($options);
            } else if ($ol && (is_int($ol) || ctype_digit($ol))) {
                $this->option_letter = (int) $ol;
            } else {
                $this->option_letter = 0;
            }
            $this->options = [];
            if ($this->option_letter) {
                foreach (array_reverse($options, true) as $i => $n) {
                    $this->options[chr($this->option_letter - $i - 1)] = $n;
                }
            } else {
                foreach ($options as $i => $n) {
                    $this->options[$i + 1] = $n;
                }
            }
            if (($p = $j->option_class_prefix ?? null)) {
                $this->option_class_prefix = $p;
            }
            if (isset($j->required)) {
                $this->required = !!$j->required;
            } else if (isset($j->allow_empty)) {
                $this->required = !$j->allow_empty;
            } else {
                $this->required = true;
            }
        } else {
            $this->required = false;
        }
        $this->_typical_score = false;
    }

    /** @param string $s
     * @return string */
    static function clean_name($s) {
        while ($s !== ""
               && $s[strlen($s) - 1] === ")"
               && ($lparen = strrpos($s, "(")) !== false
               && preg_match('/\A\((?:(?:hidden|invisible|visible|shown)(?:| (?:from|to|from the|to the) authors?)|pc only|shown only to chairs|secret|private)(?:| until decision)[.?!]?\)\z/', substr($s, $lparen))) {
            $s = rtrim(substr($s, 0, $lparen));
        }
        return $s;
    }

    /** @return string */
    function unparse_round_mask() {
        $rs = [];
        foreach ($this->conf->round_list() as $i => $rname) {
            if ($this->round_mask & (1 << $i))
                $rs[] = $i ? "round:{$rname}" : "round:unnamed";
        }
        return join(" OR ", $rs);
    }

    function unparse_json($for_settings = false) {
        $j = (object) array("name" => $this->name);
        if ($this->description) {
            $j->description = $this->description;
        }
        if (!$this->has_options && $this->display_space > 3) {
            $j->display_space = $this->display_space;
        }
        if ($this->displayed) {
            $j->position = $this->display_order;
        }
        $j->visibility = $this->unparse_visibility();
        if ($this->has_options) {
            $j->options = array_values($this->options ?? []);
            if ($this->option_letter) {
                $j->options = array_reverse($j->options);
                $j->option_letter = chr($this->option_letter - count($j->options));
            }
            if ($this->option_class_prefix !== "sv") {
                $j->option_class_prefix = $this->option_class_prefix;
            }
            $j->required = $this->required;
        } else if ($this->required) {
            $j->required = true;
        }
        if ($this->exists_if) {
            $j->exists_if = $this->exists_if;
        } else if ($this->round_mask && $for_settings) {
            $j->round_mask = $this->round_mask;
        } else if ($this->round_mask) {
            $j->exists_if = $this->unparse_round_mask();
        }
        return $j;
    }
    function jsonSerialize() {
        return $this->unparse_json();
    }

    /** @return string */
    function unparse_visibility() {
        return self::$view_score_rmap[$this->view_score] ?? (string) $this->view_score;
    }


    /** @return bool */
    function test_exists(ReviewInfo $rrow) {
        if ($this->_need_exists_search) {
            $search = new PaperSearch($this->conf->root_user(), $this->exists_if);
            $this->_exists_search = $search->term();
            $this->_need_exists_search = false;
        }
        return (!$this->round_mask || ($this->round_mask & (1 << $rrow->reviewRound)) !== 0)
            && (!$this->_exists_search || $this->_exists_search->test($rrow->prow, $rrow));
    }

    /** @param ?int|string $value
     * @return bool */
    function value_empty($value) {
        // see also ReviewInfo::has_nonempty_field
        return $value === null
            || $value === ""
            || ($this->has_options && (int) $value === 0);
    }

    /** @return bool */
    function include_word_count() {
        return $this->displayed
            && !$this->has_options
            && $this->view_score >= VIEWSCORE_AUTHORDEC;
    }

    function typical_score() {
        if ($this->_typical_score === false && $this->has_options) {
            $n = count($this->options);
            if ($n == 1) {
                $this->_typical_score = $this->unparse_value(1);
            } else if ($this->option_letter) {
                $this->_typical_score = $this->unparse_value(1 + (int) (($n - 1) / 2));
            } else {
                $this->_typical_score = $this->unparse_value(2);
            }
        }
        return $this->_typical_score;
    }

    function typical_score_range() {
        if (!$this->has_options || count($this->options) < 2) {
            return null;
        }
        $n = count($this->options);
        if ($this->option_letter) {
            return [$this->unparse_value($n - ($n > 2 ? 1 : 0)), $this->unparse_value($n - 1 - ($n > 2 ? 1 : 0) - ($n > 3 ? 1 : 0))];
        } else {
            return [$this->unparse_value(1 + ($n > 2 ? 1 : 0)), $this->unparse_value(2 + ($n > 2 ? 1 : 0) + ($n > 3 ? 1 : 0))];
        }
    }

    function full_score_range() {
        if (!$this->has_options) {
            return null;
        }
        $f = $this->option_letter ? count($this->options) : 1;
        $l = $this->option_letter ? 1 : count($this->options);
        return [$this->unparse_value($f), $this->unparse_value($l)];
    }

    function search_keyword() {
        if ($this->_search_keyword === null) {
            $this->conf->abbrev_matcher();
            assert($this->_search_keyword !== null);
        }
        return $this->_search_keyword;
    }
    /** @return ?string */
    function abbreviation1() {
        $e = new AbbreviationEntry($this->name, $this, Conf::MFLAG_REVIEW);
        return $this->conf->abbrev_matcher()->find_entry_keyword($e, AbbreviationMatcher::KW_UNDERSCORE);
    }
    function web_abbreviation() {
        return '<span class="need-tooltip" data-tooltip="' . $this->name_html
            . '" data-tooltip-anchor="s">' . htmlspecialchars($this->search_keyword()) . "</span>";
    }
    function uid() {
        return $this->search_keyword();
    }

    static function unparse_letter($option_letter, $value) {
        $ivalue = (int) $value;
        $ch = $option_letter - $ivalue;
        if ($value < $ivalue + 0.25) {
            return chr($ch);
        } else if ($value < $ivalue + 0.75) {
            return chr($ch - 1) . chr($ch);
        } else {
            return chr($ch - 1);
        }
    }

    function value_class($value) {
        if (count($this->options) > 1) {
            $n = (int) (($value - 1) * 8.0 / (count($this->options) - 1) + 1.5);
        } else {
            $n = 1;
        }
        return "sv " . $this->option_class_prefix . $n;
    }

    /** @param int|float|string $value
     * @param int $flags
     * @param ?string $real_format
     * @return ?string */
    function unparse_value($value, $flags = 0, $real_format = null) {
        if (is_object($value)) {
            $value = $value->{$this->id} ?? null;
        }
        if (!$this->has_options) {
            if ($flags & self::VALUE_TRIM) {
                $value = rtrim($value);
            }
            return $value;
        }
        if (!$value) {
            return null;
        }
        if (!$this->option_letter || is_numeric($value)) {
            $value = (float) $value;
        } else if (strlen($value) === 1) {
            $value = (float) $this->option_letter - ord($value);
        } else if (ord($value[0]) + 1 === ord($value[1])) {
            $value = ($this->option_letter - ord($value[0])) - 0.5;
        }
        if (!is_float($value) || $value <= 0.8) {
            return null;
        }
        if ($this->option_letter) {
            $text = self::unparse_letter($this->option_letter, $value);
        } else if ($real_format) {
            $text = sprintf($real_format, $value);
        } else if ($flags & self::VALUE_STRING) {
            $text = (string) $value;
        } else {
            $text = $value;
        }
        if ($flags & (self::VALUE_SC | self::VALUE_REV_NUM)) {
            $vc = $this->value_class($value);
            if ($flags & self::VALUE_REV_NUM) {
                $text = '<strong class="rev_num ' . $vc . '">' . $text . '.</strong>';
            } else {
                $text = '<span class="' . $vc . '">' . $text . '</span>';
            }
        }
        return $text;
    }

    /** @param int|float $value */
    function unparse_average($value) {
        assert($this->has_options);
        return (string) $this->unparse_value($value, 0, "%.2f");
    }

    function unparse_graph($v, $style, $myscore) {
        assert($this->has_options);
        $max = count($this->options);

        if (!is_object($v)) {
            $v = new ScoreInfo($v, true);
        }
        $counts = $v->counts($max);

        $avgtext = $this->unparse_average($v->mean());
        if ($v->count() > 1 && ($stddev = $v->stddev_s())) {
            $avgtext .= sprintf(" ± %.2f", $stddev);
        }

        $args = "v=" . join(",", $counts);
        if ($myscore && $counts[$myscore - 1] > 0) {
            $args .= "&amp;h=$myscore";
        }
        if ($this->option_letter) {
            $args .= "&amp;c=" . chr($this->option_letter - 1);
        }
        if ($this->option_class_prefix !== "sv") {
            $args .= "&amp;sv=" . urlencode($this->option_class_prefix);
        }

        if ($style == 1) {
            $width = 5 * $max + 3;
            $height = 5 * max(3, max($counts)) + 3;
            $retstr = "<div class=\"need-scorechart\" style=\"width:${width}px;height:${height}px\" data-scorechart=\"$args&amp;s=1\" title=\"$avgtext\"></div>";
        } else {
            $retstr = "<div class=\"sc\">"
                . "<div class=\"need-scorechart\" style=\"width:64px;height:8px\" data-scorechart=\"$args&amp;s=2\" title=\"$avgtext\"></div>"
                . "<br>";
            if ($this->option_letter) {
                for ($key = $max; $key >= 1; --$key) {
                    $retstr .= ($key < $max ? " " : "") . '<span class="' . $this->value_class($key) . '">' . $counts[$key - 1] . "</span>";
                }
            } else {
                for ($key = 1; $key <= $max; ++$key) {
                    $retstr .= ($key > 1 ? " " : "") . '<span class="' . $this->value_class($key) . '">' . $counts[$key - 1] . "</span>";
                }
            }
            $retstr .= '<br><span class="sc_sum">' . $avgtext . "</span></div>";
        }
        Ht::stash_script("$(hotcrp.scorechart)", "scorechart");

        return $retstr;
    }

    /** @param string $text
     * @return int|false */
    function parse_option_value($text) {
        assert($this->has_options);
        $text = trim($text);
        if ($text === "") {
            return 0;
        }
        if (!ctype_alnum($text)) {
            if (preg_match('/\A([A-Z]|[0-9]+)(?:[\s\.]|\z)/', $text, $m)) {
                $text = $m[1];
            } else if ($text[0] === "(" || strcasecmp($text, "No entry") === 0) {
                return 0;
            } else {
                return false;
            }
        }
        if (isset($this->options[$text])) {
            if ($this->option_letter) {
                return $this->option_letter - ord($text);
            } else {
                return intval($text);
            }
        } else if ($text === "0") {
            return 0;
        } else {
            return false;
        }
    }

    /** @param string $text
     * @return int|string|false */
    function parse_value($text) {
        if ($this->has_options) {
            return $this->parse_option_value($text);
        } else {
            $text = rtrim($text);
            if ($text !== "") {
                $text .= "\n";
            }
            return $text;
        }
    }

    /** @param string $text
     * @return bool */
    function parse_is_explicit_empty($text) {
        return $this->has_options
            && ($text === "0" || strcasecmp($text, "No entry") === 0);
    }

    /** @param int|string $fval
     * @return int|string */
    function normalize_option_value($fval) {
        assert($this->has_options);
        if (isset($this->options[$fval])) {
            return $this->option_letter ? $fval : (int) $fval;
        } else {
            return 0;
        }
    }

    /** @param int $num
     * @param int $fv
     * @param int $reqv */
    private function echo_option($num, $fv, $reqv) {
        $opt = ["id" => "{$this->id}_{$num}"];
        if ($fv !== $reqv) {
            $opt["data-default-checked"] = $reqv === $num;
        }
        echo '<label class="checki', ($num ? "" : " g"), '"><span class="checkc">',
            Ht::radio($this->id, $num, $fv === $num, $opt), '</span>';
        if ($num) {
            echo $this->unparse_value($num, self::VALUE_REV_NUM),
                ' ', htmlspecialchars($this->options[$num]);
        } else {
            echo 'No entry';
        }
        echo '</label>';
    }

    function echo_web_edit(ReviewForm $rf, $fv, $reqv) {
        if ($this->has_options) {
            foreach ($this->options as $num => $text) {
                $this->echo_option($num, $fv, $reqv);
            }
            if (!$this->required) {
                $this->echo_option(0, $fv, $reqv);
            }
        } else {
            $opt = ["class" => "w-text need-autogrow", "rows" => $this->display_space, "cols" => 60, "spellcheck" => true, "id" => $this->id];
            if ($fv !== $reqv) {
                $opt["data-default-value"] = (string) $reqv;
            }
            echo Ht::textarea($this->id, (string) $fv, $opt);
        }
    }
}

class ReviewForm implements JsonSerializable {
    const NOTIFICATION_DELAY = 10800;

    /** @var Conf */
    public $conf;
    /** @var array<string,ReviewField> */
    public $fmap;      // all fields, whether or not displayed, key id
    /** @var array<string,ReviewField> */
    public $forder;    // displayed fields in display order, key id

    static public $revtype_names = [
        "None", "External", "PC", "Secondary", "Primary", "Meta"
    ];
    static public $revtype_names_lc = [
        "none", "external", "PC", "secondary", "primary", "meta"
    ];
    static public $revtype_names_full = [
        -3 => "Refused", -2 => "Author", -1 => "Conflict",
        0 => "No review", 1 => "External review", 2 => "PC review",
        3 => "Secondary review", 4 => "Primary review", 5 => "Metareview"
    ];
    static public $revtype_icon_text = [
        -3 => "−" /* &minus; */, -2 => "A", -1 => "C",
        1 => "E", 2 => "P", 3 => "2", 4 => "1", 5 => "M"
    ];

    static private $review_author_seen = null;

    static function fmap_compare($a, $b) {
        if ($a->displayed !== $b->displayed) {
            return $a->displayed ? -1 : 1;
        } else if ($a->displayed && $a->display_order !== $b->display_order) {
            return $a->display_order < $b->display_order ? -1 : 1;
        } else {
            return strcmp($a->id, $b->id);
        }
    }

    function __construct($rfj, Conf $conf) {
        $this->conf = $conf;
        $this->fmap = $this->forder = [];

        // parse JSON
        if (!$rfj) {
            $rfj = json_decode('{
"overAllMerit":{"name":"Overall merit","position":1,"visibility":"au",
  "options":["Reject","Weak reject","Weak accept","Accept","Strong accept"]},
"reviewerQualification":{"name":"Reviewer expertise","position":2,"visibility":"au",
  "options":["No familiarity","Some familiarity","Knowledgeable","Expert"]},
"t01":{"name":"Paper summary","position":3,"display_space":5,"visibility":"au"},
"t02":{"name":"Comments to authors","position":4,"visibility":"au"},
"t03":{"name":"Comments to PC","position":5,"visibility":"pc"}}');
        }

        foreach ($rfj as $fid => $j) {
            if (($finfo = ReviewInfo::field_info($fid))) {
                $f = new ReviewField($finfo, $conf);
                $this->fmap[$f->id] = $f;
                $f->assign($j);
            }
        }
        uasort($this->fmap, "ReviewForm::fmap_compare");

        // assign field order
        $do = 0;
        foreach ($this->fmap as $f) {
            if ($f->displayed) {
                $f->display_order = ++$do;
                $this->forder[$f->id] = $f;
            }
        }
    }

    /** @param string $fid
     * @return ?ReviewField */
    function field($fid) {
        return $this->forder[$fid] ?? null;
    }
    /** @return array<string,ReviewField> */
    function all_fields() {
        return $this->forder;
    }
    /** @param int $bound
     * @return array<string,ReviewField> */
    function bound_viewable_fields($bound) {
        $fs = [];
        foreach ($this->forder as $fid => $f) {
            if ($f->view_score > $bound)
                $fs[$fid] = $f;
        }
        return $fs;
    }
    /** @return array<string,ReviewField> */
    function viewable_fields(Contact $user) {
        return $this->bound_viewable_fields($user->permissive_view_score_bound());
    }
    /** @return list<ReviewField> */
    function example_fields(Contact $user) {
        $fs = [];
        foreach ($this->viewable_fields($user) as $f) {
            if ($f->has_options && $f->search_keyword()) {
                if ($f->id === "overAllMerit") {
                    array_unshift($fs, $f);
                } else {
                    $fs[] = $f;
                }
            }
        }
        return $fs;
    }
    function populate_abbrev_matcher(AbbreviationMatcher $am) {
        foreach ($this->all_fields() as $f) {
            $am->add_phrase($f->name, $f, Conf::MFLAG_REVIEW);
        }
    }
    function assign_search_keywords(AbbreviationMatcher $am) {
        foreach ($this->all_fields() as $f) {
            $e = new AbbreviationEntry($f->name, $f, Conf::MFLAG_REVIEW);
            $f->_search_keyword = $am->ensure_entry_keyword($e, AbbreviationMatcher::KW_CAMEL) ?? false;
        }
    }

    /** @return string */
    function default_display() {
        $f = $this->fmap["overAllMerit"];
        if (!$f->displayed || !$f->search_keyword()) {
            $f = null;
            foreach ($this->forder as $fx) {
                if ($fx->has_options && $fx->search_keyword()) {
                    $f = $fx;
                    break;
                }
            }
        }
        return $f ? " " . $f->search_keyword() . " " : " ";
    }

    function jsonSerialize() {
        $fmap = [];
        foreach ($this->fmap as $f) {
            $fmap[$f->id] = $f->unparse_json(true);
        }
        return $fmap;
    }

    /** @param array<string,ReviewField> $fields
     * @return array<string,array> */
    function unparse_form_json($fields) {
        $fmap = [];
        foreach ($fields as $f) {
            $fmap[$f->uid()] = $f->unparse_json();
        }
        return $fmap;
    }


    private function format_info($rrow) {
        $format = $rrow ? $rrow->reviewFormat : null;
        if ($format === null) {
            $format = $this->conf->default_format;
        }
        return $format ? $this->conf->format_info($format) : null;
    }

    private function webFormRows(PaperInfo $prow, ReviewInfo $rrow, Contact $contact,
                                 ReviewValues $rvalues = null) {
        $format_description = "";
        if (($fi = $this->format_info($rrow))) {
            $format_description = $fi->description_preview_html();
        }
        echo '<div class="rve">';
        foreach ($rrow->viewable_fields($contact) as $fid => $f) {
            $rval = "";
            if ($rrow) {
                $rval = $f->unparse_value($rrow->$fid ?? null, ReviewField::VALUE_STRING);
            }
            $fval = $rval;
            if ($rvalues && isset($rvalues->req[$fid])) {
                $fval = $rvalues->req[$fid];
            }
            if ($f->has_options) {
                $fval = $f->normalize_option_value($fval);
                $rval = $f->normalize_option_value($rval);
            }

            echo '<div class="rv rveg" data-rf="', $f->uid(), '"><h3 class="',
                $rvalues ? $rvalues->control_class($fid, "revet") : "revet";
            if ($f->has_options) {
                echo '" id="', $f->id;
            }
            echo '"><label class="revfn';
            if ($f->required) {
                echo ' field-required';
            }
            echo '" for="', $f->id;
            if ($f->has_options) {
                if ($rval || !$f->required) {
                    echo "_", $rval;
                } else {
                    echo "_", key($f->options);
                }
            }
            echo '">', $f->name_html, '</label>';
            if ($f->view_score < VIEWSCORE_AUTHOR) {
                echo '<div class="field-visibility">';
                if ($f->view_score < VIEWSCORE_REVIEWERONLY) {
                    echo '(secret)';
                } else if ($f->view_score < VIEWSCORE_PC) {
                    echo '(shown only to chairs)';
                } else if ($f->view_score < VIEWSCORE_AUTHORDEC) {
                    echo '(hidden from authors)';
                } else {
                    echo '(hidden from authors until decision)';
                }
                echo '</div>';
            }
            echo '</h3>';

            if ($f->description) {
                echo '<div class="field-d">', $f->description, "</div>";
            }

            echo '<div class="revev">';
            if (!$f->has_options) {
                echo $format_description;
            }
            $f->echo_web_edit($this, $fval, $rval);
            echo "</div></div>\n";
        }
        echo "</div>\n";
    }

    /** @return int */
    function nonempty_view_score(ReviewInfo $rrow) {
        $view_score = VIEWSCORE_EMPTY;
        foreach ($this->forder as $fid => $f) {
            if ($rrow->has_nonempty_field($f)) {
                $view_score = max($view_score, $f->view_score);
            }
        }
        return $view_score;
    }

    /** @return int */
    function word_count(ReviewInfo $rrow) {
        $wc = 0;
        foreach ($this->forder as $fid => $f) {
            if ($f->include_word_count() && $rrow->has_nonempty_field($f)) {
                $wc += count_words($rrow->$fid);
            }
        }
        return $wc;
    }

    /** @return ?int */
    function full_word_count(ReviewInfo $rrow) {
        $wc = null;
        foreach ($this->forder as $fid => $f) {
            if (!$f->has_options && $f->test_exists($rrow)) {
                $wc = $wc ?? 0;
                if (!$f->value_empty($rrow->$fid ?? null)) {
                    $wc += count_words($rrow->$fid);
                }
            }
        }
        return $wc;
    }


    static function update_review_author_seen() {
        while (self::$review_author_seen) {
            $conf = self::$review_author_seen[0][0];
            $q = $qv = $next = [];
            foreach (self::$review_author_seen as $x) {
                if ($x[0] === $conf) {
                    $q[] = $x[1];
                    for ($i = 2; $i < count($x); ++$i) {
                        $qv[] = $x[$i];
                    }
                } else {
                    $next[] = $x;
                }
            }
            self::$review_author_seen = $next;
            $mresult = Dbl::multi_qe_apply($conf->dblink, join(";", $q), $qv);
            $mresult->free_all();
        }
    }

    static private function check_review_author_seen($prow, $rrow, $contact,
                                                     $no_update = false) {
        if ($rrow
            && $rrow->reviewId
            && !$rrow->reviewAuthorSeen
            && $contact->act_author_view($prow)
            && !$contact->is_actas_user()) {
            // XXX combination of review tokens & authorship gets weird
            assert($rrow->reviewAuthorModified > 0);
            $rrow->reviewAuthorSeen = Conf::$now;
            if (!$no_update) {
                if (!self::$review_author_seen) {
                    register_shutdown_function("ReviewForm::update_review_author_seen");
                    self::$review_author_seen = [];
                }
                self::$review_author_seen[] = [$contact->conf,
                    "update PaperReview set reviewAuthorSeen=? where paperId=? and reviewId=?",
                    $rrow->reviewAuthorSeen, $rrow->paperId, $rrow->reviewId];
            }
        }
    }


    /** @param bool $plural
     * @return string */
    function text_form_header($plural) {
        $x = "==+== " . $this->conf->short_name . " Review Form" . ($plural ? "s" : "") . "\n";
        $x .= "==-== DO NOT CHANGE LINES THAT START WITH \"==+==\" UNLESS DIRECTED!
==-== For further guidance, or to upload this file when you are done, go to:
==-== " . $this->conf->hoturl_absolute("offline", null, Conf::HOTURL_RAW) . "\n\n";
        return $x;
    }

    function text_form(PaperInfo $prow = null, ReviewInfo $rrow_in = null, Contact $contact, $req = null) {
        $rrow = $rrow_in ?? ReviewInfo::make_blank($prow, $contact);
        $revViewScore = $prow ? $contact->view_score_bound($prow, $rrow) : $contact->permissive_view_score_bound();
        self::check_review_author_seen($prow, $rrow, $contact);
        $viewable_identity = !$prow || $contact->can_view_review_identity($prow, $rrow);

        $x = "==+== =====================================================================\n";
        //$x .= "$prow->paperId:$revViewScore:$rrow->contactId;;$prow->conflictType;;$prow->reviewType\n";

        $x .= "==+== Begin Review";
        if ($prow) {
            $x .= " #" . $prow->paperId;
            if ($req && isset($req["reviewOrdinal"]) && $req["reviewOrdinal"]) {
                $x .= unparse_latin_ordinal($req["reviewOrdinal"]);
            } else if ($rrow->reviewOrdinal) {
                $x .= unparse_latin_ordinal($rrow->reviewOrdinal);
            }
        }
        $x .= "\n";
        if ($rrow->reviewEditVersion && $viewable_identity) {
            $x .= "==+== Version " . $rrow->reviewEditVersion . "\n";
        }
        if (!$prow || $viewable_identity) {
            if ($rrow->email) {
                $x .= "==+== Reviewer: " . Text::nameo($rrow, NAME_EB) . "\n";
            } else {
                $x .= "==+== Reviewer: " . Text::nameo($contact, NAME_EB) . "\n";
            }
        }
        $time = $rrow->mtime($contact);
        if ($time > 0 && $time > $rrow->timeRequested) {
            $x .= "==-== Updated " . $this->conf->unparse_time($time) . "\n";
        }

        if ($prow) {
            $x .= "\n==+== Paper #$prow->paperId\n"
                . prefix_word_wrap("==-== Title: ", $prow->title, "==-==        ")
                . "\n";
        } else {
            $x .= "\n==+== Paper Number\n\n(Enter paper number here)\n\n";
        }

        if ($viewable_identity) {
            $x .= "==+== Review Readiness
==-== Enter \"Ready\" if the review is ready for others to see:

Ready\n";
            if ($this->conf->review_blindness() == Conf::BLIND_OPTIONAL) {
                $blind = $rrow->reviewBlind ? "Anonymous" : "Open";
                $x .= "\n==+== Review Anonymity
==-== " . $this->conf->short_name . " allows either anonymous or open review.
==-== Enter \"Open\" if you want to expose your name to authors:

$blind\n";
            }
        }

        $i = 0;
        $numericMessage = 0;
        $format_description = "";
        if (($fi = $this->format_info($rrow))) {
            $format_description = $fi->description_text();
        }
        foreach ($this->forder as $fid => $f) {
            $i++; // XXX remove $i
            assert($i === $f->display_order);
            if ($f->view_score <= $revViewScore
                || !$f->test_exists($rrow)) {
                continue;
            }

            $fval = "";
            if ($req && isset($req[$fid])) {
                $fval = rtrim($req[$fid]);
            } else if (isset($rrow->$fid)) {
                $fval = $f->unparse_value($rrow->$fid, ReviewField::VALUE_STRING | ReviewField::VALUE_TRIM);
            }
            if ($f->has_options && isset($f->options[$fval])) {
                $fval = "$fval. " . $f->options[$fval];
            } else if (!$fval) {
                $fval = "";
            }

            $y = "==+== " . chr(64 + $i) . ". ";
            $x .= "\n" . prefix_word_wrap($y, $f->name, "==+==    ");
            if ($f->description) {
                $d = cleannl($f->description);
                if (strpbrk($d, "&<") !== false) {
                    $d = Text::html_to_text($d);
                }
                $x .= prefix_word_wrap("==-==    ", trim($d), "==-==    ");
            }
            if ($f->view_score < VIEWSCORE_REVIEWERONLY) {
                $x .= "==-== (secret field)\n";
            } else if ($f->view_score < VIEWSCORE_PC) {
                $x .= "==-== (shown only to chairs)\n";
            } else if ($f->view_score < VIEWSCORE_AUTHORDEC) {
                $x .= "==-== (hidden from authors)\n";
            } else if ($f->view_score < VIEWSCORE_AUTHOR) {
                $x .= "==-== (hidden from authors until decision)\n";
            }
            if ($f->has_options) {
                $x .= "==-== Choices:\n";
                foreach ($f->options as $num => $value) {
                    $y = "==-==    $num. ";
                    /** @phan-suppress-next-line PhanParamSuspiciousOrder */
                    $x .= prefix_word_wrap($y, $value, str_pad("==-==", strlen($y)));
                }
                if (!$f->required) {
                    $x .= "==-==    No entry\n==-== Enter your choice:\n";
                } else if ($f->option_letter) {
                    $x .= "==-== Enter the letter of your choice:\n";
                } else {
                    $x .= "==-== Enter the number of your choice:\n";
                }
                if ($fval === "" && !$f->required) {
                    $fval = "No entry";
                } else if ($fval === "") {
                    $fval = "(Your choice here)";
                }
            } else if ($format_description !== "") {
                $x .= prefix_word_wrap("==-== ", $format_description, "==-== ");
            }
            $x .= "\n" . preg_replace("/^==\\+==/m", "\\==+==", $fval) . "\n";
        }
        return $x . "\n==+== Scratchpad (for unsaved private notes)\n\n==+== End Review\n";
    }

    const UNPARSE_NO_AUTHOR_SEEN = 1;
    const UNPARSE_NO_TITLE = 2;
    const UNPARSE_FLOWED = 4;
    function unparse_text(PaperInfo $prow, ReviewInfo $rrow, Contact $contact,
                          $flags = 0) {
        self::check_review_author_seen($prow, $rrow, $contact, !!($flags & self::UNPARSE_NO_AUTHOR_SEEN));

        $n = "";
        if (!($flags & self::UNPARSE_NO_TITLE)) {
            $n .= $this->conf->short_name . " ";
        }
        $n .= "Review";
        if ($rrow->reviewOrdinal) {
            $n .= " #" . $rrow->unparse_ordinal_id();
        }
        if ($rrow->reviewRound
            && $contact->can_view_review_meta($prow, $rrow)) {
            $n .= " [" . $prow->conf->round_name($rrow->reviewRound) . "]";
        }
        $x = [$n . "\n" . str_repeat("=", 75) . "\n"];

        $flowed = ($flags & self::UNPARSE_FLOWED) !== 0;
        if (!($flags & self::UNPARSE_NO_TITLE)) {
            $x[] = prefix_word_wrap("* ", "Paper: #{$prow->paperId} {$prow->title}", 2, null, $flowed);
        }
        if ($contact->can_view_review_identity($prow, $rrow) && isset($rrow->lastName)) {
            $x[] = "* Reviewer: " . Text::nameo($rrow, NAME_EB) . "\n";
        }
        $time = $rrow->mtime($contact);
        if ($time > 0 && $time > $rrow->timeRequested && $time > $rrow->reviewSubmitted) {
            $x[] = "* Updated: " . $this->conf->unparse_time($time) . "\n";
        }

        foreach ($rrow->viewable_fields($contact) as $fid => $f) {
            $fval = "";
            if (isset($rrow->$fid)) {
                $fval = $f->unparse_value($rrow->$fid, ReviewField::VALUE_STRING | ReviewField::VALUE_TRIM);
            }
            if ($fval == "") {
                continue;
            }

            $x[] = "\n{$f->name}\n";
            $x[] = str_repeat("-", strlen($f->name)) . "\n";

            if ($f->has_options) {
                $y = $f->options[$fval] ?? "";
                $x[] = prefix_word_wrap($fval . ". ", $y, strlen($fval) + 2, null, $flowed);
            } else {
                $x[] = $fval . "\n";
            }
        }
        return join("", $x);
    }

    private function _echo_accept_decline(PaperInfo $prow, $rrow, Contact $user,
                                          $reviewPostLink) {
        if ($rrow
            && $rrow->reviewId
            && $rrow->reviewStatus === 0
            && $rrow->reviewType < REVIEW_SECONDARY
            && ($user->is_my_review($rrow) || $user->can_administer($prow))) {
            $buttons = [];
            $buttons[] = Ht::submit("accept", "Accept", ["class" => "btn-success"]);
            $buttons[] = Ht::button("Decline", ["class" => "btn-danger ui js-decline-review"]);
            // Also see $qreq->refuse case in review.php.
            if ($rrow->requestedBy
                && ($requester = $this->conf->cached_user_by_id($rrow->requestedBy))) {
                $req = 'Please take a moment to accept or decline ' . Text::nameo_h($requester, NAME_P) . '’s review request.';
            } else {
                $req = 'Please take a moment to accept or decline our review request.';
            }
            echo '<div class="revcard-bodyinsert demargin remargin">',
                Ht::actions($buttons, ["class" => "aab aabr aabig", "style" => "margin-top:0"],
                            '<div style="padding-top:5px">' . $req . '</div>'),
                "</div>\n";
        }
    }

    private function _echo_review_actions($prow, $rrow, $user, $reviewPostLink) {
        $buttons = [];

        $submitted = $rrow && $rrow->reviewStatus === ReviewInfo::RS_COMPLETED;
        $disabled = !$user->can_clickthrough("review", $prow);
        $my_review = !$rrow || $user->is_my_review($rrow);
        $pc_deadline = $user->act_pc($prow) || $user->allow_administer($prow);
        if (!$this->conf->time_review($rrow, $pc_deadline, true)) {
            $whyNot = new PermissionProblem($this->conf, ["deadline" => ($rrow && $rrow->reviewType < REVIEW_PC ? "extrev_hard" : "pcrev_hard")]);
            $override_text = whyNotText($whyNot) . " Are you sure you want to override the deadline?";
            if (!$submitted) {
                $buttons[] = array(Ht::button("Submit review", ["class" => "btn-primary btn-savereview ui js-override-deadlines", "data-override-text" => $override_text, "data-override-submit" => "submitreview"]), "(admin only)");
                $buttons[] = array(Ht::button("Save draft", ["class" => "btn-savereview ui js-override-deadlines", "data-override-text" => $override_text, "data-override-submit" => "savedraft"]), "(admin only)");
            } else {
                $buttons[] = array(Ht::button("Save changes", ["class" => "btn-primary btn-savereview ui js-override-deadlines", "data-override-text" => $override_text, "data-override-submit" => "submitreview"]), "(admin only)");
            }
        } else if (!$submitted && $rrow && $rrow->subject_to_approval()) {
            assert($rrow->reviewStatus <= ReviewInfo::RS_ADOPTED);
            if ($rrow->reviewStatus === ReviewInfo::RS_ADOPTED) {
                $buttons[] = Ht::submit("update", "Update approved review", ["class" => "btn-primary btn-savereview need-clickthrough-enable", "disabled" => $disabled]);
            } else if ($my_review) {
                if ($rrow->reviewStatus !== ReviewInfo::RS_DELIVERED) {
                    $subtext = "Submit for approval";
                } else {
                    $subtext = "Resubmit for approval";
                }
                $buttons[] = Ht::submit("submitreview", $subtext, ["class" => "btn-primary btn-savereview need-clickthrough-enable", "disabled" => $disabled]);
            } else {
                $class = "btn-highlight btn-savereview need-clickthrough-enable ui js-approve-review";
                $text = "Approve review";
                if ($rrow->requestedBy === $user->contactId) {
                    $my_rrow = $prow->review_of_user($user);
                    if (!$my_rrow || $my_rrow->reviewStatus < ReviewInfo::RS_DRAFTED) {
                        $class .= " can-adopt";
                        $text = "Approve/adopt review";
                    } else if ($my_rrow->reviewStatus === ReviewInfo::RS_DRAFTED) {
                        $class .= " can-adopt-replace";
                        $text = "Approve/adopt review";
                    }
                }
                if ($user->allow_administer($prow)
                    || $this->conf->ext_subreviews !== 3) {
                    $class .= " can-approve-submit";
                }
                $buttons[] = Ht::submit("approvesubreview", $text, ["class" => $class, "disabled" => $disabled]);
            }
            if ($rrow->reviewStatus < ReviewInfo::RS_DELIVERED) {
                $buttons[] = Ht::submit("savedraft", "Save draft", ["class" => "btn-savereview need-clickthrough-enable", "disabled" => $disabled]);
            }
        } else if (!$submitted) {
            // NB see `PaperTable::_echo_clickthrough` data-clickthrough-enable
            $buttons[] = Ht::submit("submitreview", "Submit review", ["class" => "btn-primary btn-savereview need-clickthrough-enable", "disabled" => $disabled]);
            $buttons[] = Ht::submit("savedraft", "Save draft", ["class" => "btn-savereview need-clickthrough-enable", "disabled" => $disabled]);
        } else {
            // NB see `PaperTable::_echo_clickthrough` data-clickthrough-enable
            $buttons[] = Ht::submit("submitreview", "Save changes", ["class" => "btn-primary btn-savereview need-clickthrough-enable", "disabled" => $disabled]);
        }
        $buttons[] = Ht::submit("cancel", "Cancel");

        if ($rrow && $user->allow_administer($prow)) {
            $buttons[] = "";
            if ($rrow->reviewStatus >= ReviewInfo::RS_ADOPTED) {
                $buttons[] = array(Ht::submit("unsubmitreview", "Unsubmit review"), "(admin only)");
            }
            $buttons[] = array(Ht::button("Delete review", ["class" => "ui js-delete-review"]), "(admin only)");
        }

        echo Ht::actions($buttons, ["class" => "aab aabig"]);
    }

    function show(PaperInfo $prow, ReviewInfo $rrow_in = null, Contact $viewer,
                  $options, ReviewValues $rvalues = null) {
        $editmode = $options["edit"] ?? false;
        $rrow = $rrow_in ?? ReviewInfo::make_blank($prow, $viewer);
        self::check_review_author_seen($prow, $rrow, $viewer);

        if (!$editmode) {
            $rj = $this->unparse_review_json($viewer, $prow, $rrow);
            if ($options["editmessage"] ?? false) {
                $rj->message_html = $options["editmessage"];
            }
            echo Ht::unstash_script("hotcrp.add_review(" . json_encode_browser($rj) . ");\n");
            return;
        }

        // From here on, edit mode.
        $reviewOrdinal = $rrow->unparse_ordinal_id();
        $forceShow = $viewer->is_admin_force() ? "&amp;forceShow=1" : "";
        $reviewLinkArgs = "p=$prow->paperId" . ($rrow->reviewId ? "&amp;r=$reviewOrdinal" : "") . "&amp;m=re" . $forceShow;
        $reviewPostLink = $this->conf->hoturl_post("review", $reviewLinkArgs);
        $reviewDownloadLink = $this->conf->hoturl("review", $reviewLinkArgs . "&amp;downloadForm=1" . $forceShow);

        echo '<div class="pcard revcard" id="r', $reviewOrdinal, '" data-pid="',
            $prow->paperId, '" data-rid="', ($rrow->reviewId ? : "new");
        if ($rrow->reviewOrdinal) {
            echo '" data-review-ordinal="', unparse_latin_ordinal($rrow->reviewOrdinal);
        }
        echo '">',
            Ht::form($reviewPostLink, [
                "id" => "form-review", "class" => "need-unload-protection",
                "data-alert-toggle" => "review-alert"
            ]),
            Ht::hidden_default_submit("default", "");
        if ($rrow->reviewId) {
            echo Ht::hidden("version", ($rrow->reviewEditVersion ?? 0) + 1);
        }
        echo '<div class="revcard-head">';

        // Links
        if ($rrow->reviewId) {
            echo '<div class="float-right"><a href="' . $this->conf->hoturl("review", "r=$reviewOrdinal&amp;text=1" . $forceShow) . '" class="xx">',
                Ht::img("txt.png", "[Text]", "b"),
                "&nbsp;<u>Plain text</u></a>",
                "</div>";
        }

        echo '<h2><span class="revcard-header-name">';
        if ($rrow->reviewId) {
            echo '<a class="nn" href="',
                $rrow->conf->hoturl("review", "r=$reviewOrdinal" . $forceShow),
                '">Edit ', ($rrow->subject_to_approval() ? "Subreview" : "Review");
            if ($rrow->reviewOrdinal) {
                echo "&nbsp;#", $reviewOrdinal;
            }
            echo "</a>";
        } else {
            echo "New Review";
        }
        echo "</span></h2>\n";

        $revname = $revtime = "";
        if ($viewer->active_review_token_for($prow, $rrow)) {
            $revname = "Review token " . encode_token((int) $rrow->reviewToken);
        } else if ($rrow->reviewId && $viewer->can_view_review_identity($prow, $rrow)) {
            $revname = $viewer->reviewer_html_for($rrow);
            if ($rrow->reviewBlind) {
                $revname = "[{$revname}]";
            }
            if (!Contact::is_anonymous_email($rrow->email)) {
                $revname = '<span title="' . $rrow->email . '">' . $revname . '</span>';
            }
        }
        if ($viewer->can_view_review_meta($prow, $rrow)) {
            $revname .= ($revname ? " " : "") . $rrow->type_icon();
            if ($rrow->reviewRound > 0) {
                $revname .= ' <span class="revround" title="Review round">'
                    . htmlspecialchars($this->conf->round_name($rrow->reviewRound))
                    . '</span>';
            }
        }
        if ($rrow->reviewStatus >= ReviewInfo::RS_DRAFTED
            && $viewer->can_view_review_time($prow, $rrow)) {
            $revtime = $this->conf->unparse_time($rrow->reviewModified);
        }
        if ($revname || $revtime) {
            echo '<div class="revthead">';
            if ($revname) {
                echo '<div class="revname">', $revname, '</div>';
            }
            if ($revtime) {
                echo '<div class="revtime">', $revtime, '</div>';
            }
            echo '</div>';
        }

        if ($options["editmessage"] ?? false) {
            echo '<div class="hint">', $options["editmessage"], "</div>\n";
        }

        // download?
        echo '<hr class="c">';
        echo "<table class=\"revoff\"><tr>
      <td><strong>Offline reviewing</strong> &nbsp;</td>
      <td>Upload form: &nbsp; <input type=\"file\" name=\"uploadedFile\" accept=\"text/plain\" size=\"30\">
      &nbsp; ", Ht::submit("uploadForm", "Go"), "</td>
    </tr><tr>
      <td></td>
      <td><a href=\"$reviewDownloadLink\">Download form</a>
      <span class=\"barsep\">·</span>
      <span class=\"hint\"><strong>Tip:</strong> Use <a href=\"", $this->conf->hoturl("search"), "\">Search</a> or <a href=\"", $this->conf->hoturl("offline"), "\">Offline reviewing</a> to download or upload many forms at once.</span></td>
    </tr></table></div>\n";

        // review card
        echo '<div class="revcard-body">';

        // administrator?
        $admin = $viewer->allow_administer($prow);
        if (!$viewer->is_my_review($rrow)) {
            if ($viewer->is_owned_review($rrow)) {
                echo Ht::msg("This isn’t your review, but you can make changes since you requested it.", 0);
            } else if ($admin) {
                echo Ht::msg("This isn’t your review, but as an administrator you can still make changes.", 0);
            }
        }

        // delegate?
        if (!$rrow->reviewSubmitted
            && $rrow->contactId == $viewer->contactId
            && $rrow->reviewType == REVIEW_SECONDARY
            && $this->conf->ext_subreviews < 3) {
            $ndelegated = 0;
            $napproval = 0;
            foreach ($prow->reviews_by_id() as $rr) {
                if ($rr->reviewType === REVIEW_EXTERNAL
                    && $rr->requestedBy === $rrow->contactId) {
                    ++$ndelegated;
                    if ($rr->reviewStatus === ReviewInfo::RS_DELIVERED) {
                        ++$napproval;
                    }
                }
            }

            if ($ndelegated == 0) {
                $t = "As a secondary reviewer, you can <a href=\"" . $this->conf->hoturl("assign", "p=$rrow->paperId") . "\">delegate this review to an external reviewer</a>, but if your external reviewer declines to review the paper, you should complete this review yourself.";
            } else if ($rrow->reviewNeedsSubmit == 0) {
                $t = "A delegated external reviewer has submitted their review, but you can still complete your own if you’d like.";
            } else if ($napproval) {
                $t = "A delegated external reviewer has submitted their review for approval. If you approve that review, you won’t need to submit your own.";
            } else {
                $t = "Your delegated external reviewer has not yet submitted a review.  If they do not, you should complete this review yourself.";
            }
            echo Ht::msg($t, 0);
        }

        // top save changes
        if ($viewer->timeReview($prow, $rrow) || $admin) {
            $this->_echo_accept_decline($prow, $rrow, $viewer, $reviewPostLink);
        }

        // blind?
        if ($this->conf->review_blindness() == Conf::BLIND_OPTIONAL) {
            echo '<div class="rveg"><h3 class="revet checki"><label class="revfn">',
                Ht::hidden("has_blind", 1),
                '<span class="checkc">', Ht::checkbox("blind", 1, ($rvalues ? !!($rvalues->req["blind"] ?? null) : $rrow->reviewBlind)), '</span>',
                "Anonymous review</span></h3>\n",
                '<div class="field-d">', htmlspecialchars($this->conf->short_name), " allows either anonymous or open review.  Check this box to submit your review anonymously (the authors won’t know who wrote the review).</div>",
                "</div>\n";
        }

        // form body
        $this->webFormRows($prow, $rrow, $viewer, $rvalues);

        // review actions
        if ($viewer->timeReview($prow, $rrow) || $admin) {
            if ($prow->can_author_view_submitted_review()
                && (!$rrow->subject_to_approval()
                    || !$viewer->is_my_review($rrow))) {
                echo '<div class="feedback is-warning mb-2">Authors will be notified about submitted reviews.</div>';
            }
            if ($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED
                && !$admin) {
                echo '<div class="feedback is-warning mb-2">Only administrators can remove or unsubmit the review at this point.</div>';
            }
            $this->_echo_review_actions($prow, $rrow, $viewer, $reviewPostLink);
        }

        echo "</div></form></div>\n\n";
        Ht::stash_script('hotcrp.load_editable_review()', "form_revcard");
    }

    const RJ_NO_EDITABLE = 2;
    const RJ_UNPARSE_RATINGS = 4;
    const RJ_ALL_RATINGS = 8;
    const RJ_NO_REVIEWERONLY = 16;

    function unparse_review_json(Contact $viewer, PaperInfo $prow,
                                 ReviewInfo $rrow, $flags = 0) {
        self::check_review_author_seen($prow, $rrow, $viewer);
        $editable = !($flags & self::RJ_NO_EDITABLE);

        $rj = ["pid" => $prow->paperId, "rid" => (int) $rrow->reviewId];
        if ($rrow->reviewOrdinal) {
            $rj["ordinal"] = unparse_latin_ordinal($rrow->reviewOrdinal);
        }
        if ($viewer->can_view_review_meta($prow, $rrow)) {
            $rj["rtype"] = (int) $rrow->reviewType;
            if (($round = $this->conf->round_name($rrow->reviewRound))) {
                $rj["round"] = $round;
            }
        }
        if ($rrow->reviewBlind) {
            $rj["blind"] = true;
        }
        if ($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
            $rj["submitted"] = true;
        } else {
            if ($rrow->is_subreview()) {
                $rj["subreview"] = true;
            }
            if (!$rrow->reviewOrdinal && $rrow->reviewStatus < ReviewInfo::RS_DELIVERED) {
                $rj["draft"] = true;
            } else {
                $rj["ready"] = false;
            }
            if ($rrow->subject_to_approval()) {
                if ($rrow->reviewStatus === ReviewInfo::RS_DELIVERED) {
                    $rj["needs_approval"] = true;
                } else if ($rrow->reviewStatus === ReviewInfo::RS_ADOPTED) {
                    $rj["approved"] = $rj["adopted"] = true;
                } else if ($rrow->reviewStatus > ReviewInfo::RS_ADOPTED) {
                    $rj["approved"] = true;
                }
            }
        }
        if ($editable && $viewer->can_review($prow, $rrow)) {
            $rj["editable"] = true;
        }

        // identity
        $showtoken = $editable && $viewer->active_review_token_for($prow, $rrow);
        if ($viewer->can_view_review_identity($prow, $rrow)
            && (!$showtoken || !Contact::is_anonymous_email($rrow->email))) {
            $rj["reviewer"] = $viewer->reviewer_html_for($rrow);
            if (!Contact::is_anonymous_email($rrow->email)) {
                $rj["reviewer_email"] = $rrow->email;
            }
        }
        if ($showtoken) {
            $rj["review_token"] = encode_token((int) $rrow->reviewToken);
        }
        if ($viewer->is_my_review($rrow)) {
            $rj["my_review"] = true;
        }
        if ($viewer->contactId == $rrow->requestedBy) {
            $rj["my_request"] = true;
        }

        // time
        $time = $rrow->mtime($viewer);
        if ($time > 0 && $time > $rrow->timeRequested) {
            $rj["modified_at"] = (int) $time;
            $rj["modified_at_text"] = $this->conf->unparse_time_point($time);
        }

        // ratings
        if ($rrow->has_ratings()
            && $viewer->can_view_review_ratings($prow, $rrow, ($flags & self::RJ_ALL_RATINGS) != 0)) {
            $rj["ratings"] = array_values($rrow->ratings());
            if ($flags & self::RJ_UNPARSE_RATINGS) {
                $rj["ratings"] = array_map("ReviewInfo::unparse_rating", $rj["ratings"]);
            }
        }
        if ($editable && $viewer->can_rate_review($prow, $rrow)) {
            $rj["user_rating"] = $rrow->rating_of_user($viewer);
            if ($flags & self::RJ_UNPARSE_RATINGS) {
                $rj["user_rating"] = ReviewInfo::unparse_rating($rj["user_rating"]);
            }
        }

        // review text
        // (field UIDs always are uppercase so can't conflict)
        foreach ($rrow->viewable_fields($viewer) as $fid => $f) {
            if ($f->view_score > VIEWSCORE_REVIEWERONLY
                || !($flags & self::RJ_NO_REVIEWERONLY)) {
                $fval = $rrow->$fid ?? null;
                if ($f->has_options) {
                    $fval = $f->unparse_value((int) $fval);
                }
                $rj[$f->uid()] = $fval;
            }
        }
        if (($fmt = $rrow->reviewFormat) === null) {
            $fmt = $this->conf->default_format;
        }
        if ($fmt) {
            $rj["format"] = $fmt;
        }

        return (object) $rj;
    }


    function unparse_flow_entry(PaperInfo $prow, ReviewInfo $rrow, Contact $contact) {
        // See also CommentInfo::unparse_flow_entry
        $barsep = ' <span class="barsep">·</span> ';
        $a = '<a href="' . $prow->hoturl(["#" => "r" . $rrow->unparse_ordinal_id()]) . '"';
        $t = '<tr class="pl"><td class="pl_eventicon">' . $a . '>'
            . Ht::img("review48.png", "[Review]", ["class" => "dlimg", "width" => 24, "height" => 24])
            . '</a></td><td class="pl_eventid pl_rowclick">'
            . $a . ' class="pnum">#' . $prow->paperId . '</a></td>'
            . '<td class="pl_eventdesc pl_rowclick"><small>'
            . $a . ' class="ptitle">'
            . htmlspecialchars(UnicodeHelper::utf8_abbreviate($prow->title, 80))
            . "</a>";
        if ($rrow->reviewStatus >= ReviewInfo::RS_DRAFTED) {
            if ($contact->can_view_review_time($prow, $rrow)) {
                $time = $this->conf->parseableTime($rrow->reviewModified, false);
            } else {
                $time = $this->conf->unparse_time_obscure($this->conf->obscure_time($rrow->reviewModified));
            }
            $t .= $barsep . $time;
        }
        if ($contact->can_view_review_identity($prow, $rrow)) {
            $t .= $barsep . '<span class="hint">review by</span> ' . $contact->reviewer_html_for($rrow);
        }
        $t .= "</small><br>";

        if ($rrow->reviewSubmitted) {
            $t .= "Review #" . $rrow->unparse_ordinal_id() . " submitted";
            $xbarsep = $barsep;
        } else {
            $xbarsep = "";
        }
        foreach ($rrow->viewable_fields($contact) as $fid => $f) {
            if ($f->has_options && !$f->value_empty($rrow->$fid ?? null)) {
                $t .= $xbarsep . $f->name_html . "&nbsp;"
                    . $f->unparse_value((int) $rrow->$fid, ReviewField::VALUE_SC);
                $xbarsep = $barsep;
            }
        }

        return $t . "</td></tr>";
    }

    function compute_view_scores() {
        $recompute = $this !== $this->conf->review_form();
        $prows = $this->conf->paper_set(["where" => "Paper.paperId in (select paperId from PaperReview where reviewViewScore=" . ReviewInfo::VIEWSCORE_RECOMPUTE . ")"]);
        $prows->ensure_full_reviews();
        $updatef = Dbl::make_multi_qe_stager($this->conf->dblink);
        $pids = $rids = [];
        $last_view_score = ReviewInfo::VIEWSCORE_RECOMPUTE;
        foreach ($prows as $prow) {
            foreach ($prow->reviews_by_id() as $rrow) {
                if ($rrow->reviewViewScore_recomputed) {
                    if ($recompute) {
                        $rrow->reviewViewScore = $this->nonempty_view_score($rrow);
                    }
                    if ($last_view_score !== $rrow->reviewViewScore) {
                        if (!empty($rids)) {
                            $updatef("update PaperReview set reviewViewScore=? where paperId?a and reviewId?a and reviewViewScore=?", [$last_view_score, $pids, $rids, ReviewInfo::VIEWSCORE_RECOMPUTE]);
                        }
                        $pids = $rids = [];
                        $last_view_score = $rrow->reviewViewScore;
                    }
                    if (empty($pids) || $pids[count($pids) - 1] !== $rrow->paperId) {
                        $pids[] = $rrow->paperId;
                    }
                    $rids[] = $rrow->reviewId;
                }
            }
        }
        if (!empty($rids)) {
            $updatef("update PaperReview set reviewViewScore=? where paperId?a and reviewId?a and reviewViewScore=?", [$last_view_score, $pids, $rids, ReviewInfo::VIEWSCORE_RECOMPUTE]);
        }
        $updatef(null);
    }
}

class ReviewValues extends MessageSet {
    /** @var ReviewForm */
    public $rf;
    /** @var Conf */
    public $conf;

    /** @var ?string */
    public $text;
    /** @var ?string */
    public $filename;
    /** @var ?int */
    public $lineno;
    /** @var ?int */
    private $first_lineno;
    /** @var ?array<string,int> */
    private $field_lineno;
    /** @var ?int */
    private $garbage_lineno;

    /** @var int */
    public $paperId;
    public $req;

    private $finished = 0;
    /** @var ?list<string> */
    private $submitted;
    /** @var ?list<string> */
    public $updated; // used in tests
    /** @var ?list<string> */
    private $approval_requested;
    /** @var ?list<string> */
    private $approved;
    /** @var ?list<string> */
    private $saved_draft;
    /** @var ?list<string> */
    private $author_notified;
    /** @var ?list<string> */
    public $unchanged;
    /** @var ?list<string> */
    private $unchanged_draft;
    /** @var ?int */
    private $single_approval;
    /** @var ?list<string> */
    private $blank;

    /** @var bool */
    private $no_notify = false;

    function __construct(ReviewForm $rf, $options = []) {
        parent::__construct();
        $this->rf = $rf;
        $this->conf = $rf->conf;
        foreach (["no_notify"] as $k) {
            if (array_key_exists($k, $options))
                $this->$k = $options[$k];
        }
    }

    /** @return ReviewValues */
    static function make_text(ReviewForm $rf, $text, $filename = null) {
        $rv = new ReviewValues($rf);
        $rv->text = $text;
        $rv->lineno = 0;
        $rv->filename = $filename;
        return $rv;
    }

    function rmsg($field, $msg, $status) {
        $e = "";
        if ($this->filename) {
            $e .= htmlspecialchars($this->filename);
            if (is_int($field)) {
                if ($field) {
                    $e .= ":" . $field;
                }
                $field = null;
            } else if ($field && isset($this->field_lineno[$field])) {
                $e .= ":" . $this->field_lineno[$field];
            } else {
                $e .= ":" . $this->lineno;
            }
            if ($this->paperId) {
                $e .= " (paper #" . $this->paperId . ")";
            }
        }
        if ($e) {
            $msg = '<span class="lineno">' . $e . ':</span> ' . $msg;
        }
        $this->msg_at($field, $msg, $status);
    }

    private function check_garbage() {
        if ($this->garbage_lineno) {
            $this->rmsg($this->garbage_lineno, "Review form appears to begin with garbage; ignoring it.", self::WARNING);
        }
        $this->garbage_lineno = null;
    }

    function parse_text($override) {
        assert($this->text !== null && $this->finished === 0);

        $text = $this->text;
        $this->first_lineno = $this->lineno + 1;
        $this->field_lineno = [];
        $this->garbage_lineno = null;
        $this->req = [];
        $this->paperId = 0;
        if ($override !== null) {
            $this->req["override"] = $override;
        }

        $mode = 0;
        $nfields = 0;
        $field = 0;
        $anyDirectives = 0;

        while ($text !== "") {
            $pos = strpos($text, "\n");
            $line = ($pos === false ? $text : substr($text, 0, $pos + 1));
            ++$this->lineno;

            if (substr($line, 0, 6) == "==+== ") {
                // make sure we record that we saw the last field
                if ($mode && $field != null && !isset($this->req[$field])) {
                    $this->req[$field] = "";
                }

                $anyDirectives++;
                if (preg_match('{\A==\+==\s+(.*?)\s+(Paper Review(?: Form)?s?)\s*\z}', $line, $m)
                    && $m[1] != $this->conf->short_name) {
                    $this->check_garbage();
                    $this->rmsg("confid", "Ignoring review form, which appears to be for a different conference.<br>(If this message is in error, replace the line that reads “<code>" . htmlspecialchars(rtrim($line)) . "</code>” with “<code>==+== " . htmlspecialchars($this->conf->short_name) . " " . $m[2] . "</code>” and upload again.)", self::ERROR);
                    return false;
                } else if (preg_match('/^==\+== Begin Review/i', $line)) {
                    if ($nfields > 0)
                        break;
                } else if (preg_match('/^==\+== Paper #?(\d+)/i', $line, $match)) {
                    if ($nfields > 0)
                        break;
                    $this->paperId = intval($match[1]);
                    $this->req["blind"] = 1;
                    $this->first_lineno = $this->field_lineno["paperNumber"] = $this->lineno;
                } else if (preg_match('/^==\+== Reviewer:\s*(.*)$/', $line, $match)
                           && ($user = Text::split_name($match[1], true))
                           && $user[2]) {
                    $this->field_lineno["reviewerEmail"] = $this->lineno;
                    $this->req["reviewerFirst"] = $user[0];
                    $this->req["reviewerLast"] = $user[1];
                    $this->req["reviewerEmail"] = $user[2];
                } else if (preg_match('/^==\+== Paper (Number|\#)\s*$/i', $line)) {
                    if ($nfields > 0)
                        break;
                    $field = "paperNumber";
                    $this->field_lineno[$field] = $this->lineno;
                    $mode = 1;
                    $this->req["blind"] = 1;
                    $this->first_lineno = $this->lineno;
                } else if (preg_match('/^==\+== Submit Review\s*$/i', $line)
                           || preg_match('/^==\+== Review Ready\s*$/i', $line)) {
                    $this->req["ready"] = true;
                } else if (preg_match('/^==\+== Open Review\s*$/i', $line)) {
                    $this->req["blind"] = 0;
                } else if (preg_match('/^==\+== Version\s*(\d+)$/i', $line, $match)) {
                    if (($this->req["version"] ?? 0) < intval($match[1]))
                        $this->req["version"] = intval($match[1]);
                } else if (preg_match('/^==\+== Review Readiness\s*/i', $line)) {
                    $field = "readiness";
                    $mode = 1;
                } else if (preg_match('/^==\+== Review Anonymity\s*/i', $line)) {
                    $field = "anonymity";
                    $mode = 1;
                } else if (preg_match('/^==\+== Review Format\s*/i', $line)) {
                    $field = "reviewFormat";
                    $mode = 1;
                } else if (preg_match('/^==\+== [A-Z]\.\s*(.*?)\s*$/', $line, $match)) {
                    while (substr($text, strlen($line), 6) === "==+== ") {
                        $pos = strpos($text, "\n", strlen($line));
                        $xline = ($pos === false ? substr($text, strlen($line)) : substr($text, strlen($line), $pos + 1 - strlen($line)));
                        if (preg_match('/^==\+==\s+(.*?)\s*$/', $xline, $xmatch)) {
                            $match[1] .= " " . $xmatch[1];
                        }
                        $line .= $xline;
                    }
                    if (($f = $this->conf->find_review_field($match[1]))) {
                        $field = $f->id;
                        $this->field_lineno[$field] = $this->lineno;
                        $nfields++;
                    } else {
                        $this->check_garbage();
                        $this->rmsg(null, "Review field “" . htmlentities($match[1]) . "” is not used for " . htmlspecialchars($this->conf->short_name) . " reviews.  Ignoring this section.", self::ERROR);
                    }
                    $mode = 1;
                } else {
                    $field = null;
                    $mode = 1;
                }
            } else if ($mode < 2 && (substr($line, 0, 5) == "==-==" || ltrim($line) == "")) {
                /* ignore line */
            } else {
                if ($mode == 0) {
                    $this->garbage_lineno = $this->lineno;
                    $field = null;
                }
                if ($field != null) {
                    $this->req[$field] = ($this->req[$field] ?? "") . $line;
                }
                $mode = 2;
            }

            $text = (string) substr($text, strlen($line));
        }

        if ($nfields == 0 && $this->first_lineno == 1) {
            $this->rmsg(null, "That didn’t appear to be a review form; I was not able to extract any information from it.  Please check its formatting and try again.", self::ERROR);
        }

        $this->text = $text;
        --$this->lineno;

        if (isset($this->req["readiness"])) {
            $this->req["ready"] = strcasecmp(trim($this->req["readiness"]), "Ready") == 0;
        }
        if (isset($this->req["anonymity"])) {
            $this->req["blind"] = strcasecmp(trim($this->req["anonymity"]), "Open") != 0;
        }
        if (isset($this->req["reviewFormat"])) {
            $this->req["reviewFormat"] = trim($this->req["reviewFormat"]);
        }

        if ($this->paperId) {
            /* OK */
        } else if (isset($this->req["paperNumber"])
                   && ($pid = cvtint(trim($this->req["paperNumber"]), -1)) > 0) {
            $this->paperId = $pid;
        } else if ($nfields > 0) {
            $this->rmsg("paperNumber", "This review form doesn’t report which paper number it is for.  Make sure you’ve entered the paper number in the right place and try again.", self::ERROR);
            $nfields = 0;
        }

        if ($nfields == 0 && $text) { // try again
            return $this->parse_text($override);
        } else {
            return $nfields != 0;
        }
    }

    function parse_json($j) {
        assert($this->text === null && $this->finished === 0);

        if (!is_object($j) && !is_array($j)) {
            return false;
        }
        $this->req = [];

        // XXX validate more
        $first = $last = null;
        foreach ($j as $k => $v) {
            if ($k === "round") {
                if ($v === null || is_string($v))
                    $this->req["round"] = $v;
            } else if ($k === "blind") {
                if (is_bool($v))
                    $this->req["blind"] = $v ? 1 : 0;
            } else if ($k === "submitted" || $k === "ready") {
                if (is_bool($v))
                    $this->req["ready"] = $v ? 1 : 0;
            } else if ($k === "draft") {
                if (is_bool($v))
                    $this->req["ready"] = $v ? 0 : 1;
            } else if ($k === "name" || $k === "reviewer_name") {
                if (is_string($v))
                    list($this->req["reviewerFirst"], $this->req["reviewerLast"]) = Text::split_name($v);
            } else if ($k === "email" || $k === "reviewer_email") {
                if (is_string($v))
                    $this->req["reviewerEmail"] = trim($v);
            } else if ($k === "affiliation" || $k === "reviewer_affiliation") {
                if (is_string($v))
                    $this->req["reviewerAffiliation"] = $v;
            } else if ($k === "first" || $k === "firstName") {
                if (is_string($v))
                    $this->req["reviewerFirst"] = simplify_whitespace($v);
            } else if ($k === "last" || $k === "lastName") {
                if (is_string($v))
                    $this->req["reviewerLast"] = simplify_whitespace($v);
            } else if ($k === "format") {
                if (is_int($v))
                    $this->req["reviewFormat"] = $v;
            } else if ($k === "version") {
                if (is_int($v))
                    $this->req["version"] = $v;
            } else if (($f = $this->conf->find_review_field($k))) {
                if ((is_string($v) || is_int($v) || $v === null)
                    && !isset($this->req[$f->id]))
                    $this->req[$f->id] = $v;
            }
        }
        if (!empty($this->req) && !isset($this->req["ready"])) {
            $this->req["ready"] = 1;
        }

        return !empty($this->req);
    }

    static private $ignore_web_keys = [
        "submitreview" => true, "savedraft" => true, "unsubmitreview" => true,
        "deletereview" => true, "r" => true, "m" => true, "post" => true,
        "forceShow" => true, "update" => true, "has_blind" => true,
        "adoptreview" => true, "adoptsubmit" => true, "adoptdraft" => true,
        "approvesubreview" => true, "default" => true
    ];

    function parse_web(Qrequest $qreq, $override) {
        assert($this->text === null && $this->finished === 0);
        $this->req = [];
        foreach ($qreq as $k => $v) {
            if (isset(self::$ignore_web_keys[$k]) || !is_scalar($v)) {
                /* skip */
            } else if ($k === "p") {
                $this->paperId = cvtint($v);
            } else if ($k === "override") {
                $this->req["override"] = !!$v;
            } else if ($k === "blind" || $k === "version" || $k === "ready") {
                $this->req[$k] = is_bool($v) ? (int) $v : cvtint($v);
            } else if ($k === "format") {
                $this->req["reviewFormat"] = cvtint($v);
            } else if (isset($this->rf->fmap[$k])) {
                $this->req[$k] = $v;
            } else if (($f = $this->conf->find_review_field($k))
                       && !isset($this->req[$f->id])) {
                $this->req[$f->id] = $v;
            }
        }
        if (!empty($this->req)) {
            if (!$qreq->has_blind && !isset($this->req["blind"])) {
                $this->req["blind"] = 1;
            }
            if ($override) {
                $this->req["override"] = 1;
            }
            return true;
        } else {
            return false;
        }
    }

    function set_ready($ready) {
        $this->req["ready"] = $ready ? 1 : 0;
    }

    function set_adopt() {
        $this->req["adoptreview"] = $this->req["ready"] = 1;
    }

    private function reviewer_error($msg) {
        if (!$msg) {
            $msg = $this->conf->_("Can’t submit a review for %s.", htmlspecialchars($this->req["reviewerEmail"]));
        }
        $this->rmsg("reviewerEmail", $msg, self::ERROR);
    }

    function check_and_save(Contact $user, PaperInfo $prow = null, ReviewInfo $rrow = null) {
        assert(!$rrow || $rrow->paperId === $prow->paperId);

        // look up paper
        if (!$prow) {
            if (!$this->paperId) {
                $this->rmsg("paperNumber", "This review form doesn’t report which paper number it is for.  Make sure you’ve entered the paper number in the right place and try again.", self::ERROR);
                return false;
            }
            $prow = $user->paper_by_id($this->paperId);
            if (($whynot = $user->perm_view_paper($prow, false, $this->paperId))) {
                $this->rmsg("paperNumber", whyNotText($whynot), self::ERROR);
                return false;
            }
        }
        if ($this->paperId && $prow->paperId !== $this->paperId) {
            $this->rmsg("paperNumber", "This review form is for paper #{$this->paperId}, not paper #{$prow->paperId}; did you mean to upload it here? I have ignored the form.", MessageSet::ERROR);
            return false;
        }
        $this->paperId = $prow->paperId;

        // look up reviewer
        $reviewer = $user;
        if ($rrow) {
            if ($rrow->contactId != $user->contactId) {
                $reviewer = $this->conf->cached_user_by_id($rrow->contactId);
            }
        } else if (isset($this->req["reviewerEmail"])
                   && strcasecmp($this->req["reviewerEmail"], $user->email) != 0) {
            if (!($reviewer = $this->conf->user_by_email($this->req["reviewerEmail"]))) {
                $this->reviewer_error($user->privChair ? $this->conf->_("No such user %s.", htmlspecialchars($this->req["reviewerEmail"])) : null);
                return false;
            }
        }

        // look up review
        if (!$rrow) {
            $rrow = $prow->fresh_review_of_user($reviewer);
        }
        if (!$rrow && $user->review_tokens()) {
            $prow->ensure_full_reviews();
            if (($xrrows = $prow->reviews_of_user(-1, $user->review_tokens()))) {
                $rrow = $xrrows[0];
            }
        }

        // maybe create review
        $new_rrid = false;
        if (!$rrow && $user !== $reviewer) {
            if (($whyNot = $user->perm_create_review_from($prow, $reviewer))) {
                $this->reviewer_error(null);
                $this->reviewer_error(whyNotText($whyNot));
                return false;
            }
            $extra = [];
            if (isset($this->req["round"])) {
                $extra["round_number"] = (int) $this->conf->round_number($this->req["round"], false);
            }
            $new_rrid = $user->assign_review($prow->paperId, $reviewer->contactId, $reviewer->isPC ? REVIEW_PC : REVIEW_EXTERNAL, $extra);
            if (!$new_rrid) {
                $this->rmsg(null, "Internal error while creating review.", self::ERROR);
                return false;
            }
            $rrow = $prow->fresh_review_of_id($new_rrid);
        }

        // check permission
        $whyNot = $user->perm_submit_review($prow, $rrow);
        if ($whyNot) {
            if ($user === $reviewer || $user->can_view_review_identity($prow, $rrow)) {
                $this->rmsg(null, whyNotText($whyNot), self::ERROR);
            } else {
                $this->reviewer_error(null);
            }
            return false;
        }

        // actually check review and save
        $rrow = $rrow ?? ReviewInfo::make_blank($prow, $user);
        if ($this->check($rrow)) {
            return $this->do_save($user, $prow, $rrow);
        } else {
            if ($new_rrid) {
                $user->assign_review($prow->paperId, $reviewer->contactId, 0);
            }
            return false;
        }
    }

    private function fvalues(ReviewField $f, ReviewInfo $rrow = null) {
        $fid = $f->id;
        $oldval = $rrow && isset($rrow->$fid) ? $rrow->$fid : "";
        if ($f->has_options) {
            $oldval = (int) $oldval;
        }
        if (isset($this->req[$fid])) {
            return [$oldval, $f->parse_value($this->req[$fid])];
        } else {
            return [$oldval, $oldval];
        }
    }

    private function fvalue_nonempty(ReviewField $f, $fval) {
        return $fval !== ""
            && ($fval !== 0
                || (isset($this->req[$f->id])
                    && $f->parse_is_explicit_empty($this->req[$f->id])));
    }

    private function check(ReviewInfo $rrow) {
        $submit = $this->req["ready"] ?? null;
        $msgcount = $this->message_count();
        $missingfields = [];
        $unready = $anydiff = $anynonempty = false;

        foreach ($this->rf->forder as $fid => $f) {
            if (!isset($this->req[$fid])
                && (!$submit || !$f->test_exists($rrow))) {
                continue;
            }
            list($old_fval, $fval) = $this->fvalues($f, $rrow);
            if ($fval === false) {
                $this->rmsg($fid, $this->conf->_("Bad %s value “%s”.", $f->name_html, htmlspecialchars(UnicodeHelper::utf8_abbreviate($this->req[$fid], 100))), self::WARNING);
                unset($this->req[$fid]);
                $unready = true;
            } else {
                if (!$anydiff
                    && $old_fval !== $fval
                    && ($f->has_options || cleannl($old_fval) !== cleannl($fval))) {
                    $anydiff = true;
                }
                if (!$f->value_empty($fval)
                    || ($fval === 0
                        && isset($this->req[$f->id])
                        && $f->parse_is_explicit_empty($this->req[$f->id]))) {
                    $anynonempty = true;
                } else if ($f->required && $f->view_score >= VIEWSCORE_PC) {
                    $missingfields[] = $f;
                    $unready = $unready || $submit;
                }
            }
        }
        if ($missingfields && $submit && $anynonempty) {
            foreach ($missingfields as $f) {
                $this->rmsg($f->id, $this->conf->_("%s: Entry required.", $f->name_html), self::WARNING);
            }
        }

        if ($rrow->reviewId
            && isset($this->req["reviewerEmail"])
            && strcasecmp($rrow->email, $this->req["reviewerEmail"]) != 0
            && (!isset($this->req["reviewerFirst"])
                || !isset($this->req["reviewerLast"])
                || strcasecmp($this->req["reviewerFirst"], $rrow->firstName) != 0
                || strcasecmp($this->req["reviewerLast"], $rrow->lastName) != 0)) {
            $name1h = Text::name_h($this->req["reviewerFirst"] ?? "", $this->req["reviewerLast"] ?? "", $this->req["reviewerEmail"], NAME_EB);
            $name2h = Text::nameo_h($rrow, NAME_EB);
            $msg = $this->conf->_("The review form was meant for %s, but this review belongs to %s. If you want to upload the form anyway, remove the “<code class=\"nw\">==+== Reviewer</code>” line from the form.", $name1h, $name2h);
            $this->rmsg("reviewerEmail", $msg, self::ERROR);
        } else if ($rrow->reviewId
                   && $rrow->reviewEditVersion > ($this->req["version"] ?? 0)
                   && $anydiff
                   && $this->text !== null) {
            $this->rmsg($this->first_lineno, "This review has been edited online since you downloaded this offline form, so for safety I am not replacing the online version.  If you want to override your online edits, add a line “<code>==+==&nbsp;Version&nbsp;" . $rrow->reviewEditVersion . "</code>” to your offline review form for paper #{$this->paperId} and upload the form again.", self::ERROR);
        } else if ($unready) {
            $what = $this->req["adoptreview"] ?? null ? "approved" : "submitted";
            $this->warning_at("ready", "This review can’t be $what until entries are provided for all required fields.");
            $this->req["ready"] = 0;
        }

        if ($this->has_error_since($msgcount)) {
            return false;
        } else if ($anynonempty || ($this->req["adoptreview"] ?? null)) {
            return true;
        } else {
            $this->blank[] = "#" . $this->paperId;
            return false;
        }
    }

    private function do_notify(PaperInfo $prow, ReviewInfo $rrow,
                               $newstatus, $oldstatus, ReviewDiffInfo $diffinfo,
                               Contact $reviewer, Contact $user) {
        $info = [
            "prow" => $prow, "rrow" => $rrow,
            "reviewer_contact" => $reviewer,
            "check_function" => "HotCRPMailer::check_can_view_review",
            "combination_type" => 1
        ];
        if ($newstatus >= ReviewInfo::RS_COMPLETED
            && ($diffinfo->notify || $diffinfo->notify_author)) {
            if ($oldstatus < ReviewInfo::RS_COMPLETED) {
                $template = "@reviewsubmit";
            } else {
                $template = "@reviewupdate";
            }
            $always_combine = false;
            $diff_view_score = $diffinfo->view_score;
        } else if ($newstatus < ReviewInfo::RS_COMPLETED
                   && $newstatus >= ReviewInfo::RS_DELIVERED
                   && ($diffinfo->fields() || $newstatus !== $oldstatus)
                   && !$this->no_notify) {
            if ($newstatus >= ReviewInfo::RS_ADOPTED) {
                $template = "@reviewapprove";
            } else if ($newstatus === ReviewInfo::RS_DELIVERED
                       && $oldstatus < ReviewInfo::RS_DELIVERED) {
                $template = "@reviewapprovalrequest";
            } else if ($rrow->requestedBy === $user->contactId) {
                $template = "@reviewpreapprovaledit";
            } else {
                $template = "@reviewapprovalupdate";
            }
            $always_combine = true;
            $diff_view_score = null;
            $info["rrow_unsubmitted"] = true;
        } else {
            return;
        }

        $preps = [];
        foreach ($prow->review_followers() as $minic) {
            if ($minic->contactId !== $user->contactId
                && $minic->can_view_review($prow, $rrow, $diff_view_score)
                && ($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED
                    || $rrow->contactId == $minic->contactId
                    || $rrow->requestedBy == $minic->contactId
                    || ($prow->watch($minic) & Contact::WATCH_REVIEW) !== 0)
                && ($p = HotCRPMailer::prepare_to($minic, $template, $info))) {
                // Don't combine preparations unless you can see all submitted
                // reviewer identities
                if (!$always_combine
                    && !$prow->has_author($minic)
                    && (!$prow->has_reviewer($minic)
                        || !$minic->can_view_review_identity($prow, null))) {
                    $p->unique_preparation = true;
                }
                $preps[] = $p;
            }
        }

        if (!empty($preps)) {
            HotCRPMailer::send_combined_preparations($preps);
        }
    }

    private function do_save(Contact $user, PaperInfo $prow, ReviewInfo $rrow) {
        assert($this->paperId == $prow->paperId);
        assert($rrow->paperId == $prow->paperId);

        $oldstatus = $newstatus = $rrow->reviewStatus;
        if (($this->req["ready"] ?? null)
            && $rrow->reviewStatus < ReviewInfo::RS_COMPLETED) {
            if (!$rrow->subject_to_approval()) {
                $newstatus = ReviewInfo::RS_COMPLETED;
            } else if (!$user->isPC) {
                $newstatus = max(ReviewInfo::RS_DELIVERED, $oldstatus);
            } else if ($this->req["adoptreview"] ?? null) {
                $newstatus = ReviewInfo::RS_ADOPTED;
            } else {
                $newstatus = ReviewInfo::RS_COMPLETED;
            }
        }
        $admin = $user->allow_administer($prow);

        if (!$user->timeReview($prow, $rrow)
            && (!isset($this->req["override"]) || !$admin)) {
            $this->rmsg(null, 'The <a href="' . $this->conf->hoturl("deadlines") . '">deadline</a> for entering this review has passed.' . ($admin ? " Select the “Override deadlines” checkbox and try again if you really want to override the deadline." : ""), self::ERROR);
            return false;
        }

        // initialize tfields/sfields with old values from review
        // (in case of partial request)
        $sfields = $tfields = [];
        foreach ($this->rf->forder as $fid => $f) {
            if ($f->json_storage && !$f->value_empty($rrow->$fid ?? null)) {
                if ($f->has_options) {
                    $sfields[$f->json_storage] = (int) $rrow->$fid;
                } else {
                    $tfields[$f->json_storage] = $rrow->$fid;
                }
            }
        }

        $qf = $qv = [];
        $set_sfields = $set_tfields = [];
        $view_score = VIEWSCORE_EMPTY;
        $diffinfo = new ReviewDiffInfo($prow, $rrow);
        $wc = 0;
        foreach ($this->rf->all_fields() as $fid => $f) {
            if (!$f->test_exists($rrow)) {
                continue;
            }
            list($old_fval, $fval) = $this->fvalues($f, $rrow);
            if ($fval === false) {
                $fval = $old_fval;
            }
            if ($f->has_options) {
                if ($fval === 0 && $rrow->reviewId && $f->required) {
                    $fval = $old_fval;
                }
                $fval_diffs = $fval !== $old_fval;
            } else {
                // Check for valid UTF-8; re-encode from Windows-1252 or Mac OS
                $fval = convert_to_utf8($fval);
                $fval_diffs = $fval !== $old_fval && cleannl($fval) !== cleannl($old_fval);
            }
            if ($fval_diffs) {
                $diffinfo->add_field($f, $fval);
            }
            if ($fval_diffs || !$rrow->reviewId) {
                if ($f->main_storage) {
                    $qf[] = "{$f->main_storage}=?";
                    $qv[] = $fval;
                }
                if ($f->json_storage) {
                    if ($f->has_options) {
                        if ($fval != 0) {
                            $sfields[$f->json_storage] = $fval;
                        } else {
                            unset($sfields[$f->json_storage]);
                        }
                        $set_sfields[$fid] = true;
                    } else {
                        if ($fval !== "") {
                            $tfields[$f->json_storage] = $fval;
                        } else {
                            unset($tfields[$f->json_storage]);
                        }
                        $set_tfields[$fid] = true;
                    }
                }
            }
            if ($f->include_word_count()) {
                $wc += count_words($fval);
            }
            if (!$f->value_empty($fval)) {
                $view_score = max($view_score, $f->view_score);
            }
        }
        if (!empty($set_sfields)) {
            $qf[] = "sfields=?";
            $qv[] = $sfields ? json_encode_db($sfields) : null;
        }
        if (!empty($set_tfields)) {
            $qf[] = "tfields=?";
            $qv[] = $tfields ? json_encode_db($tfields) : null;
        }

        // get the current time
        $now = time();
        if ($rrow->reviewModified >= $now) {
            $now = $rrow->reviewModified + 1;
        }

        if (($newstatus >= ReviewInfo::RS_COMPLETED)
            !== ($oldstatus >= ReviewInfo::RS_COMPLETED)) {
            $qf[] = "reviewSubmitted=?";
            $qv[] = $newstatus >= ReviewInfo::RS_COMPLETED ? $now : null;
            // $diffinfo->view_score should represent transition to submitted
            if ($rrow->reviewId && $newstatus >= ReviewInfo::RS_COMPLETED) {
                $diffinfo->add_view_score($this->rf->nonempty_view_score($rrow));
            }
        }
        if ($newstatus >= ReviewInfo::RS_ADOPTED) {
            $qf[] = "reviewNeedsSubmit=?";
            $qv[] = 0;
        }
        if ($newstatus === ReviewInfo::RS_DELIVERED && $oldstatus <= $newstatus) {
            $qf[] = "timeApprovalRequested=?";
            $qv[] = $now;
        } else if ($newstatus === ReviewInfo::RS_ADOPTED && $oldstatus !== $newstatus) {
            $qf[] = "timeApprovalRequested=?";
            $qv[] = -$now;
        }

        // check whether used a review token
        $usedReviewToken = $user->active_review_token_for($prow, $rrow);

        // blind? reviewer type? edit version?
        $reviewBlind = $this->conf->is_review_blind(!!($this->req["blind"] ?? null));
        if (!$rrow->reviewId
            || $reviewBlind != $rrow->reviewBlind) {
            $diffinfo->add_view_score(VIEWSCORE_ADMINONLY);
            $qf[] = "reviewBlind=?";
            $qv[] = $reviewBlind ? 1 : 0;
        }
        if ($rrow->reviewId
            && $rrow->reviewType == REVIEW_EXTERNAL
            && $user->contactId == $rrow->contactId
            && $user->isPC
            && !$usedReviewToken) {
            $qf[] = "reviewType=?";
            $qv[] = REVIEW_PC;
        }
        if ($rrow->reviewId
            && $diffinfo->nonempty()
            && isset($this->req["version"])
            && ctype_digit($this->req["version"])
            && $this->req["version"] > ($rrow->reviewEditVersion ?? 0)) {
            $qf[] = "reviewEditVersion=?";
            $qv[] = $this->req["version"] + 0;
        }
        if ($diffinfo->nonempty()) {
            $qf[] = "reviewWordCount=?";
            $qv[] = $wc;
        }
        if (isset($this->req["reviewFormat"])
            && $this->conf->opt("formatInfo")) {
            $fmt = null;
            foreach ($this->conf->opt("formatInfo") as $k => $f) {
                if (($f["name"] ?? null)
                    && strcasecmp($f["name"], $this->req["reviewFormat"]) === 0)
                    $fmt = (int) $k;
            }
            if (!$fmt
                && $this->req["reviewFormat"]
                && preg_match('/\A(?:plain\s*)?(?:text)?\z/i', $this->req["reviewFormat"])) {
                $fmt = 0;
            }
            $qf[] = "reviewFormat=?";
            $qv[] = $fmt;
        }

        // notification
        if ($diffinfo->nonempty()) {
            $qf[] = "reviewModified=?";
            $qv[] = $now;
            $newstatus = max($newstatus, ReviewInfo::RS_DRAFTED);
        }
        $notification_bound = $now - ReviewForm::NOTIFICATION_DELAY;
        $newsubmit = $newstatus >= ReviewInfo::RS_COMPLETED
            && $oldstatus < ReviewInfo::RS_COMPLETED;
        if (!$rrow->reviewId || $diffinfo->nonempty()) {
            $qf[] = "reviewViewScore=?";
            $qv[] = $view_score;
            // XXX distinction between VIEWSCORE_AUTHOR/VIEWSCORE_AUTHORDEC?
            if ($diffinfo->view_score >= VIEWSCORE_AUTHOR) {
                $qf[] = "reviewAuthorModified=?";
                $qv[] = $now;
            } else if (!$rrow->reviewAuthorModified
                       && $rrow->reviewModified
                       && $this->rf->nonempty_view_score($rrow) >= VIEWSCORE_AUTHOR) {
                $qf[] = "reviewAuthorModified=?";
                $qv[] = $rrow->reviewModified;
            }
            // do not notify on updates within 3 hours, except fresh submits
            if ($newstatus >= ReviewInfo::RS_COMPLETED
                && $diffinfo->view_score > VIEWSCORE_ADMINONLY
                && !$this->no_notify) {
                if (!$rrow->reviewNotified
                    || $rrow->reviewNotified < $notification_bound
                    || $newsubmit) {
                    $qf[] = "reviewNotified=?";
                    $qv[] = $now;
                    $diffinfo->notify = true;
                }
                if ((!$rrow->reviewAuthorNotified
                     || $rrow->reviewAuthorNotified < $notification_bound)
                    && $diffinfo->view_score >= VIEWSCORE_AUTHOR
                    && $prow->can_author_view_submitted_review()) {
                    $qf[] = "reviewAuthorNotified=?";
                    $qv[] = $now;
                    $diffinfo->notify_author = true;
                }
            }
        }

        // potentially assign review ordinal (requires table locking since
        // mySQL is stupid)
        $locked = $newordinal = false;
        if ((!$rrow->reviewId
             && $newsubmit
             && $diffinfo->view_score >= VIEWSCORE_AUTHORDEC)
            || ($rrow->reviewId
                && !$rrow->reviewOrdinal
                && ($newsubmit || $rrow->reviewStatus >= ReviewInfo::RS_COMPLETED)
                && ($diffinfo->view_score >= VIEWSCORE_AUTHORDEC
                    || $this->rf->nonempty_view_score($rrow) >= VIEWSCORE_AUTHORDEC))) {
            $table_suffix = "";
            if ($this->conf->au_seerev == Conf::AUSEEREV_TAGS) {
                $table_suffix = ", PaperTag read";
            }
            $result = $this->conf->qe_raw("lock tables PaperReview write" . $table_suffix);
            if (Dbl::is_error($result)) {
                return false;
            }
            Dbl::free($result);
            $locked = true;
            $max_ordinal = $this->conf->fetch_ivalue("select coalesce(max(reviewOrdinal), 0) from PaperReview where paperId=? group by paperId", $prow->paperId);
            // NB `coalesce(reviewOrdinal,0)` is not necessary in modern schemas
            $qf[] = "reviewOrdinal=if(coalesce(reviewOrdinal,0)=0,?,reviewOrdinal)";
            $qv[] = (int) $max_ordinal + 1;
            $newordinal = true;
        }
        if ($newordinal
            || (($newsubmit
                 || ($newstatus >= ReviewInfo::RS_ADOPTED && $oldstatus < ReviewInfo::RS_ADOPTED))
                && !$rrow->timeDisplayed)) {
            $qf[] = "timeDisplayed=?";
            $qv[] = $now;
        }

        // actually affect database
        if ($rrow->reviewId) {
            if (!empty($qf)) {
                array_push($qv, $prow->paperId, $rrow->reviewId);
                $result = $this->conf->qe_apply("update PaperReview set " . join(", ", $qf) . " where paperId=? and reviewId=?", $qv);
            } else {
                $result = true;
            }
            $reviewId = $rrow->reviewId;
            $contactId = $rrow->contactId;
            if ($user->is_signed_in()) {
                $rrow->delete_acceptor();
            }
        } else {
            array_unshift($qf, "paperId=?", "contactId=?", "reviewType=?", "requestedBy=?", "reviewRound=?");
            array_unshift($qv, $prow->paperId, $user->contactId, REVIEW_PC, $user->contactId, $this->conf->assignment_round(false));
            $result = $this->conf->qe_apply("insert into PaperReview set " . join(", ", $qf), $qv);
            $reviewId = $result ? $result->insert_id : null;
            $contactId = $user->contactId;
        }

        // unlock tables even if problem
        if ($locked) {
            $this->conf->qe_raw("unlock tables");
        }
        if (Dbl::is_error($result)) {
            return false;
        }

        // update caches
        $prow->update_rights();

        // look up review ID
        if (!$reviewId) {
            return false;
        }
        $this->req["reviewId"] = $reviewId;
        $new_rrow = $prow->fresh_review_of_id($reviewId);
        if ($new_rrow->reviewStatus !== $newstatus) {
            error_log("{$this->conf->dbname}: review #{$prow->paperId}/{$new_rrow->reviewId} saved reviewStatus {$new_rrow->reviewStatus} (expected {$newstatus})");
        }
        assert($new_rrow->reviewStatus === $newstatus);

        // log updates -- but not if review token is used
        if (!$usedReviewToken
            && $diffinfo->nonempty()) {
            $log_actions = [];
            if (!$rrow->reviewId) {
                $log_actions[] = "started";
            }
            if ($newsubmit) {
                $log_actions[] = "submitted";
            }
            if ($rrow->reviewId && !$newsubmit && $diffinfo->fields()) {
                $log_actions[] = "edited";
            }
            $log_fields = array_map(function ($f) use ($new_rrow) {
                if ($f->has_options) {
                    return $f->search_keyword() . ":" . $f->unparse_value($new_rrow);
                } else {
                    return $f->search_keyword();
                }
            }, $diffinfo->fields());
            if (($wc = $this->rf->full_word_count($new_rrow)) !== null) {
                $log_fields[] = plural($wc, "word");
            }
            if ($newstatus < ReviewInfo::RS_DELIVERED) {
                $statusword = " draft";
            } else if ($newstatus === ReviewInfo::RS_DELIVERED) {
                $statusword = " approvable";
            } else if ($newstatus === ReviewInfo::RS_ADOPTED) {
                $statusword = " adopted";
            } else {
                $statusword = "";
            }
            $user->log_activity_for($new_rrow->contactId, "Review $reviewId "
                . join(", ", $log_actions)
                . $statusword
                . (empty($log_fields) ? "" : ": ")
                . join(", ", $log_fields), $prow);
        }

        // if external, forgive the requester from finishing their review
        if ($new_rrow->reviewType < REVIEW_SECONDARY
            && $new_rrow->requestedBy
            && $newstatus >= ReviewInfo::RS_COMPLETED) {
            $this->conf->q_raw("update PaperReview set reviewNeedsSubmit=0 where paperId=$prow->paperId and contactId={$new_rrow->requestedBy} and reviewType=" . REVIEW_SECONDARY . " and reviewSubmitted is null");
        }

        // notify automatic tags
        $this->conf->update_automatic_tags($prow, "review");

        // potentially email chair, reviewers, and authors
        $reviewer = $user;
        if ($contactId != $user->contactId) {
            $reviewer = $this->conf->cached_user_by_id($contactId);
        }
        $this->do_notify($prow, $new_rrow, $newstatus, $oldstatus, $diffinfo, $reviewer, $user);

        // record what happened
        $what = "#$prow->paperId";
        if ($new_rrow->reviewOrdinal) {
            $what .= unparse_latin_ordinal($new_rrow->reviewOrdinal);
        }
        if ($newsubmit) {
            $this->submitted[] = $what;
        } else if ($newstatus === ReviewInfo::RS_DELIVERED
                   && $new_rrow->contactId === $user->contactId) {
            $this->approval_requested[] = $what;
        } else if ($newstatus === ReviewInfo::RS_ADOPTED
                   && $oldstatus < $newstatus
                   && $new_rrow->contactId !== $user->contactId) {
            $this->approved[] = $what;
        } else if ($diffinfo->nonempty()) {
            if ($newstatus >= ReviewInfo::RS_ADOPTED) {
                $this->updated[] = $what;
            } else {
                $this->saved_draft[] = $what;
                $this->single_approval = +$new_rrow->timeApprovalRequested;
            }
        } else {
            $this->unchanged[] = $what;
            if ($newstatus < ReviewInfo::RS_ADOPTED) {
                $this->unchanged_draft[] = $what;
                $this->single_approval = +$new_rrow->timeApprovalRequested;
            }
        }
        if ($diffinfo->notify_author) {
            $this->author_notified[] = $what;
        }

        return true;
    }

    private function _confirm_message($fmt, $info, $single = null) {
        $pids = array();
        foreach ($info as &$x) {
            if (preg_match('/\A(#?)(\d+)([A-Z]*)\z/', $x, $m)) {
                $x = "<a href=\"" . $this->conf->hoturl("paper", ["p" => $m[2], "#" => $m[3] ? "r$m[2]$m[3]" : null]) . "\">" . $x . "</a>";
                $pids[] = $m[2];
            }
        }
        if ($single === null) {
            $single = $this->text === null;
        }
        $t = $this->conf->_($fmt, count($info), commajoin($info), $single);
        if (count($pids) > 1) {
            $t = '<span class="has-hotlist" data-hotlist="p/s/' . join("+", $pids) . '">' . $t . '</span>';
        }
        $this->msg_at(null, $t, self::INFO);
    }

    private function _single_approval_state() {
        if ($this->text !== null || $this->single_approval < 0) {
            return null;
        } else {
            return $this->single_approval == 0 ? 2 : 3;
        }
    }

    function finish() {
        $confirm = false;
        if ($this->submitted) {
            $this->_confirm_message("Reviews %2\$s submitted.", $this->submitted);
            $confirm = true;
        }
        if ($this->updated) {
            $this->_confirm_message("Reviews %2\$s updated.", $this->updated);
            $confirm = true;
        }
        if ($this->approval_requested) {
            $this->_confirm_message("Reviews %2\$s submitted for approval.", $this->approval_requested);
            $confirm = true;
        }
        if ($this->approved) {
            $this->_confirm_message("Reviews %2\$s approved.", $this->approved);
            $confirm = true;
        }
        if ($this->saved_draft) {
            $this->_confirm_message("Draft reviews for papers %2\$s saved.", $this->saved_draft, $this->_single_approval_state());
        }
        if ($this->author_notified) {
            $this->_confirm_message("Authors were notified about updated reviews %2\$s.", $this->author_notified);
        }
        $nunchanged = $this->unchanged ? count($this->unchanged) : 0;
        $nignoredBlank = $this->blank ? count($this->blank) : 0;
        if ($nunchanged + $nignoredBlank > 1
            || $this->text !== null
            || !$this->has_messages()) {
            if ($this->unchanged) {
                $single = null;
                if ($this->unchanged == $this->unchanged_draft) {
                    $single = $this->_single_approval_state();
                }
                $this->_confirm_message("Reviews %2\$s unchanged.", $this->unchanged, $single);
            }
            if ($this->blank) {
                $this->_confirm_message("Ignored blank review forms %2\$s.", $this->blank);
            }
        }
        $this->finished = $confirm ? 2 : 1;
    }

    function message_status() {
        if (!$this->finished) {
            $this->finish();
        }
        if ($this->has_messages()) {
            $m = [];
            if ($this->text !== null) {
                if ($this->has_error() && $this->has_warning()) {
                    $m[] = $this->conf->_("There were errors and warnings while parsing the uploaded review file.");
                } else if ($this->has_error()) {
                    $m[] = $this->conf->_("There were errors while parsing the uploaded review file.");
                } else if ($this->has_warning()) {
                    $m[] = $this->conf->_("There were warnings while parsing the uploaded review file.");
                }
            }
            $m[] = '<div class="parseerr"><p>' . join("</p>\n<p>", $this->message_texts()) . '</p></div>';
            if ($this->has_error() || $this->has_problem_at("ready")) {
                return [$m, 2];
            } else if ($this->has_warning() || $this->finished == 1) {
                return [$m, 1];
            } else {
                return [$m, "confirm"];
            }
        } else {
            return ["Nothing to do.", 0];
        }
    }

    function report() {
        if ($this->finished < 3) {
            $mx = $this->message_status();
            if ($mx[1]) {
                $this->conf->msg($mx[0], $mx[1]);
            }
            $this->finished = 3;
        }
    }

    function json_report() {
        $j = [];
        foreach (["submitted", "updated", "approval_requested", "saved_draft", "author_notified", "unchanged", "blank"] as $k) {
            if ($this->$k)
                $j[$k] = $this->$k;
        }
        return $j;
    }
}
