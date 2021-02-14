<?php
// papertable.php -- HotCRP helper class for producing paper tables
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class PaperTable {
    /** @var Conf */
    public $conf;
    /** @var PaperInfo */
    public $prow;
    /** @var Contact */
    public $user;
    /** @var list<ReviewInfo> */
    private $all_rrows = [];
    /** @var list<ReviewInfo> */
    private $viewable_rrows = [];
    /** @var array<int,CommentInfo> */
    private $crows;
    /** @var array<int,CommentInfo> */
    private $mycrows;
    /** @var bool */
    private $can_view_reviews;
    /** @var ?ReviewInfo */
    public $rrow;
    /** @var ?ReviewInfo */
    public $editrrow;
    /** @var string */
    public $mode;
    /** @var string */
    private $first_mode;
    private $prefer_approvable = false;
    private $allreviewslink;
    /** @var ?PaperStatus */
    private $edit_status;

    public $editable;
    /** @var list<PaperOption> */
    private $edit_fields;

    /** @var Qrequest */
    private $qreq;
    private $useRequest;
    /** @var ?ReviewValues */
    private $review_values;
    private $npapstrip = 0;
    /** @var bool */
    private $allFolded;
    private $matchPreg;
    private $canUploadFinal;
    private $foldmap;
    private $foldnumber;

    /** @var bool */
    private $allow_admin;
    /** @var bool */
    private $admin;

    /** @var ?CheckFormat */
    public $cf;
    private $quit = false;

    function __construct(PaperInfo $prow = null, Qrequest $qreq, $mode = null) {
        global $Me;

        $this->conf = $prow ? $prow->conf : Conf::$main;
        $this->user = $user = $Me;
        $this->prow = $prow ?? PaperInfo::make_new($user);
        $this->allow_admin = $user->allow_administer($this->prow);
        $this->admin = $user->can_administer($this->prow);
        $this->qreq = $qreq;

        $this->canUploadFinal = $this->user->allow_edit_final_paper($this->prow);

        if (!$prow || !$this->prow->paperId) {
            $this->can_view_reviews = false;
            $this->mode = $this->first_mode = "edit";
            return;
        }

        $this->can_view_reviews = $user->can_view_review($prow, null);
        if (!$this->can_view_reviews && $prow->has_reviewer($user)) {
            foreach ($prow->reviews_of_user($user) as $rrow) {
                if ($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
                    $this->can_view_reviews = true;
                }
            }
        }

        // enumerate allowed modes
        if ($prow->has_author($user)
            && !$user->can_view_review($prow, null)
            && $this->conf->timeFinalizePaper($prow)) {
            $this->first_mode = "edit";
        } else if ($user->can_review($prow, null)
                   && $qreq->page() === "review") {
            $this->first_mode = "re";
        } else {
            $this->first_mode = "p";
        }

        $ms = ["p" => true];
        if ($user->can_review($prow, null)) {
            $ms["re"] = true;
        }
        if ($prow->has_author($user) || $this->allow_admin) {
            $ms["edit"] = true;
        }
        if ($prow->review_type($user) >= REVIEW_SECONDARY || $this->allow_admin) {
            $ms["assign"] = true;
        }
        if (!$mode) {
            $mode = $this->qreq->m ? : $this->qreq->mode;
        }
        if ($mode === "pe") {
            $mode = "edit";
        } else if ($mode === "view" || $mode === "r" || $mode === "main") {
            $mode = "p";
        } else if ($mode === "rea") {
            $mode = "re";
            $this->prefer_approvable = true;
        }
        if ($mode && isset($ms[$mode])) {
            $this->mode = $mode;
        } else {
            $this->mode = $this->first_mode;
        }
        if (isset($ms["re"]) && isset($this->qreq->reviewId)) {
            $this->mode = "re";
        }
    }

    static function do_header($paperTable, $id, $action_mode, $qreq) {
        global $Me;
        $conf = $paperTable ? $paperTable->conf : Conf::$main;
        $prow = $paperTable ? $paperTable->prow : null;
        $format = 0;

        $t = '<header id="header-page" class="header-page-submission"><h1 class="paptitle';

        if (!$paperTable) {
            if (($pid = $qreq->paperId) && ctype_digit($pid)) {
                $title = "#$pid";
            } else {
                $title = $conf->_c("paper_title", "Submission");
            }
            $t .= '">' . $title;
        } else if (!$prow->paperId) {
            $title = $conf->_c("paper_title", "New submission");
            $t .= '">' . $title;
        } else {
            $paperTable->initialize_list();
            $title = "#" . $prow->paperId;
            $viewable_tags = $prow->viewable_tags($Me);
            if ($viewable_tags || $Me->can_view_tags($prow)) {
                $t .= ' has-tag-classes';
                if (($color = $prow->conf->tags()->color_classes($viewable_tags)))
                    $t .= ' ' . $color;
            }
            $t .= '"><a class="q" href="' . $prow->hoturl()
                . '"><span class="taghl"><span class="pnum">' . $title . '</span>'
                . ' &nbsp; ';

            $highlight_text = null;
            $title_matches = 0;
            if ($paperTable->matchPreg
                && ($highlight = $paperTable->matchPreg["ti"] ?? null)) {
                $highlight_text = Text::highlight($prow->title, $highlight, $title_matches);
            }

            if (!$title_matches && ($format = $prow->title_format())) {
                $t .= '<span class="ptitle need-format" data-format="' . $format . '">';
            } else {
                $t .= '<span class="ptitle">';
            }
            if ($highlight_text) {
                $t .= $highlight_text;
            } else if ($prow->title === "") {
                $t .= "[No title]";
            } else {
                $t .= htmlspecialchars($prow->title);
            }

            $t .= '</span></span></a>';
            if ($viewable_tags && $conf->tags()->has_decoration) {
                $tagger = new Tagger($Me);
                $t .= $tagger->unparse_decoration_html($viewable_tags);
            }
        }

        $t .= '</h1></header>';
        if ($paperTable && $prow->paperId) {
            $t .= $paperTable->_paptabBeginKnown();
        }

        $body_class = "paper";
        if ($paperTable
            && $prow->paperId
            && $Me->has_overridable_conflict($prow)
            && ($Me->overrides() & Contact::OVERRIDE_CONFLICT)) {
            $body_class .= " fold5o";
        } else {
            $body_class .= " fold5c";
        }

        $conf->header($title, $id, [
            "action_bar" => actionBar($action_mode, $qreq),
            "title_div" => $t,
            "body_class" => $body_class,
            "paperId" => $qreq->paperId
        ]);
        if ($format) {
            echo Ht::unstash_script("hotcrp.render_text_page()");
        }
    }

    private function initialize_list() {
        assert(!$this->conf->has_active_list());
        $list = $this->find_session_list();
        $this->conf->set_active_list($list);

        $this->matchPreg = [];
        if (($list = $this->conf->active_list())
            && $list->highlight
            && preg_match('/\Ap\/([^\/]*)\/([^\/]*)(?:\/|\z)/', $list->listid, $m)) {
            $hlquery = is_string($list->highlight) ? $list->highlight : urldecode($m[2]);
            $ps = new PaperSearch($this->user, ["t" => $m[1], "q" => $hlquery]);
            foreach ($ps->field_highlighters() as $k => $v) {
                $this->matchPreg[$k] = $v;
            }
        }
        if (empty($this->matchPreg)) {
            $this->matchPreg = null;
        }
    }

    private function find_session_list() {
        $prow = $this->prow;
        if ($prow->paperId <= 0) {
            return null;
        }

        if (($list = SessionList::load_cookie($this->user, "p"))
            && ($list->set_current_id($prow->paperId) || $list->digest)) {
            return $list;
        }

        // look up list description
        $list = null;
        $listdesc = $this->qreq->ls;
        if ($listdesc) {
            if (($opt = PaperSearch::unparse_listid($listdesc))) {
                $list = $this->try_list($opt, $prow);
            }
            if (!$list && preg_match('{\A(all|s):(.*)\z}s', $listdesc, $m)) {
                $list = $this->try_list(["t" => $m[1], "q" => $m[2]], $prow);
            }
            if (!$list && preg_match('{\A[a-z]+\z}', $listdesc)) {
                $list = $this->try_list(["t" => $listdesc], $prow);
            }
            if (!$list) {
                $list = $this->try_list(["q" => $listdesc], $prow);
            }
        }

        // default lists
        if (!$list) {
            $list = $this->try_list([], $prow);
        }
        if (!$list && $this->user->privChair) {
            $list = $this->try_list(["t" => "all"], $prow);
        }

        return $list;
    }
    private function try_list($opt, $prow) {
        $srch = new PaperSearch($this->user, $opt);
        if ($srch->test($prow)) {
            $list = $srch->session_list_object();
            $list->set_current_id($prow->paperId);
            return $list;
        } else {
            return null;
        }
    }

    function initialize($editable, $useRequest) {
        $this->editable = $editable;
        $this->useRequest = $useRequest;
        $this->allFolded = $this->mode === "re"
            || $this->mode === "assign"
            || ($this->mode !== "edit"
                && $this->can_view_reviews
                && !empty($this->all_rrows));
    }

    function set_edit_status(PaperStatus $status) {
        $this->edit_status = $status;
        $this->edit_status->translate_field("authorInformation", "authors");
    }

    function set_review_values(ReviewValues $rvalues = null) {
        $this->review_values = $rvalues;
    }

    /** @return bool */
    function can_view_reviews() {
        return $this->can_view_reviews;
    }

    private function abstract_foldable($abstract) {
        return strlen($abstract) > 190;
    }

    private function echoDivEnter() {
        // 4: topics, 6: abstract, 7: [JavaScript abstract expansion],
        // 8: blind authors, 9: full authors
        $foldstorage = [4 => "t", 6 => "b", 8 => "a", 9 => "p"];
        $this->foldnumber = ["topics" => 4];

        // other expansions
        $next_foldnum = 10;
        foreach ($this->prow->display_fields() as $o) {
            if ($o->display_position() !== false
                && $o->display_position() >= 1000
                && $o->display_position() < 5000
                && ($o->id <= 0 || $this->user->allow_view_option($this->prow, $o))
                && $o->display_group !== null) {
                if (strlen($o->display_group) > 1
                    && !isset($this->foldnumber[$o->display_group])) {
                    $this->foldnumber[$o->display_group] = $next_foldnum;
                    $foldstorage[$next_foldnum] = str_replace(" ", "_", $o->display_group);
                    ++$next_foldnum;
                }
                if ($o->display_expand) {
                    $this->foldnumber[$o->formid] = $next_foldnum;
                    $foldstorage[$next_foldnum] = $o->formid;
                    ++$next_foldnum;
                }
            }
        }

        // what is folded?
        // if highlighting, automatically unfold abstract/authors
        $this->foldmap = [];
        foreach ($foldstorage as $num => $k) {
            $this->foldmap[$num] = $this->allFolded || $k === "a";
        }
        if ($this->foldmap[6]) {
            $abstract = $this->highlight($this->prow->abstract_text(), "ab", $match);
            if ($match || !$this->abstract_foldable($abstract)) {
                $this->foldmap[6] = false;
            }
        }
        if ($this->matchPreg && ($this->foldmap[8] || $this->foldmap[9])) {
            $this->highlight($this->prow->authorInformation, "au", $match);
            if ($match) {
                $this->foldmap[8] = $this->foldmap[9] = false;
            }
        }

        // collect folders
        $folders = [];
        foreach ($this->foldmap as $num => $f) {
            if ($num !== 8 || $this->user->view_authors_state($this->prow) === 1) {
                $folders[] = "fold" . $num . ($f ? "c" : "o");
            }
        }
        echo '<div id="foldpaper" class="', join(" ", $folders);
        if ($this->allFolded) {
            echo '">';
        } else {
            echo (empty($folders) ? "" : " "),
                'need-fold-storage" data-fold-storage-prefix="p." data-fold-storage="',
                htmlspecialchars(json_encode_browser($foldstorage)), '">';
            Ht::stash_script("hotcrp.fold_storage()");
        }
    }

    private function problem_status_at($f) {
        if ($this->edit_status) {
            return $this->edit_status->problem_status_at($f);
        } else {
            return 0;
        }
    }
    function has_problem_at($f) {
        return $this->problem_status_at($f) > 0;
    }
    function has_error_class($f) {
        return $this->has_problem_at($f) ? " has-error" : "";
    }
    /** @param string $f */
    function control_class($f, $rest = "", $prefix = "has-") {
        return MessageSet::status_class($this->problem_status_at($f), $rest, $prefix);
    }
    /** @param list<string> $fs */
    function max_control_class($fs, $rest = "", $prefix = "has-") {
        $ps = $this->edit_status ? $this->edit_status->max_problem_status_at($fs) : 0;
        return MessageSet::status_class($ps, $rest, $prefix);
    }

    /** @param ?string $heading */
    function echo_editable_option_papt(PaperOption $opt, $heading = null, $rest = []) {
        if (!isset($rest["for"])) {
            $for = $opt->readable_formid();
        } else {
            $for = $rest["for"] ?? false;
        }
        echo '<div class="papeg';
        if (!$opt->test_exists($this->prow) || ($rest["hidden"] ?? false)) {
            echo ' hidden';
        }
        if ($opt->exists_condition()) {
            echo ' want-fieldchange has-edit-condition" data-edit-condition="', htmlspecialchars(json_encode($opt->exists_script_expression($this->prow)));
            Ht::stash_script('$(hotcrp.paper_edit_conditions)', 'edit_condition');
        }
        echo '"><h3 class="', $this->control_class($opt->formid, "papet");
        if ($for === "checkbox") {
            echo " checki";
        }
        if (($tclass = $rest["tclass"] ?? false)) {
            echo " ", ltrim($tclass);
        }
        if (($id = $rest["id"] ?? false)) {
            echo '" id="' . $id;
        }
        $klass = "papfn";
        if ($opt->required) {
            $klass .= " field-required";
        }
        echo '">', Ht::label($heading ?? $this->edit_title_html($opt), $for === "checkbox" ? false : $for, ["class" => $klass]);
        if ($opt->visibility === "admin") {
            echo '<div class="field-visibility">(hidden from reviewers)</div>';
        }
        echo '</h3>';
        $this->echo_field_hint($opt);
        echo Ht::hidden("has_{$opt->formid}", 1);
    }

    /** @param array<string,int|string> $extra */
    private function papt($what, $name, $extra = []) {
        $fold = $extra["fold"] ?? false;
        $editfolder = $extra["editfolder"] ?? false;
        $foldnum = $foldnumclass = false;
        if ($fold || $editfolder) {
            $foldnum = $extra["foldnum"] ?? 0;
            $foldnumclass = $foldnum ? " data-fold-target=\"$foldnum\"" : "";
        }

        if (($extra["type"] ?? null) === "ps") {
            list($divclass, $hdrclass) = ["pst", "psfn"];
        } else {
            list($divclass, $hdrclass) = ["pavt", "pavfn"];
        }

        $c = "<div class=\"" . $this->control_class($what, $divclass);
        if (($fold || $editfolder) && !($extra["float"] ?? false)) {
            $c .= " ui js-foldup\"" . $foldnumclass . ">";
        } else {
            $c .= "\">";
        }
        $c .= "<h3 class=\"$hdrclass";
        if (isset($extra["fnclass"])) {
            $c .= " " . $extra["fnclass"];
        }
        $c .= '">';
        if (!$fold) {
            $n = (is_array($name) ? $name[0] : $name);
            if ($editfolder) {
                $c .= "<a class=\"q fn ui js-foldup\" "
                    . "href=\"" . $this->conf->selfurl($this->qreq, ["atab" => $what])
                    . "\"" . $foldnumclass . ">" . $n
                    . '<span class="t-editor">✎ </span>'
                    . "</a><span class=\"fx\">" . $n . "</span>";
            } else {
                $c .= $n;
            }
        } else {
            '@phan-var-force int $foldnum';
            '@phan-var-force string $foldnumclass';
            $c .= '<a class="q ui js-foldup" href=""' . $foldnumclass;
            if (($title = $extra["foldtitle"] ?? false)) {
                $c .= ' title="' . $title . '"';
            }
            if (isset($this->foldmap[$foldnum])) {
                $c .= ' role="button" aria-expanded="' . ($this->foldmap[$foldnum] ? "false" : "true") . '"';
            }
            $c .= '>' . expander(null, $foldnum);
            if (!is_array($name)) {
                $name = array($name, $name);
            }
            if ($name[0] !== $name[1]) {
                $c .= '<span class="fn' . $foldnum . '">' . $name[1] . '</span><span class="fx' . $foldnum . '">' . $name[0] . '</span>';
            } else {
                $c .= $name[0];
            }
            $c .= '</a>';
        }
        $c .= "</h3>";
        if (isset($extra["float"])) {
            $c .= $extra["float"];
        }
        $c .= "</div>";
        return $c;
    }

    function highlight($text, $pregname, &$n = null) {
        if ($this->matchPreg && isset($this->matchPreg[$pregname])) {
            $text = Text::highlight($text, $this->matchPreg[$pregname], $n);
        } else {
            $text = htmlspecialchars($text);
            $n = 0;
        }
        return $text;
    }

    function messages_at($field) {
        $t = "";
        foreach ($this->edit_status ? $this->edit_status->message_list_at($field) : [] as $mx) {
            $t .= '<p class="' . MessageSet::status_class($mx->status, "feedback", "is-") . '">' . $mx->message . '</p>';
        }
        return $t;
    }

    /** @param PaperOption $opt */
    function echo_field_hint($opt) {
        echo $this->messages_at($opt->formid);
        $fr = new FieldRender(FieldRender::CFHTML);
        $fr->value_format = 5;
        if ($opt->description_format !== null) {
            $fr->value_format = $opt->description_format;
        }
        $this->conf->ims()->render_ci($fr, "field_description/edit",
                                      $opt->formid, $opt->description);
        if (!$fr->is_empty()) {
            echo $fr->value_html("field-d");
        }
        echo $this->messages_at($opt->formid . ":context");
    }

    /** @param PaperOption $opt */
    function edit_title_html($opt) {
        $t = $opt->edit_title();
        if (str_ends_with($t, ")")
            && preg_match('/\A([^()]* +)(\([^()]+\))\z/', $t, $m)) {
            return htmlspecialchars($m[1]) . '<span class="n">' . htmlspecialchars($m[2]) . '</span>';
        } else {
            return htmlspecialchars($t);
        }
    }

    /** @param DocumentInfo $doc
     * @param array{notooltip?:bool} $options
     * @return string */
    static function pdf_stamps_html($doc, $options = null) {
        $tooltip = !$options || !($options["notooltip"] ?? null);
        $t = [];

        if ($doc->timestamp > 0) {
            $t[] = ($tooltip ? '<span class="nb need-tooltip" aria-label="Upload time">' : '<span class="nb">')
                . '<svg width="12" height="12" viewBox="0 0 96 96" class="licon"><path d="M48 6a42 42 0 1 1 0 84 42 42 0 1 1 0-84zm0 10a32 32 0 1 0 0 64 32 32 0 1 0 0-64zM48 19A5 5 0 0 0 43 24V46c0 2.352.37 4.44 1.464 5.536l12 12c4.714 4.908 12-2.36 7-7L53 46V24A5 5 0 0 0 43 24z"/></svg>'
                . " " . $doc->conf->unparse_time($doc->timestamp) . "</span>";
        }

        $ha = new HashAnalysis($doc->sha1);
        if ($ha->ok()) {
            $h = $ha->text_data();
            $x = '<span class="nb checksum';
            if ($tooltip) {
                $x .= ' need-tooltip" data-tooltip="';
                if ($ha->algorithm() === "sha256")  {
                    $x .= "SHA-256 checksum";
                } else if ($ha->algorithm() === "sha1") {
                    $x .= "SHA-1 checksum";
                }
            }
            $x .= '"><svg width="12" height="12" viewBox="0 0 48 48" class="licon"><path d="M19 32l-8-8-7 7 14 14 26-26-6-6-19 19zM15 3V10H8v5h7v7h5v-7H27V10h-7V3h-5z"/></svg> '
                . '<span class="checksum-overflow">' . $h . '</span>'
                . '<span class="checksum-abbreviation">' . substr($h, 0, 8) . '</span></span>';
            $t[] = $x;
        }

        if (!empty($t)) {
            return '<span class="hint">' . join(' <span class="barsep">·</span> ', $t) . "</span>";
        } else {
            return "";
        }
    }

    /** @param PaperOption $o */
    function render_submission(FieldRender $fr, $o) {
        assert(!$this->editable && $o->id == 0);
        $fr->title = false;
        $fr->value = "";
        $fr->value_format = 5;

        // conflicts
        if ($this->user->isPC
            && !$this->prow->has_conflict($this->user)
            && $this->mode !== "assign"
            && $this->mode !== "contact"
            && $this->prow->can_author_edit_paper()) {
            $fr->value .= Ht::msg('The authors still have <a href="' . $this->conf->hoturl("deadlines") . '">time</a> to make changes.', 1);
        }

        // download
        if ($this->user->can_view_pdf($this->prow)) {
            $dprefix = "";
            $dtype = $this->prow->finalPaperStorageId > 1 ? DTYPE_FINAL : DTYPE_SUBMISSION;
            if (($doc = $this->prow->document($dtype))
                && $doc->paperStorageId > 1) {
                if (($stamps = self::pdf_stamps_html($doc))) {
                    $stamps = '<span class="sep"></span>' . $stamps;
                }
                if ($dtype == DTYPE_FINAL) {
                    $dhtml = $this->conf->option_by_id($dtype)->title_html();
                } else {
                    $dhtml = $o->title_html($this->prow->timeSubmitted != 0);
                }
                $fr->value .= '<p class="pgsm">' . $dprefix . $doc->link_html('<span class="pavfn">' . $dhtml . '</span>', DocumentInfo::L_REQUIREFORMAT) . $stamps . '</p>';
            }
        }
    }

    private function is_ready($checkbox) {
        if ($this->useRequest) {
            return !!$this->qreq->submitpaper
                && ($checkbox
                    || $this->conf->opt("noPapers")
                    || $this->prow->paperStorageId > 1);
        } else {
            return $this->prow->timeSubmitted > 0
                || ($checkbox
                    && !$this->conf->setting("sub_freeze")
                    && (!$this->prow->paperId
                        || (!$this->conf->opt("noPapers") && $this->prow->paperStorageId <= 1)));
        }
    }

    private function echo_editable_complete() {
        if ($this->canUploadFinal) {
            echo Ht::hidden("submitpaper", 1);
            return;
        }

        $checked = $this->is_ready(true);
        $ready_open = $this->prow->paperStorageId > 1 || $this->conf->opt("noPapers");
        echo '<div class="ready-container ',
            $ready_open ? "foldo" : "foldc",
            '"><div class="checki fx"><span class="checkc">',
            Ht::checkbox("submitpaper", 1, $checked, ["class" => "uich js-check-submittable", "disabled" => !$ready_open]),
            " </span>";
        if ($this->conf->setting("sub_freeze")) {
            echo Ht::label("<strong>" . $this->conf->_("The submission is complete") . "</strong>"),
                '<p class="settings-ap hint">You must complete the submission before the deadline or it will not be reviewed. Completed submissions are frozen and cannot be changed further.</p>';
        } else {
            echo Ht::label("<strong>" . $this->conf->_("The submission is ready for review") . "</strong>");
        }
        echo "</div></div>\n";
    }

    static function document_upload_input($inputid, $dtype, $accepts) {
        $t = '<input id="' . $inputid . '" type="file" name="' . $inputid . '"';
        if ($accepts !== null && count($accepts) == 1) {
            $t .= ' accept="' . $accepts[0]->mimetype . '"';
        }
        $t .= ' size="30" class="';
        $k = ["uich", "document-uploader"];
        if ($dtype == DTYPE_SUBMISSION || $dtype == DTYPE_FINAL) {
            $k[] = "js-check-submittable primary-document";
        }
        return $t . join(" ", $k) . '">';
    }

    function render_abstract(FieldRender $fr, PaperOption $o) {
        $fr->title = false;
        $fr->value_format = 5;

        $html = $this->highlight($this->prow->abstract_text(), "ab", $match);
        if (trim($html) === "") {
            if ($this->conf->opt("noAbstract"))
                return;
            $html = "[No abstract]";
        }
        $extra = [];
        if ($this->allFolded && $this->abstract_foldable($html)) {
            $extra = ["fold" => "paper", "foldnum" => 6,
                      "foldtitle" => "Toggle full abstract"];
        }
        $fr->value = '<div class="paperinfo-abstract"><div class="pg">'
            . $this->papt("abstract", $o->title_html(), $extra)
            . '<div class="pavb abstract';
        if (!$match && ($format = $this->prow->format_of($html))) {
            $fr->value .= ' need-format" data-format="' . $format . '">' . $html;
        } else {
            $fr->value .= ' format0">' . Ht::format0_html($html);
        }
        $fr->value .= "</div></div></div>";
        if ($extra) {
            $fr->value .= '<div class="fn6 fx7 longtext-fader"></div>'
                . '<div class="fn6 fx7 longtext-expander"><a class="ui x js-foldup" href="" role="button" aria-expanded="false" data-fold-target="6">[more]</a></div>'
                . Ht::unstash_script("hotcrp.render_text_page()");
        }
    }

    private function authorData($table, $type, $viewAs = null) {
        if ($this->matchPreg && isset($this->matchPreg["au"])) {
            $highpreg = $this->matchPreg["au"];
        } else {
            $highpreg = false;
        }
        $names = [];

        if (empty($table)) {
            return "[No authors]";
        } else if ($type === "last") {
            foreach ($table as $au) {
                $n = Text::nameo($au, NAME_P|NAME_I);
                $names[] = Text::highlight($n, $highpreg);
            }
            return join(", ", $names);
        } else {
            foreach ($table as $au) {
                $n = $e = $t = "";
                $n = trim(Text::highlight("$au->firstName $au->lastName", $highpreg));
                if ($au->email !== "") {
                    $e = Text::highlight($au->email, $highpreg);
                    $e = '&lt;<a href="mailto:' . htmlspecialchars($au->email)
                        . '" class="mailto">' . $e . '</a>&gt;';
                }
                $t = ($n === "" ? $e : $n);
                if ($au->affiliation !== "") {
                    $t .= ' <span class="auaff">(' . Text::highlight($au->affiliation, $highpreg) . ')</span>';
                }
                if ($n !== "" && $e !== "") {
                    $t .= " " . $e;
                }
                $t = trim($t);
                if ($au->email !== ""
                    && $au->contactId
                    && $viewAs !== null
                    && $viewAs->email !== $au->email
                    && $viewAs->privChair) {
                    $t .= " <a href=\""
                        . $this->conf->selfurl($this->qreq, ["actas" => $au->email])
                        . "\">" . Ht::img("viewas.png", "[Act as]", array("title" => "Act as " . Text::nameo($au, NAME_P))) . "</a>";
                }
                $names[] = '<p class="odname">' . $t . '</p>';
            }
            return join("\n", $names);
        }
    }

    private function _analyze_authors() {
        // clean author information
        $aulist = $this->prow->author_list();
        if (empty($aulist)) {
            return [[], []];
        }

        // find contact author information, combine with author table
        $result = $this->conf->qe("select contactId, firstName, lastName, '' affiliation, email from ContactInfo where contactId?a", array_keys($this->prow->contacts()));
        $contacts = array();
        while ($result && ($row = $result->fetch_object("Author"))) {
            $match = -1;
            for ($i = 0; $match < 0 && $i < count($aulist); ++$i) {
                if (strcasecmp($aulist[$i]->email, $row->email) == 0)
                    $match = $i;
            }
            if (($row->firstName !== "" || $row->lastName !== "") && $match < 0) {
                $contact_n = $row->firstName . " " . $row->lastName;
                $contact_preg = str_replace("\\.", "\\S*", "{\\b" . preg_quote($row->firstName) . "\\b.*\\b" . preg_quote($row->lastName) . "\\b}i");
                for ($i = 0; $match < 0 && $i < count($aulist); ++$i) {
                    $f = $aulist[$i]->firstName;
                    $l = $aulist[$i]->lastName;
                    if (($f !== "" || $l !== "") && $aulist[$i]->email === "") {
                        $author_n = $f . " " . $l;
                        $author_preg = str_replace("\\.", "\\S*", "{\\b" . preg_quote($f) . "\\b.*\\b" . preg_quote($l) . "\\b}i");
                        if (preg_match($contact_preg, $author_n)
                            || preg_match($author_preg, $contact_n))
                            $match = $i;
                    }
                }
            }
            if ($match >= 0) {
                $au = $aulist[$match];
                if ($au->email === "") {
                    $au->email = $row->email;
                }
            } else {
                $contacts[] = $au = $row;
                $au->nonauthor = true;
            }
            $au->contactId = (int) $row->contactId;
        }
        Dbl::free($result);

        uasort($contacts, $this->conf->user_comparator());
        return array($aulist, $contacts);
    }

    function render_authors(FieldRender $fr, PaperOption $o) {
        $fr->title = false;
        $fr->value_format = 5;

        $vas = $this->user->view_authors_state($this->prow);
        if ($vas === 0) {
            $fr->value = '<div class="pg">'
                . $this->papt("authorInformation", $o->title_html(0))
                . '<div class="pavb"><i>Hidden for blind review</i></div>'
                . "</div>\n\n";
            return;
        }

        // clean author information
        list($aulist, $contacts) = $this->_analyze_authors();

        // "author" or "authors"?
        $auname = $o->title_html(count($aulist));
        if ($vas === 1) {
            $auname .= " (deblinded)";
        } else if ($this->user->act_author_view($this->prow)) {
            $sb = $this->conf->submission_blindness();
            if ($sb === Conf::BLIND_ALWAYS
                || ($sb === Conf::BLIND_OPTIONAL && $this->prow->blind)) {
                $auname .= " (blind)";
            } else if ($sb === Conf::BLIND_UNTILREVIEW) {
                $auname .= " (blind until review)";
            }
        }

        // header with folding
        $fr->value = '<div class="pg">'
            . '<div class="'
            . $this->control_class("authors", "pavt ui js-aufoldup")
            . '"><h3 class="pavfn">';
        if ($vas === 1 || $this->allFolded) {
            $fr->value .= '<a class="q ui js-aufoldup" href="" title="Toggle author display" role="button" aria-expanded="' . ($this->foldmap[8] ? "false" : "true") . '">';
        }
        if ($vas === 1) {
            $fr->value .= '<span class="fn8">' . $o->title_html(0) . '</span><span class="fx8">';
        }
        if ($this->allFolded) {
            $fr->value .= expander(null, 9);
        } else if ($vas === 1) {
            $fr->value .= expander(false);
        }
        $fr->value .= $auname;
        if ($vas === 1) {
            $fr->value .= '</span>';
        }
        if ($vas === 1 || $this->allFolded) {
            $fr->value .= '</a>';
        }
        $fr->value .= '</h3></div>';

        // contents
        $fr->value .= '<div class="pavb">';
        if ($vas === 1) {
            $fr->value .= '<a class="q fn8 ui js-aufoldup" href="" title="Toggle author display">'
                . '+&nbsp;<i>Hidden for blind review</i>'
                . '</a><div class="fx8">';
        }
        if ($this->allFolded) {
            $fr->value .= '<div class="fn9">'
                . $this->authorData($aulist, "last", null)
                . ' <a class="ui js-aufoldup" href="">[details]</a>'
                . '</div><div class="fx9">';
        }
        $fr->value .= $this->authorData($aulist, "col", $this->user);
        if ($this->allFolded) {
            $fr->value .= '</div>';
        }
        if ($vas === 1) {
            $fr->value .= '</div>';
        }
        $fr->value .= "</div></div>\n\n";

        // contacts
        if (!empty($contacts)
            && ($this->editable
                || $this->mode !== "edit"
                || $this->prow->timeSubmitted <= 0)) {
            $contacts_option = $this->conf->option_by_id(PaperOption::CONTACTSID);
            $fr->value .= '<div class="pg fx9' . ($vas > 1 ? "" : " fx8") . '">'
                . $this->papt("authorInformation", $contacts_option->title_html(count($contacts)))
                . '<div class="pavb">'
                . $this->authorData($contacts, "col", $this->user)
                . "</div></div>\n\n";
        }
    }

    /** @param PaperOption $o
     * @param int $vos
     * @param FieldRender $fr */
    private function clean_render($o, $vos, $fr) {
        if ($fr->title === false) {
            assert($fr->value_format === 5);
            return;
        }

        if ($fr->title === null) {
            $fr->title = $o->title();
        }

        $fr->value = $fr->value_html();
        $fr->value_format = 5;

        if ($fr->title !== "" && $o->display_group && !$fr->value_long) {
            $title = htmlspecialchars($fr->title);
            if ($fr->value === "") {
                $fr->value = '<h3 class="pavfn">' . $title . '</h3>';
            } else if ($fr->value[0] === "<"
                       && preg_match('{\A((?:<(?:div|p).*?>)*)}', $fr->value, $cm)) {
                $fr->value = $cm[1] . '<h3 class="pavfn pavfnsp">' . $title
                    . ':</h3> ' . substr($fr->value, strlen($cm[1]));
            } else {
                $fr->value = '<h3 class="pavfn pavfnsp">' . $title . ':</h3> ' . $fr->value;
            }
            $fr->value_long = false;
            $fr->title = "";
        }
    }

    /** @param list<PaperTableFieldRender> $renders
     * @param int $first
     * @param int $last
     * @param int $vos */
    private function _group_name_html($renders, $first, $last, $vos) {
        $group_names = [];
        $group_flags = 0;
        for ($i = $first; $i !== $last; ++$i) {
            if ($renders[$i]->view_state >= $vos) {
                $o = $renders[$i]->option;
                $group_names[] = $o->title();
                if ($o->id === -1005) {
                    $group_flags |= 1;
                } else if ($o->has_document()) {
                    $group_flags |= 2;
                } else {
                    $group_flags |= 4;
                }
            }
        }
        $group_types = [];
        if ($group_flags & 1) {
            $group_types[] = "Topics";
        }
        if ($group_flags & 2) {
            $group_types[] = "Attachments";
        }
        if ($group_flags & 4) {
            $group_types[] = "Options";
        }
        return htmlspecialchars($this->conf->_c("field_group", $renders[$first]->option->display_group, commajoin($group_names), commajoin($group_types)));
    }

    private function _echo_normal_body() {
        $status_info = $this->user->paper_status_info($this->prow);
        echo '<p class="pgsm"><span class="pstat ', $status_info[0], '">',
            htmlspecialchars($status_info[1]), "</span></p>";

        $renders = [];
        $fr = new FieldRender(FieldRender::CPAGE);
        $fr->table = $this;
        foreach ($this->prow->display_fields() as $o) {
            if ($o->display_position() === false
                || $o->display_position() < 1000
                || $o->display_position() >= 5000
                || ($vos = $this->user->view_option_state($this->prow, $o)) === 0) {
                continue;
            }

            $fr->clear();
            $o->render($fr, $this->prow->force_option($o));
            if (!$fr->is_empty()) {
                $this->clean_render($o, $vos, $fr);
                $renders[] = new PaperTableFieldRender($o, $vos, $fr);
            }
        }

        $lasto1 = null;
        $in_paperinfo_i = false;
        for ($first = 0; $first !== count($renders); $first = $last) {
            // compute size of group
            $o1 = $renders[$first]->option;
            $last = $first + 1;
            if ($o1->display_group !== null && $this->allFolded) {
                while ($last !== count($renders)
                       && $renders[$last]->option->display_group === $o1->display_group) {
                    ++$last;
                }
            }

            $nvos1 = 0;
            for ($i = $first; $i !== $last; ++$i) {
                if ($renders[$i]->view_state === 1) {
                    ++$nvos1;
                }
            }

            // change column
            if ($o1->display_position() >= 2000) {
                if (!$lasto1 || $lasto1->display_position() < 2000) {
                    echo '<div class="paperinfo"><div class="paperinfo-c">';
                } else if ($o1->display_position() >= 3000
                           && $lasto1->display_position() < 3000) {
                    if ($in_paperinfo_i) {
                        echo '</div>'; // paperinfo-i
                        $in_paperinfo_i = false;
                    }
                    echo '</div><div class="paperinfo-c">';
                }
                if ($o1->display_expand) {
                    if ($in_paperinfo_i) {
                        echo '</div>';
                        $in_paperinfo_i = false;
                    }
                    echo '<div class="paperinfo-i paperinfo-i-expand">';
                } else if (!$in_paperinfo_i) {
                    echo '<div class="paperinfo-i">';
                    $in_paperinfo_i = true;
                }
            }

            // echo start of group
            if ($o1->display_group !== null && $this->allFolded) {
                if ($nvos1 === 0 || $nvos1 === $last - $first) {
                    $group_html = $this->_group_name_html($renders, $first, $last, $nvos1 === 0 ? 2 : 1);
                } else {
                    $group_html = $this->_group_name_html($renders, $first, $last, 2);
                    $gn1 = $this->_group_name_html($renders, $first, $last, 1);
                    if ($group_html !== $gn1) {
                        $group_html = '<span class="fn8">' . $group_html . '</span><span class="fx8">' . $gn1 . '</span>';
                    }
                }

                $class = "pg";
                if ($nvos1 === $last - $first) {
                    $class .= " fx8";
                }
                $foldnum = $this->foldnumber[$o1->display_group] ?? 0;
                if ($foldnum && $renders[$first]->title !== "") {
                    $group_html = '<span class="fn' . $foldnum . '">'
                        . $group_html . '</span><span class="fx' . $foldnum
                        . '">' . $renders[$first]->title . '</span>';
                    $renders[$first]->title = false;
                    $renders[$first]->value = '<div class="'
                        . ($renders[$first]->value_long ? "pg" : "pgsm")
                        . ' pavb">' . $renders[$first]->value . '</div>';
                }
                echo '<div class="', $class, '">';
                if ($foldnum) {
                    echo '<div class="pavt ui js-foldup" data-fold-target="', $foldnum, '">',
                        '<h3 class="pavfn">',
                        '<a class="q ui js-foldup" href="" data-fold-target="', $foldnum, '" title="Toggle visibility" role="button" aria-expanded="',
                        $this->foldmap[$foldnum] ? "false" : "true",
                        '">', expander(null, $foldnum),
                        $group_html,
                        '</a></h3></div><div class="pg fx', $foldnum, '">';
                } else {
                    echo '<div class="pavt"><h3 class="pavfn">',
                        $group_html,
                        '</h3></div><div class="pg">';
                }
            }

            // echo contents
            for ($i = $first; $i !== $last; ++$i) {
                $x = $renders[$i];
                if ($x->value_long === false
                    || (!$x->value_long && $x->title === "")) {
                    $class = "pgsm";
                } else {
                    $class = "pg";
                }
                if ($x->value === ""
                    || ($x->title === "" && preg_match('{\A(?:[^<]|<a|<span)}', $x->value))) {
                    $class .= " outdent";
                }
                if ($x->view_state === 1) {
                    $class .= " fx8";
                }
                if ($x->title === false) {
                    echo $x->value;
                } else if ($x->title === "") {
                    echo '<div class="', $class, '">', $x->value, '</div>';
                } else if ($x->value === "") {
                    echo '<div class="', $class, '"><h3 class="pavfn">', $x->title, '</h3></div>';
                } else {
                    echo '<div class="', $class, '"><div class="pavt"><h3 class="pavfn">', $x->title, '</h3></div><div class="pavb">', $x->value, '</div></div>';
                }
            }

            // echo end of group
            if ($o1->display_group !== null && $this->allFolded) {
                echo '</div></div>';
            }
            if ($o1->display_position() >= 2000
                && $o1->display_expand) {
                echo '</div>';
            }
            $lasto1 = $o1;
        }

        // close out display
        if ($in_paperinfo_i) {
            echo '</div>';
        }
        if ($lasto1 && $lasto1->display_position() >= 2000) {
            echo '</div></div>';
        }
    }


    private function _papstrip_framework() {
        if (!$this->npapstrip) {
            echo '<article class="pcontainer"><div class="pcard-left',
                '"><div class="pspcard"><div class="ui pspcard-fold">',
                '<div style="float:right;margin-left:1em;cursor:pointer"><span class="psfn">More ', expander(true), '</span></div>';

            if (($viewable = $this->prow->sorted_viewable_tags($this->user))) {
                $tagger = new Tagger($this->user);
                echo '<span class="psfn">Tags:</span> ',
                    $tagger->unparse_link($viewable);
            } else {
                echo '<hr class="c">';
            }

            echo '</div><div class="pspcard-open">';
        }
        ++$this->npapstrip;
    }

    private function _papstripBegin($foldid = null, $folded = null, $extra = null) {
        $this->_papstrip_framework();
        echo '<div';
        if ($foldid) {
            echo " id=\"fold$foldid\"";
        }
        echo ' class="psc';
        if ($foldid) {
            echo " fold", ($folded ? "c" : "o");
        }
        if ($extra) {
            if (isset($extra["class"])) {
                echo " ", $extra["class"];
            }
            foreach ($extra as $k => $v) {
                if ($k !== "class")
                    echo "\" $k=\"", str_replace("\"", "&quot;", $v);
            }
        }
        echo '">';
    }

    private function papstripCollaborators() {
        if (!$this->conf->setting("sub_collab")
            || !$this->prow->collaborators
            || strcasecmp(trim($this->prow->collaborators), "None") == 0) {
            return;
        }
        $fold = $this->user->session("foldpscollab", 1) ? 1 : 0;

        $data = $this->highlight($this->prow->collaborators(), "co", $match);
        $data = nl2br($data);
        if ($match || !$this->allFolded) {
            $fold = 0;
        }

        $option = $this->conf->option_by_id(PaperOption::COLLABORATORSID);
        $this->_papstripBegin("pscollab", $fold, ["data-fold-storage" => "p.collab", "class" => "need-fold-storage"]);
        echo $this->papt("collaborators", $option->title_html(),
                         ["type" => "ps", "fold" => "pscollab", "folded" => $fold]),
            '<div class="psv"><div class="fx">', $data,
            "</div></div></div>\n\n";
    }

    private function papstripPCConflicts() {
        assert(!$this->editable && $this->prow->paperId);
        $pcconf = [];
        $pcm = $this->conf->pc_members();
        foreach ($this->prow->pc_conflicts() as $id => $cflt) {
            if (Conflict::is_conflicted($cflt->conflictType)) {
                $p = $pcm[$id];
                $pcconf[$p->sort_position] = '<li class="odname">'
                    . $this->user->reviewer_html_for($p) . '</li>';
            }
        }
        ksort($pcconf);
        if (empty($pcconf)) {
            $pcconf[] = '<li class="odname">None</li>';
        }
        $this->_papstripBegin();
        $option = $this->conf->option_by_id(PaperOption::PCCONFID);
        echo $this->papt("pc_conflicts", $option->title_html(), ["type" => "ps"]),
            '<div class="psv"><ul class="x namelist-columns">', join("", $pcconf), "</ul></div></div>\n";
    }

    private function _papstripLeadShepherd($type, $name, $showedit) {
        $editable = $type === "manager" ? $this->user->privChair : $this->admin;
        $extrev_shepherd = $type === "shepherd" && $this->conf->setting("extrev_shepherd");

        $field = $type . "ContactId";
        if ($this->prow->$field == 0 && !$editable) {
            return;
        }
        $value = $this->prow->$field;

        $this->_papstripBegin($type, true, $editable ? ["class" => "ui-unfold js-unfold-pcselector js-unfold-focus need-paper-select-api"] : "");
        echo $this->papt($type, $name, array("type" => "ps", "fold" => $editable ? $type : false, "folded" => true)),
            '<div class="psv">';
        if (!$value) {
            $n = "";
        } else if (($p = $this->conf->user_by_id($value))
                   && ($p->isPC
                       || ($extrev_shepherd && $this->prow->review_type($p) == REVIEW_EXTERNAL))) {
            $n = $this->user->reviewer_html_for($p);
        } else {
            $n = "<strong>[removed from PC]</strong>";
        }
        echo '<div class="pscopen"><p class="fn odname js-psedit-result">',
            $n, '</p></div>';

        if ($editable) {
            $this->conf->stash_hotcrp_pc($this->user);
            $selopt = "0 assignable";
            if ($type === "shepherd" && $this->conf->setting("extrev_shepherd")) {
                $selopt .= " extrev";
            }
            echo '<form class="ui-submit uin fx">',
                Ht::select($type, [], 0, ["class" => "w-99 want-focus", "data-pcselector-options" => $selopt . " selected", "data-pcselector-selected" => $value]),
                '</form>';
        }

        echo "</div></div>\n";
    }

    private function papstripLead($showedit) {
        $this->_papstripLeadShepherd("lead", "Discussion lead", $showedit || $this->qreq->atab === "lead");
    }

    private function papstripShepherd($showedit) {
        $this->_papstripLeadShepherd("shepherd", "Shepherd", $showedit || $this->qreq->atab === "shepherd");
    }

    private function papstripManager($showedit) {
        $this->_papstripLeadShepherd("manager", "Paper administrator", $showedit || $this->qreq->atab === "manager");
    }

    /** @param string $msg
     * @param int $status */
    private function echo_tag_report_message($msg, $status) {
        echo '<p class="', MessageSet::status_class($status, "feedback", "is-"), '">';
        if (preg_match('/\A(#' . TAG_REGEX . '?)(: .*)\z/s', $msg, $m)) {
            echo Ht::link($m[1], $this->conf->hoturl("search", ["q" => $m[1]]), ["class" => "q"]), $m[2];
        } else {
            echo $msg;
        }
        echo '</p>';
    }

    private function papstripTags() {
        if (!$this->prow->paperId || !$this->user->can_view_tags($this->prow)) {
            return;
        }

        $tags = $this->prow->all_tags_text();
        $is_editable = $this->user->can_change_some_tag($this->prow);
        $is_sitewide = $is_editable && !$this->user->can_change_most_tags($this->prow);
        if ($tags === "" && !$is_editable) {
            return;
        }

        // Note that tags MUST NOT contain HTML special characters.
        $tagger = new Tagger($this->user);
        $viewable = $this->prow->sorted_viewable_tags($this->user);

        $tx = $tagger->unparse_link($viewable);
        $unfolded = $is_editable && ($this->has_problem_at("tags") || $this->qreq->atab === "tags");

        $this->_papstripBegin("tags", true, $is_editable ? ["class" => "need-tag-form js-unfold-focus"] : []);

        if ($is_editable) {
            echo Ht::form($this->prow->hoturl(), ["data-pid" => $this->prow->paperId, "data-no-tag-report" => $unfolded ? 1 : null]);
        }

        echo $this->papt("tags", "Tags", ["type" => "ps", "fold" => $is_editable ? "tags" : false]),
            '<div class="psv">';
        if ($is_editable) {
            $treport = Tags_API::tagmessages($this->user, $this->prow);

            // uneditable
            echo '<div class="fn want-tag-report-warnings">';
            foreach ($treport->message_list as $tr) {
                if ($tr->status > 0) {
                    $this->echo_tag_report_message($tr->message, $tr->status);
                }
            }
            echo '</div><div class="fn js-tag-result">',
                ($tx === "" ? "None" : $tx), '</div>';

            echo '<div class="fx js-tag-editor"><div class="want-tag-report">';
            foreach ($treport->message_list as $tr) {
                $this->echo_tag_report_message($tr->message, $tr->status);
            }
            echo "</div>";
            if ($is_sitewide) {
                echo '<p class="feedback is-warning">You have a conflict with this submission, so you can only edit its ', Ht::link("site-wide tags", $this->conf->hoturl("settings", "group=tags#tag_sitewide")), '.';
                if ($this->user->allow_administer($this->prow)) {
                    echo ' ', Ht::link("Override your conflict", $this->conf->selfurl($this->qreq, ["forceShow" => 1])), ' to view and edit all tags.';
                }
                echo '</p>';
            }
            $editable = $this->prow->sorted_editable_tags($this->user);
            echo '<textarea cols="20" rows="4" name="tags" class="w-99 want-focus need-suggest ',
                $is_sitewide ? "sitewide-editable-tags" : "editable-tags",
                '" spellcheck="false">', $tagger->unparse($editable), '</textarea>',
                '<div class="aab aabr aab-compact"><div class="aabut">',
                Ht::submit("save", "Save", ["class" => "btn-primary"]),
                '</div><div class="aabut">',
                Ht::submit("cancel", "Cancel"),
                "</div></div>",
                '<span class="hint"><a href="', $this->conf->hoturl("help", "t=tags"), '">Learn more</a> <span class="barsep">·</span> <strong>Tip:</strong> Twiddle tags like “~tag” are visible only to you.</span>',
                "</div>";
        } else {
            echo '<div class="js-tag-result">', ($tx === "" ? "None" : $tx), '</div>';
        }
        echo "</div>";

        if ($is_editable) {
            echo "</form>";
        }
        if ($unfolded) {
            echo Ht::unstash_script('hotcrp.fold("tags",0)');
        }
        echo "</div>\n";
    }

    function papstripOutcomeSelector() {
        $this->_papstripBegin("decision", $this->qreq->atab !== "decision", ["class" => "need-paper-select-api js-unfold-focus"]);
        echo $this->papt("decision", "Decision", array("type" => "ps", "fold" => "decision")),
            '<div class="psv"><form class="ui-submit uin fx">';
        if (isset($this->qreq->forceShow)) {
            echo Ht::hidden("forceShow", $this->qreq->forceShow ? 1 : 0);
        }
        echo Ht::select("decision", $this->conf->decision_map(),
                        (string) $this->prow->outcome,
                        ["class" => "w-99 want-focus"]),
            '</form><p class="fn odname js-psedit-result">',
            htmlspecialchars($this->conf->decision_name($this->prow->outcome)),
            "</p></div></div>\n";
    }

    function papstripReviewPreference() {
        $this->_papstripBegin("revpref");
        echo $this->papt("revpref", "Review preference", ["type" => "ps"]),
            "<div class=\"psv\"><form class=\"ui\">";
        $rp = unparse_preference($this->prow->preference($this->user));
        $rp = ($rp == "0" ? "" : $rp);
        echo "<input id=\"revprefform_d\" type=\"text\" name=\"revpref", $this->prow->paperId,
            "\" size=\"4\" value=\"$rp\" class=\"revpref want-focus want-select\">",
            "</form></div></div>\n";
        Ht::stash_script("hotcrp.add_preference_ajax(\"#revprefform_d\",true);hotcrp.shortcut(\"revprefform_d\").add()");
    }

    private function papstrip_tag_entry($id) {
        $this->_papstripBegin($id, !!$id, ["class" => "pste js-unfold-focus"]);
    }

    private function papstrip_tag_float($tag, $kind, $type) {
        if (!$this->user->can_view_tag($this->prow, $tag)) {
            return "";
        }
        $totval = $this->prow->tag_value($tag) ?? "";
        $class = "is-nonempty-tags float-right" . ($totval === "" ? " hidden" : "");
        $reverse = $type !== "rank";
        $extradiv = "";
        if (($type === "allotment" || $type === "approval")
            && $this->user->can_view_peruser_tag($this->prow, $tag)) {
            $class .= " need-tooltip";
            $extradiv = ' data-tooltip-dir="h" data-tooltip-info="votereport" data-tag="' . htmlspecialchars($tag) . '"';
        }
        return '<div class="' . $class . '"' . $extradiv
            . '><a class="qq" href="' . $this->conf->hoturl("search", "q=" . urlencode("show:#$tag sort:" . ($reverse ? "-" : "") . "#$tag")) . '">'
            . '<span class="is-tag-index" data-tag-base="' . $tag . '">' . $totval . '</span> ' . $kind . '</a></div>';
    }

    private function papstrip_tag_entry_title($s, $tag, $value) {
        $ts = "#$tag";
        if (($color = $this->conf->tags()->color_classes($tag))) {
            $ts = '<span class="' . $color . ' taghh">' . $ts . '</span>';
        }
        $s = str_replace("{{}}", $ts, $s);
        if ($value !== false) {
            $s .= '<span class="fn is-nonempty-tags'
                . ($value === "" ? " hidden" : "")
                . '">: <span class="is-tag-index" data-tag-base="~'
                . $tag . '">' . $value . '</span></span>';
        }
        return $s;
    }

    private function papstrip_rank($tag) {
        $id = "rank_" . html_id_encode($tag);
        $myval = $this->prow->tag_value($this->user->contactId . "~$tag") ?? "";
        $totmark = $this->papstrip_tag_float($tag, "overall", "rank");

        $this->papstrip_tag_entry($id);
        echo Ht::form("", ["class" => "need-tag-index-form", "data-pid" => $this->prow->paperId]);
        if (isset($this->qreq->forceShow)) {
            echo Ht::hidden("forceShow", $this->qreq->forceShow);
        }
        echo $this->papt($id, $this->papstrip_tag_entry_title("{{}} rank", $tag, $myval),
                         array("type" => "ps", "fold" => $id, "float" => $totmark)),
            '<div class="psv"><div class="fx">',
            Ht::entry("tagindex", $myval,
                      ["size" => 4, "class" => "is-tag-index want-focus",
                       "data-tag-base" => "~$tag", "inputmode" => "decimal"]),
            ' <span class="barsep">·</span> ',
            '<a href="', $this->conf->hoturl("search", "q=" . urlencode("editsort:#~$tag")), '">Edit all</a>',
            ' <div class="hint" style="margin-top:4px"><strong>Tip:</strong> <a href="', $this->conf->hoturl("search", "q=" . urlencode("editsort:#~$tag")), '">Search “editsort:#~', $tag, '”</a> to drag and drop your ranking, or <a href="', $this->conf->hoturl("offline"), '">use offline reviewing</a> to rank many papers at once.</div>',
            "</div></div></form></div>\n";
    }

    private function papstrip_allotment($tag, $allotment) {
        $id = "vote_" . html_id_encode($tag);
        $myval = $this->prow->tag_value($this->user->contactId . "~$tag") ?? "";
        $totmark = $this->papstrip_tag_float($tag, "total", "allotment");

        $this->papstrip_tag_entry($id);
        echo Ht::form("", ["class" => "need-tag-index-form", "data-pid" => $this->prow->paperId]);
        if (isset($this->qreq->forceShow)) {
            echo Ht::hidden("forceShow", $this->qreq->forceShow);
        }
        echo $this->papt($id, $this->papstrip_tag_entry_title("{{}} votes", $tag, $myval),
                         ["type" => "ps", "fold" => $id, "float" => $totmark]),
            '<div class="psv"><div class="fx">',
            Ht::entry("tagindex", $myval, ["size" => 4, "class" => "is-tag-index want-focus", "data-tag-base" => "~$tag", "inputmode" => "decimal"]),
            " &nbsp;of $allotment",
            ' <span class="barsep">·</span> ',
            '<a href="', $this->conf->hoturl("search", "q=" . urlencode("editsort:-#~$tag")), '">Edit all</a>',
            "</div></div></form></div>\n";
    }

    private function papstrip_approval($tag) {
        $id = "approval_" . html_id_encode($tag);
        $myval = $this->prow->tag_value($this->user->contactId . "~$tag") ?? "";
        $totmark = $this->papstrip_tag_float($tag, "total", "approval");

        $this->papstrip_tag_entry(null);
        echo Ht::form("", ["class" => "need-tag-index-form", "data-pid" => $this->prow->paperId]);
        if (isset($this->qreq->forceShow)) {
            echo Ht::hidden("forceShow", $this->qreq->forceShow);
        }
        echo $this->papt($id,
            $this->papstrip_tag_entry_title('<label><span class="checkc">'
                . Ht::checkbox("tagindex", "0", $myval !== "", ["class" => "is-tag-index want-focus", "data-tag-base" => "~$tag"])
                . '</span>{{}} vote</label>', $tag, false),
            ["type" => "ps", "fnclass" => "checki", "float" => $totmark]),
            "</form></div>\n";
    }

    private function papstripWatch() {
        if ($this->prow->timeSubmitted <= 0
            || $this->user->contactId <= 0
            || ($this->prow->has_conflict($this->user)
                && !$this->prow->has_author($this->user)
                && !$this->user->is_admin_force())) {
            return;
        }

        $this->_papstripBegin();

        echo '<form class="ui-submit uin">',
            $this->papt("watch",
                '<label><span class="checkc">'
                . Ht::checkbox("follow", 1, $this->user->following_reviews($this->prow), ["class" => "uich js-follow-change"])
                . '</span>Email notification</label>',
                ["type" => "ps", "fnclass" => "checki"]),
            '<div class="pshint">Select to receive email on updates to reviews and comments.</div>',
            "</form></div>\n";
    }


    // Functions for editing

    function deadline_setting_is($dname, $dl = "deadline") {
        $deadline = $this->conf->unparse_setting_time_span($dname);
        if ($deadline === "N/A") {
            return "";
        } else if (Conf::$now < $this->conf->setting($dname)) {
            return " The $dl is $deadline.";
        } else {
            return " The $dl was $deadline.";
        }
    }

    private function _deadline_override_message() {
        if ($this->admin) {
            return " As an administrator, you can make changes anyway.";
        } else {
            return $this->_forceShow_message();
        }
    }
    private function _forceShow_message() {
        if (!$this->admin && $this->allow_admin) {
            return " " . Ht::link("(Override your conflict)", $this->conf->selfurl($this->qreq, ["forceShow" => 1]), ["class" => "nw"]);
        } else {
            return "";
        }
    }
    private function _main_message($m, $status) {
        $this->edit_status->msg_at(":main", $m, $status);
    }

    private function _edit_message_new_paper_deadline() {
        $sub_open = $this->conf->setting("sub_open");
        if ($sub_open <= 0 || $sub_open > Conf::$now) {
            $msg = "The site is not open for submissions." . $this->_deadline_override_message();
        } else {
            $msg = 'The <a href="' . $this->conf->hoturl("deadlines") . '">deadline</a> for registering submissions has passed.' . $this->deadline_setting_is("sub_reg") . $this->_deadline_override_message();
        }
        $this->_main_message($msg, $this->admin ? 1 : 2);
    }
    private function _edit_message_new_paper() {
        $msg = "";
        if ($this->admin || $this->conf->timeStartPaper()) {
            $t = [$this->conf->_("Enter information about your submission.")];
            $sub_reg = $this->conf->setting("sub_reg");
            $sub_upd = $this->conf->setting("sub_update");
            if ($sub_reg > 0 && $sub_upd > 0 && $sub_reg < $sub_upd) {
                $t[] = $this->conf->_("All submissions must be registered by %s and completed by %s.", $this->conf->unparse_setting_time("sub_reg"), $this->conf->unparse_setting_time("sub_sub"));
                if (!$this->conf->opt("noPapers")) {
                    $t[] = $this->conf->_("PDF upload is not required to register.");
                }
            } else if ($sub_upd > 0) {
                $t[] = $this->conf->_("All submissions must be completed by %s.", $this->conf->unparse_setting_time("sub_update"));
            }
            $this->_main_message(space_join($t), 0);
            if (($v = $this->conf->_i("submit"))) {
                $this->_main_message($v, 0);
            }
        }
        if (!$this->conf->timeStartPaper()) {
            $this->_edit_message_new_paper_deadline();
            $this->quit = $this->quit || !$this->admin;
        }
    }

    private function _edit_message_for_author() {
        $can_view_decision = $this->prow->outcome != 0
            && $this->user->can_view_decision($this->prow);
        if ($can_view_decision && $this->prow->outcome < 0) {
            $this->_main_message("This submission was not accepted." . $this->_forceShow_message(), 1);
        } else if ($this->prow->timeWithdrawn > 0) {
            if ($this->user->can_revive_paper($this->prow)) {
                $this->_main_message("This submission has been withdrawn, but you can still revive it." . $this->deadline_setting_is("sub_update"), 1);
            } else {
                $this->_main_message("This submission has been withdrawn." . $this->_forceShow_message(), 1);
            }
        } else if ($this->prow->timeSubmitted <= 0) {
            $whyNot = $this->user->perm_edit_paper($this->prow);
            if (!$whyNot) {
                $t = [];
                $t[] = $this->conf->_("This submission is not yet ready for review.");
                if ($this->conf->setting("sub_update")) {
                    $t[] = $this->conf->_("All submissions must be completed by %s to be considered.", $this->conf->unparse_setting_time("sub_update"));
                } else {
                    $t[] = $this->conf->_("Incomplete submissions will not be considered.");
                }
                $this->_main_message(space_join($t), 1);
            } else if (isset($whyNot["updateSubmitted"])
                       && $this->user->can_finalize_paper($this->prow)) {
                $this->_main_message('This submission is not ready for review. Although you cannot make any further changes, the current version can be still be submitted for review.' . $this->deadline_setting_is("sub_sub") . $this->_deadline_override_message(), 1);
            } else if (isset($whyNot["deadline"])) {
                if ($this->conf->deadlinesBetween("", "sub_sub", "sub_grace")) {
                    $this->_main_message('The site is not open for updates at the moment.' . $this->_deadline_override_message(), 1);
                } else {
                    $this->_main_message('The <a href="' . $this->conf->hoturl("deadlines") . '">submission deadline</a> has passed and this submission will not be reviewed.' . $this->deadline_setting_is("sub_sub") . $this->_deadline_override_message(), 1);
                }
            } else {
                $this->_main_message('This submission is not ready for review and can’t be changed further. It will not be reviewed.' . $this->_deadline_override_message(), 1);
            }
        } else if ($this->user->can_edit_paper($this->prow)) {
            if ($this->mode === "edit") {
                $this->_main_message('This submission is ready and will be considered for review. You do not need to take further action. However, you can still make changes if you wish.' . $this->deadline_setting_is("sub_update", "submission deadline"), MessageSet::SUCCESS);
            }
        } else if ($this->conf->allow_final_versions()
                   && $this->prow->outcome > 0
                   && $can_view_decision) {
            if ($this->user->can_edit_final_paper($this->prow)) {
                if (($t = $this->conf->_i("finalsubmit", null, $this->deadline_setting_is("final_soft")))) {
                    $this->_main_message($t, 0);
                }
            } else if ($this->mode === "edit") {
                $this->_main_message("The deadline for updating final versions has passed. You can still change contact information." . $this->_deadline_override_message(), 1);
            }
        } else if ($this->mode === "edit") {
            if ($this->user->can_withdraw_paper($this->prow, true)) {
                $t = "This submission is under review and can’t be changed, but you can change its contacts or withdraw it from consideration.";
            } else {
                $t = "This submission is under review and can’t be changed or withdrawn, but you can change its contacts.";
            }
            $this->_main_message($t . $this->_deadline_override_message(), MessageSet::NOTE);
        }
    }

    private function _edit_message_existing_paper() {
        $has_author = $this->prow->has_author($this->user);
        $can_view_decision = $this->prow->outcome != 0 && $this->user->can_view_decision($this->prow);
        if ($has_author) {
            $this->_edit_message_for_author();
        } else if ($this->conf->allow_final_versions()
                   && $this->prow->outcome > 0
                   && !$this->prow->can_author_view_decision()) {
            $this->_main_message("The submission has been accepted, but its authors can’t see that yet. Once decisions are visible, the system will allow accepted authors to upload final versions.", 1);
        } else {
            $this->_main_message("You aren’t a contact for this submission, but as an administrator you can still make changes.", MessageSet::NOTE);
        }
        if ($this->user->call_with_overrides($this->user->overrides() | Contact::OVERRIDE_TIME, "can_edit_paper", $this->prow)
            && ($v = $this->conf->_i("submit"))) {
            $this->_main_message($v, 0);
        }
        if ($this->edit_status
            && $this->edit_status->has_problem()
            && ($this->edit_status->has_problem_at("contacts") || $this->editable)) {
            $fields = [];
            foreach ($this->edit_fields ?? [] as $o) {
                if ($this->edit_status->has_problem_at($o->formid))
                    $fields[] = Ht::link(htmlspecialchars($o->edit_title()), "#" . $o->readable_formid());
            }
            if (!empty($fields)) {
                $this->_main_message($this->conf->_c("paper_edit", "Please fix %s before completing the submission.", commajoin($fields)), $this->edit_status->problem_status());
            }
        }
    }

    private function _echo_edit_messages($include_required) {
        if (!$this->prow->paperId) {
            $this->_edit_message_new_paper();
        } else {
            $this->_edit_message_existing_paper();
        }
        if ($include_required && !$this->quit) {
            foreach ($this->edit_fields as $e) {
                if ($e->required) {
                    $this->_main_message('<span class="field-required-explanation">* Required</span>', 0);
                    break;
                }
            }
        }
        if ($this->edit_status->has_messages_at(":main")) {
            echo '<div class="papeg">', $this->messages_at(":main"), '</div>';
        }
    }

    private function _save_name() {
        if (!$this->is_ready(false)) {
            return "Save draft";
        } else if ($this->prow->timeSubmitted > 0) {
            return "Save and resubmit";
        } else {
            return "Save and submit";
        }
    }

    private function _collect_actions() {
        $pid = $this->prow->paperId ? : "new";

        // Withdrawn papers can be revived
        if ($this->prow->timeWithdrawn > 0) {
            $revivable = $this->conf->timeFinalizePaper($this->prow);
            if ($revivable) {
                return [Ht::submit("revive", "Revive submission", ["class" => "btn-primary"])];
            } else if ($this->admin) {
                return [[Ht::button("Revive submission", ["class" => "ui js-override-deadlines", "data-override-text" => 'The <a href="' . $this->conf->hoturl("deadlines") . '">deadline</a> for reviving withdrawn submissions has passed. Are you sure you want to override it?', "data-override-submit" => "revive"]), "(admin only)"]];
            } else {
                return [];
            }
        }

        $buttons = [];
        $want_override = false;

        if ($this->mode === "edit") {
            // check whether we can save
            $old_overrides = $this->user->set_overrides(Contact::OVERRIDE_CHECK_TIME);
            if ($this->canUploadFinal) {
                $whyNot = $this->user->perm_edit_final_paper($this->prow);
            } else if ($this->prow->paperId) {
                $whyNot = $this->user->perm_edit_paper($this->prow);
            } else {
                $whyNot = $this->user->perm_start_paper();
            }
            $this->user->set_overrides($old_overrides);
            // produce button
            $save_name = $this->_save_name();
            if (!$whyNot) {
                $buttons[] = [Ht::submit("update", $save_name, ["class" => "btn-primary btn-savepaper uic js-mark-submit"]), ""];
            } else if ($this->admin) {
                $revWhyNot = $whyNot->filter(["deadline", "rejected"]);
                $x = whyNotText($revWhyNot) . " Are you sure you want to override the deadline?";
                $buttons[] = [Ht::button($save_name, ["class" => "btn-primary btn-savepaper ui js-override-deadlines", "data-override-text" => $x, "data-override-submit" => "update"]), "(admin only)"];
            } else if (isset($whyNot["updateSubmitted"])
                       && $this->user->can_finalize_paper($this->prow)) {
                $buttons[] = Ht::submit("update", $save_name, ["class" => "btn-savepaper uic js-mark-submit"]);
            } else if ($this->prow->paperId) {
                $buttons[] = Ht::submit("updatecontacts", "Save contacts", ["class" => "btn-savepaper btn-primary uic js-mark-submit", "data-contacts-only" => 1]);
            }
            if (!empty($buttons)) {
                $buttons[] = Ht::submit("cancel", "Cancel", ["class" => "uic js-mark-submit"]);
                $buttons[] = "";
            }
            $want_override = $whyNot && !$this->admin;
        }

        // withdraw button
        if (!$this->prow->paperId
            || !$this->user->call_with_overrides($this->user->overrides() | Contact::OVERRIDE_TIME, "can_withdraw_paper", $this->prow, true)) {
            $b = null;
        } else if ($this->prow->timeSubmitted <= 0) {
            $b = Ht::submit("withdraw", "Withdraw", ["class" => "uic js-mark-submit"]);
        } else {
            $args = ["class" => "ui js-withdraw"];
            if ($this->user->can_withdraw_paper($this->prow, !$this->admin)) {
                $args["data-withdrawable"] = "true";
            }
            if (($this->admin && !$this->prow->has_author($this->user))
                || $this->conf->timeFinalizePaper($this->prow)) {
                $args["data-revivable"] = "true";
            }
            $b = Ht::button("Withdraw", $args);
        }
        if ($b) {
            if ($this->admin && !$this->user->can_withdraw_paper($this->prow)) {
                $b = [$b, "(admin only)"];
            }
            $buttons[] = $b;
        }

        // override conflict button
        if ($want_override && !$this->admin) {
            if ($this->allow_admin) {
                $buttons[] = "";
                $buttons[] = [Ht::submit("updateoverride", "Override conflict", ["class" => "uic js-mark-submit"]), "(admin only)"];
            } else if ($this->user->privChair) {
                $buttons[] = "";
                $buttons[] = Ht::submit("updateoverride", "Override conflict", ["disabled" => true, "class" => "need-tooltip uic js-mark-submit", "title" => "You cannot override your conflict because this paper has an administrator."]);
            }
        }

        return $buttons;
    }

    private function echo_actions() {
        if ($this->admin) {
            $v = (string) $this->qreq->emailNote;
            echo '<div class="checki"><label><span class="checkc">', Ht::checkbox("doemail", 1, true, ["class" => "ignore-diff"]), "</span>",
                "Email authors, including:</label> ",
                Ht::entry("emailNote", $v, ["size" => 30, "placeholder" => "Optional explanation", "class" => "ignore-diff js-autosubmit", "aria-label" => "Explanation for update"]),
                "</div>";
        }
        if ($this->mode === "edit" && $this->canUploadFinal) {
            echo Ht::hidden("submitfinal", 1);
        }

        $buttons = $this->_collect_actions();
        if ($this->admin && $this->prow->paperId) {
            $buttons[] = [Ht::button("Delete", ["class" => "ui js-delete-paper"]), "(admin only)"];
        }
        echo Ht::actions($buttons, ["class" => "aab aabig"]);
    }


    // Functions for overall paper table viewing

    function _papstrip() {
        if (($this->prow->managerContactId > 0
             || ($this->user->privChair && $this->mode === "assign"))
            && $this->user->can_view_manager($this->prow)) {
            $this->papstripManager($this->user->privChair);
        }
        $this->papstripTags();
        foreach ($this->conf->tags() as $ltag => $t) {
            if ($this->user->can_change_tag($this->prow, "~$ltag", null, 0)) {
                if ($t->approval) {
                    $this->papstrip_approval($t->tag);
                } else if ($t->allotment) {
                    $this->papstrip_allotment($t->tag, $t->allotment);
                } else if ($t->rank) {
                    $this->papstrip_rank($t->tag);
                }
            }
        }
        $this->papstripWatch();
        if ($this->user->can_view_conflicts($this->prow) && !$this->editable) {
            $this->papstripPCConflicts();
        }
        if ($this->user->allow_view_authors($this->prow) && !$this->editable) {
            $this->papstripCollaborators();
        }
        if ($this->user->can_set_decision($this->prow)) {
            $this->papstripOutcomeSelector();
        }
        if ($this->user->can_view_lead($this->prow)) {
            $this->papstripLead($this->mode === "assign");
        }
        if ($this->user->can_view_shepherd($this->prow)) {
            $this->papstripShepherd($this->mode === "assign");
        }
        if ($this->user->can_enter_preference($this->prow)
            && $this->conf->timePCReviewPreferences()
            && ($this->user->roles & (Contact::ROLE_PC | Contact::ROLE_CHAIR))) {
            $this->papstripReviewPreference();
        }
    }

    function _paptabTabLink($text, $link, $image, $highlight) {
        return '<li class="papmode' . ($highlight ? " active" : "")
            . '"><a href="' . $link . '" class="noul">'
            . Ht::img($image, "[$text]", "papmodeimg")
            . "&nbsp;<u" . ($highlight ? ' class="x"' : "") . ">" . $text
            . "</u></a></li>";
    }

    private function _paptabBeginKnown() {
        // what actions are supported?
        $pid = $this->prow->paperId;
        $canEdit = $this->user->allow_edit_paper($this->prow);
        $canReview = $this->user->can_review($this->prow, null);
        $canAssign = $this->admin || $this->user->can_request_review($this->prow, null, true);
        $canHome = $canEdit || $canAssign || $this->mode === "contact";

        $t = "";

        // paper tabs
        if ($canEdit || $canReview || $canAssign || $canHome) {
            $t .= '<nav class="submission-modes"><ul>';

            // home link
            $highlight = ($this->mode !== "assign" && $this->mode !== "edit"
                          && $this->mode !== "contact" && $this->mode !== "re");
            $t .= $this->_paptabTabLink("Main", $this->prow->hoturl(["m" => $this->first_mode === "p" ? null : "main"]), "view48.png", $highlight);

            if ($canEdit) {
                $t .= $this->_paptabTabLink("Edit", $this->prow->hoturl(["m" => "edit"]), "edit48.png", $this->mode === "edit");
            }

            if ($canReview) {
                $t .= $this->_paptabTabLink("Review", $this->prow->reviewurl(["m" => "re"]), "review48.png", $this->mode === "re" && (!$this->editrrow || $this->user->is_my_review($this->editrrow)));
            }

            if ($canAssign) {
                $assign = $this->allow_admin ? "Assign" : "Invite";
                $t .= $this->_paptabTabLink($assign, $this->conf->hoturl("assign", "p=$pid"), "assign48.png", $this->mode === "assign");
            }

            $t .= "</ul></nav>";
        }

        return $t;
    }

    static private function _echo_clickthrough($ctype) {
        $data = Conf::$main->_i("clickthrough_$ctype");
        $buttons = [Ht::submit("Agree", ["class" => "btnbig btn-success ui js-clickthrough"])];
        echo Ht::form("", ["class" => "ui"]), '<div>', $data,
            Ht::hidden("clickthrough_type", $ctype),
            Ht::hidden("clickthrough_id", sha1($data)),
            Ht::hidden("clickthrough_time", Conf::$now),
            Ht::actions($buttons, ["class" => "aab aabig aabr"]), "</div></form>";
    }

    static function echo_review_clickthrough() {
        echo '<div class="pcard revcard js-clickthrough-terms"><div class="revcard-head"><h2>Reviewing terms</h2></div><div class="revcard-body">', Ht::msg("You must agree to these terms before you can save reviews.", 2);
        self::_echo_clickthrough("review");
        echo "</div></div>";
    }

    private function _echo_editable_form() {
        $form_url = [
            "p" => $this->prow->paperId ? : "new", "m" => "edit"
        ];
        // This is normally added automatically, but isn't for new papers
        if ($this->user->is_admin_force()) {
            $form_url["forceShow"] = 1;
        }
        $form_js = [
            "id" => "form-paper",
            "class" => "need-unload-protection ui-submit js-submit-paper",
            "data-alert-toggle" => "paper-alert"
        ];
        if ($this->prow->timeSubmitted > 0) {
            $form_js["data-submitted"] = $this->prow->timeSubmitted;
        }
        if ($this->prow->paperId && !$this->editable) {
            $form_js["data-contacts-only"] = 1;
        }
        if ($this->useRequest) {
            $form_js["class"] .= " alert";
        }
        echo Ht::form($this->conf->hoturl_post("paper", $form_url), $form_js);
        Ht::stash_script('$(hotcrp.load_editable_paper)');
    }

    private function _echo_editable_body() {
        $this->_echo_editable_form();
        $overrides = $this->user->add_overrides(Contact::OVERRIDE_EDIT_CONDITIONS);
        echo '<div class="pedcard-head"><h2><span class="pedcard-header-name">',
            $this->conf->_($this->prow->paperId ? "Edit Submission" : "New Submission"),
            '</span></h2></div>';

        $this->edit_fields = array_values(array_filter(
            $this->prow->form_fields(),
            function ($o) {
                return $this->user->can_edit_option($this->prow, $o);
            }
        ));

        $this->_echo_edit_messages(true);

        if (!$this->quit) {
            foreach ($this->edit_fields as $o) {
                $ov = $reqov = $this->prow->force_option($o);
                if ($this->useRequest
                    && $this->qreq["has_{$o->formid}"]
                    && ($x = $o->parse_web($this->prow, $this->qreq))) {
                    $reqov = $x;
                }
                $o->echo_web_edit($this, $ov, $reqov);
            }

            // Submit button
            $this->echo_editable_complete();
            $this->echo_actions();
        }

        echo "</div></form>";
        $this->user->set_overrides($overrides);
    }

    function paptabBegin() {
        if ($this->prow->paperId) {
            $this->_papstrip();
        }
        if ($this->npapstrip) {
            Ht::stash_script("hotcrp.prepare_editable_paper()");
            echo '</div></div><nav class="pslcard-nav">';
        } else {
            echo '<article class="pcontainer"><div class="pcard-left pcard-left-nostrip"><nav class="pslcard-nav">';
        }
        $viewable_tags = $this->prow->viewable_tags($this->user);
        echo '<h4 class="pslcard-home">';
        if ($viewable_tags || $this->user->can_view_tags($this->prow)) {
            $color = $this->prow->conf->tags()->color_classes($viewable_tags);
            echo '<span class="pslcard-home-tag has-tag-classes taghh',
                ($color ? " $color" : ""), '">';
            $close = '</span>';
        } else {
            $close = '';
        }
        echo '<a href="#top" class="qq"><span class="header-site-name">',
            htmlspecialchars($this->conf->short_name), '</span> ';
        if ($this->prow->paperId <= 0) {
            echo "new submission";
        } else if ($this->mode !== "re") {
            echo "#{$this->prow->paperId}";
        } else if (!$this->editrrow || !$this->editrrow->reviewOrdinal) {
            echo "#{$this->prow->paperId} review";
        } else {
            echo "#" . unparseReviewOrdinal($this->editrrow);
        }
        echo '</a>', $close, '</h4><ul class="pslcard"></ul></nav></div>';
        echo '<div class="pcard papcard"><div class="',
            ($this->editable ? "pedcard" : "papcard"), '-body">';

        if ($this->editable) {
            $need_clickthrough = !$this->user->can_clickthrough("submit");
            if ($need_clickthrough) {
                echo '<div id="foldpaper js-clickthrough-container">',
                    '<div class="js-clickthrough-terms">',
                    '<h2>Submission terms</h2>',
                    Ht::msg("You must agree to these terms to register a submission.", 2);
                self::_echo_clickthrough("submit");
                echo '</div><div class="need-clickthrough-show hidden">';
            } else {
                echo '<div id="foldpaper">';
            }
            $this->_echo_editable_body();
            echo ($need_clickthrough ? "</div>" : ""), '</div>';
        } else {
            $this->echoDivEnter();
            $this->_echo_normal_body();
            echo '</div>';

            if ($this->mode === "edit") {
                echo '</div></div><div class="pcard notecard"><div class="papcard-body">';
                $this->_echo_edit_messages(false);
                $this->_echo_editable_form();
                $o = $this->conf->option_by_id(PaperOption::CONTACTSID);
                assert($o instanceof Contacts_PaperOption);
                $ov = $reqov = $this->prow->force_option($o);
                if ($this->useRequest
                    && $this->qreq["has_{$o->formid}"]
                    && ($x = $o->parse_web($this->prow, $this->qreq))) {
                    $reqov = $x;
                }
                $o->echo_web_edit($this, $ov, $reqov);
                $this->echo_actions();
                echo "</form>";
            }
        }

        echo '</div></div>';

        if (!$this->editable
            && $this->mode !== "edit"
            && $this->user->act_author_view($this->prow)
            && !$this->user->contactId) {
            echo '<div class="pcard papcard">',
                "To edit this submission, <a href=\"", $this->conf->hoturl("signin"), "\">sign in using your email and password</a>.",
                '</div>';
        }

        Ht::stash_script("hotcrp.shortcut().add()");
    }

    private function _paptabSepContaining($t) {
        if ($t !== "") {
            echo '<div class="pcard notcard"><div class="papcard-body">', $t, '</div></div>';
        }
    }

    /** @param ReviewInfo $rr
     * @return string */
    private function _review_table_actas($rr) {
        if (!$rr->contactId || $rr->contactId === $this->user->contactId) {
            return "";
        } else {
            return ' <a href="' . $this->conf->selfurl($this->qreq, ["actas" => $rr->email]) . '">'
                . Ht::img("viewas.png", "[Act as]", ["title" => "Act as " . Text::nameo($rr, NAME_P)])
                . "</a>";
        }
    }

    /** @param ?ReviewInfo $editrrow
     * @return string */
    function review_table($editrrow) {
        $user = $this->user;
        $prow = $this->prow;
        $conf = $prow->conf;
        $subrev = [];
        $cflttype = $user->view_conflict_type($prow);
        $allow_actas = $user->privChair && $user->allow_administer($prow);
        $admin = $user->can_administer($prow);
        $hideUnviewable = ($cflttype > 0 && !$admin)
            || (!$user->act_pc($prow) && !$conf->setting("extrev_view"));
        $show_ratings = $user->can_view_review_ratings($prow);
        $xsep = ' <span class="barsep">·</span> ';
        $want_scores = !in_array($this->mode, ["assign", "edit", "re"]);
        $want_requested_by = false;
        $score_header = array_map(function ($x) { return ""; },
                                  $conf->review_form()->forder);
        $last_pc_reviewer = -1;

        // actual rows
        foreach ($this->all_rrows as $rr) {
            $want_my_scores = $want_scores;
            if ($user->is_owned_review($rr) && $this->mode === "re") {
                $want_my_scores = true;
            }
            $canView = $user->can_view_review($prow, $rr);

            // skip unsubmitted reviews;
            // assign page lists actionable reviews separately
            if (!$canView && $hideUnviewable) {
                $last_pc_reviewer = -1;
                continue;
            }

            $tclass = $editrrow && $rr->reviewId === $editrrow->reviewId ? "reviewers-highlight" : "";
            $isdelegate = $rr->is_subreview() && $rr->requestedBy === $last_pc_reviewer;
            if ($rr->reviewStatus < ReviewInfo::RS_COMPLETED && $isdelegate) {
                $tclass .= ($tclass ? " " : "") . "rldraft";
            }
            if ($rr->reviewType >= REVIEW_PC) {
                $last_pc_reviewer = +$rr->contactId;
            }

            // review ID
            $id = $rr->subject_to_approval() ? "Subreview" : "Review";
            if ($rr->reviewOrdinal && !$isdelegate) {
                $id .= " #" . $rr->unparse_ordinal();
            }
            if ($rr->reviewStatus < ReviewInfo::RS_ADOPTED) {
                $d = $rr->status_description();
                if ($d === "draft") {
                    $id = "Draft " . $id;
                } else {
                    $id .= " (" . $d . ")";
                }
            }
            $rlink = $rr->unparse_ordinal();

            $t = '<td class="rl nw">';
            if ($editrrow && $editrrow->reviewId === $rr->reviewId) {
                if ($user->contactId == $rr->contactId
                    && $rr->reviewStatus < ReviewInfo::RS_COMPLETED) {
                    $id = "Your $id";
                }
                $t .= '<a href="' . $prow->reviewurl(["r" => $rlink]) . '" class="q"><b>' . $id . '</b></a>';
            } else if (!$canView
                       || ($rr->reviewStatus < ReviewInfo::RS_DRAFTED && !$user->can_review($prow, $rr))) {
                $t .= $id;
            } else {
                if ((!$this->can_view_reviews
                     || $rr->reviewStatus < ReviewInfo::RS_ADOPTED)
                    && $user->can_review($prow, $rr)) {
                    $link = $prow->reviewurl(["r" => $rlink]);
                } else if (Navigation::page() !== "paper") {
                    $link = $prow->hoturl(["anchor" => "r$rlink"]);
                } else {
                    $link = "#r$rlink";
                }
                $t .= '<a href="' . $link . '">' . $id . '</a>';
                if ($show_ratings
                    && $user->can_view_review_ratings($prow, $rr)
                    && ($ratings = $rr->ratings())) {
                    $all = 0;
                    foreach ($ratings as $r) {
                        $all |= $r;
                    }
                    if ($all & 126) {
                        $t .= " &#x2691;";
                    } else if ($all & 1) {
                        $t .= " &#x2690;";
                    }
                }
            }
            $t .= '</td>';

            // primary/secondary glyph
            $rtype = "";
            if (($cflttype <= 0 || $admin) && $rr->reviewType > 0) {
                $rtype = $rr->type_icon();
                if ($rr->reviewRound > 0
                    && $user->can_view_review_round($prow, $rr)) {
                    $rtype .= '&nbsp;<span class="revround" title="Review round">'
                        . htmlspecialchars($conf->round_name($rr->reviewRound))
                        . "</span>";
                }
            }

            // reviewer identity
            $showtoken = $rr->reviewToken && $user->can_review($prow, $rr);
            if (!$user->can_view_review_identity($prow, $rr)) {
                $t .= ($rtype ? "<td class=\"rl\">{$rtype}</td>" : '<td></td>');
            } else {
                if (!$showtoken || !Contact::is_anonymous_email($rr->email)) {
                    $n = $user->reviewer_html_for($rr);
                } else {
                    $n = "[Token " . encode_token((int) $rr->reviewToken) . "]";
                }
                if ($allow_actas) {
                    $n .= $this->_review_table_actas($rr);
                }
                $t .= '<td class="rl"><span class="taghl" title="'
                    . $rr->email . '">' . $n . '</span>'
                    . ($rtype ? " $rtype" : "") . "</td>";
            }

            // requester
            if ($this->mode === "assign") {
                if ($rr->reviewType < REVIEW_SECONDARY
                    && !$showtoken
                    && $rr->requestedBy
                    && $rr->requestedBy !== $rr->contactId
                    && $user->can_view_review_requester($prow, $rr)) {
                    $t .= '<td class="rl small">requested by ';
                    if ($rr->requestedBy === $user->contactId) {
                        $t .= "you";
                    } else {
                        $t .= $user->reviewer_html_for($rr->requestedBy);
                    }
                    $t .= '</td>';
                    $want_requested_by = true;
                } else {
                    $t .= '<td></td>';
                }
            }

            // scores
            $scores = [];
            if ($want_my_scores && $canView) {
                $view_score = $user->view_score_bound($prow, $rr);
                foreach ($conf->review_form()->forder as $fid => $f) {
                    if ($f->has_options && $f->view_score > $view_score
                        && (!$f->round_mask || $f->is_round_visible($rr))
                        && isset($rr->$fid) && $rr->$fid) {
                        if ($score_header[$fid] === "") {
                            $score_header[$fid] = '<th class="rlscore">' . $f->web_abbreviation() . "</th>";
                        }
                        $scores[$fid] = '<td class="rlscore need-tooltip" data-rf="' . $f->uid() . '" data-tooltip-info="rf-score">'
                            . $f->unparse_value($rr->$fid, ReviewField::VALUE_SC)
                            . '</td>';
                    }
                }
            }

            // affix
            $subrev[] = [$tclass, $t, $scores];
        }

        // completion
        if (!empty($subrev)) {
            if ($want_requested_by) {
                array_unshift($score_header, '<th class="rl"></th>');
            }
            $score_header_text = join("", $score_header);
            $t = "<div class=\"reviewersdiv\"><table class=\"reviewers";
            if ($score_header_text) {
                $t .= " has-scores";
            }
            $t .= "\">";
            $nscores = 0;
            if ($score_header_text) {
                foreach ($score_header as $x) {
                    $nscores += $x !== "" ? 1 : 0;
                }
                $t .= '<thead><tr><th colspan="2"></th>';
                if ($this->mode === "assign" && !$want_requested_by) {
                    $t .= '<th></th>';
                }
                $t .= $score_header_text . "</tr></thead>";
            }
            $t .= '<tbody>';
            foreach ($subrev as $r) {
                $t .= '<tr class="rl' . ($r[0] ? " $r[0]" : "") . '">' . $r[1];
                if ($r[2] ?? null) {
                    foreach ($score_header as $fid => $header_needed) {
                        if ($header_needed !== "") {
                            $x = $r[2][$fid] ?? null;
                            $t .= $x ? : "<td class=\"rlscore rs_$fid\"></td>";
                        }
                    }
                } else if ($nscores > 0) {
                    $t .= '<td colspan="' . $nscores . '"></td>';
                }
                $t .= "</tr>";
            }
            return $t . "</tbody></table></div>\n";
        } else {
            return "";
        }
    }

    private function _review_overview_card($rtable, $editrrow, $ifempty, $msgs) {
        $t = "";
        if ($rtable) {
            $t .= $this->review_table($editrrow);
        }
        $t .= $this->_review_links($editrrow);
        if (($empty = ($t === ""))) {
            $t = $ifempty;
        }
        if ($msgs) {
            $t .= join("", $msgs);
        }
        if ($t) {
            echo '<div class="pcard notecard"><div class="papcard-body">',
                $t, '</div></div>';
        }
        return $empty;
    }

    private function _review_links($editrrow) {
        $prow = $this->prow;
        $cflttype = $this->user->view_conflict_type($prow);
        $allow_admin = $this->user->allow_administer($prow);
        $any_comments = false;
        $admin = $this->user->can_administer($prow);
        $xsep = ' <span class="barsep">·</span> ';

        $nvisible = 0;
        $myrr = null;
        foreach ($this->all_rrows as $rr) {
            if ($this->user->can_view_review($prow, $rr)) {
                $nvisible++;
            }
            if ($rr->contactId == $this->user->contactId
                || (!$myrr && $this->user->is_my_review($rr))) {
                $myrr = $rr;
            }
        }

        // comments
        $pret = "";
        if ($this->mycrows
            && !$editrrow
            && $this->mode !== "edit") {
            $tagger = new Tagger($this->user);
            $viewable_crows = [];
            foreach ($this->mycrows as $cr) {
                if ($this->user->can_view_comment($cr->prow, $cr)) {
                    $viewable_crows[] = $cr;
                }
            }
            $cxs = CommentInfo::group_by_identity($viewable_crows, $this->user, true);
            if (!empty($cxs)) {
                $count = array_reduce($cxs, function ($n, $cx) { return $n + $cx[1]; }, 0);
                $cnames = array_map(function ($cx) {
                    $cid = $cx[0]->unparse_html_id();
                    $tclass = "cmtlink";
                    if (($tags = $cx[0]->viewable_tags($this->user))
                        && ($color = $cx[0]->conf->tags()->color_classes($tags))) {
                        $tclass .= " $color taghh";
                    }
                    return "<span class=\"nb\"><a class=\"{$tclass} track\" href=\"#{$cid}\">"
                        . $cx[0]->unparse_commenter_html($this->user)
                        . "</a>"
                        . ($cx[1] > 1 ? " ({$cx[1]})" : "")
                        . $cx[2] . "</span>";
                }, $cxs);
                $first_cid = $cxs[0][0]->unparse_html_id();
                $pret = '<div class="revnotes"><a class="track" href="#' . $first_cid . '"><strong>'
                    . plural($count, "Comment") . '</strong></a>: '
                    . join(" ", $cnames) . '</div>';
                $any_comments = true;
            }
        }

        $t = [];
        $dlimgjs = ["class" => "dlimg", "width" => 24, "height" => 24];

        // see all reviews
        $this->allreviewslink = false;
        if (($nvisible > 1 || ($nvisible > 0 && !$myrr))
            && ($this->mode !== "p" || $editrrow)) {
            $this->allreviewslink = true;
            $t[] = '<a href="' . $prow->hoturl() . '" class="xx revlink">'
                . Ht::img("view48.png", "[All reviews]", $dlimgjs) . "&nbsp;<u>All reviews</u></a>";
        }

        // edit paper
        if ($this->mode !== "edit"
            && $prow->has_author($this->user)
            && !$this->user->can_administer($prow)) {
            $t[] = '<a href="' . $prow->hoturl(["m" => "edit"]) . '" class="xx revlink">'
                . Ht::img("edit48.png", "[Edit]", $dlimgjs) . "&nbsp;<u><strong>Edit submission</strong></u></a>";
        }

        // edit review
        if ($this->mode === "re"
            || ($this->mode === "assign" && !empty($t))
            || !$prow) {
            /* no link */;
        } else if ($myrr && $editrrow !== $myrr) {
            $a = '<a href="' . $prow->reviewurl(["r" => $myrr->unparse_ordinal()]) . '" class="xx revlink">';
            if ($this->user->can_review($prow, $myrr)) {
                $x = $a . Ht::img("review48.png", "[Edit review]", $dlimgjs) . "&nbsp;<u><b>Edit your review</b></u></a>";
            } else {
                $x = $a . Ht::img("review48.png", "[Your review]", $dlimgjs) . "&nbsp;<u><b>Your review</b></u></a>";
            }
            $t[] = $x;
        } else if (!$myrr && !$editrrow && $this->user->can_review($prow, null)) {
            $t[] = '<a href="' . $prow->reviewurl(["m" => "re"]) . '" class="xx revlink">'
                . Ht::img("review48.png", "[Write review]", $dlimgjs) . "&nbsp;<u><b>Write review</b></u></a>";
        }

        // review assignments
        if ($this->mode !== "assign"
            && $this->mode !== "edit"
            && $this->user->can_request_review($prow, null, true)) {
            $t[] = '<a href="' . $this->conf->hoturl("assign", "p=$prow->paperId") . '" class="xx revlink">'
                . Ht::img("assign48.png", "[Assign]", $dlimgjs) . "&nbsp;<u>" . ($admin ? "Assign reviews" : "External reviews") . "</u></a>";
        }

        // new comment
        $nocmt = in_array($this->mode, ["assign", "contact", "edit", "re"]);
        if (!$this->allreviewslink
            && !$nocmt
            && $this->user->can_comment($prow, null)) {
            $t[] = '<a class="uic js-edit-comment xx revlink" href="#cnew">'
                . Ht::img("comment48.png", "[Add comment]", $dlimgjs) . "&nbsp;<u>Add comment</u></a>";
            $any_comments = true;
        }

        // new response
        if (!$nocmt
            && ($prow->has_author($this->user) || $allow_admin)
            && $this->conf->any_response_open) {
            foreach ($this->conf->resp_rounds() as $rrd) {
                $cr = null;
                foreach ($this->mycrows ? : [] as $crow) {
                    if (($crow->commentType & COMMENTTYPE_RESPONSE)
                        && $crow->commentRound == $rrd->number) {
                        $cr = $crow;
                    }
                }
                $cr = $cr ? : CommentInfo::make_response_template($rrd->number, $prow);
                if ($this->user->can_respond($prow, $cr)) {
                    $cid = $this->conf->resp_round_text($rrd->number) . "response";
                    $what = "Add";
                    if ($cr->commentId) {
                        $what = $cr->commentType & COMMENTTYPE_DRAFT ? "Edit draft" : "Edit";
                    }
                    $t[] = '<a class="uic js-edit-comment xx revlink" href="#' . $cid . '">'
                        . Ht::img("comment48.png", "[$what response]", $dlimgjs) . "&nbsp;"
                        . ($cflttype >= CONFLICT_AUTHOR ? '<u class="font-weight-bold">' : '<u>')
                        . $what . ($rrd->name == "1" ? "" : " $rrd->name") . ' response</u></a>';
                    $any_comments = true;
                }
            }
        }

        // override conflict
        if ($allow_admin && !$admin) {
            $t[] = '<span class="revlink"><a href="' . $prow->conf->selfurl($this->qreq, ["forceShow" => 1]) . '" class="xx">'
                . Ht::img("override24.png", "[Override]", "dlimg") . "&nbsp;<u>Override conflict</u></a> to show reviewers and allow editing</span>";
        } else if ($this->user->privChair && !$allow_admin) {
            $x = '<span class="revlink">You can’t override your conflict because this submission has an administrator.</span>';
        }

        if ($any_comments) {
            CommentInfo::echo_script($prow);
        }

        $t = empty($t) ? "" : '<p class="sd">' . join("", $t) . '</p>';
        if ($prow->has_author($this->user)) {
            $t = '<p class="sd">' . $this->conf->_('You are an <span class="author">author</span> of this submission.') . '</p>' . $t;
        } else if ($prow->has_conflict($this->user)) {
            $t = '<p class="sd">' . $this->conf->_('You have a <span class="conflict">conflict</span> with this submission.') . '</p>' . $t;
        }
        return $pret . $t;
    }

    function _privilegeMessage() {
        $a = "<a href=\"" . $this->conf->selfurl($this->qreq, ["forceShow" => 0]) . "\">";
        return $a . Ht::img("override24.png", "[Override]", "dlimg")
            . "</a>&nbsp;You have used administrator privileges to view and edit reviews for this submission. (" . $a . "Unprivileged view</a>)";
    }

    private function include_comments() {
        return !$this->allreviewslink
            && (!empty($this->mycrows)
                || $this->user->can_comment($this->prow, null)
                || $this->conf->any_response_open);
    }

    function paptabEndWithReviewsAndComments() {
        if ($this->user->is_admin_force()
            && !$this->user->call_with_overrides(0, "can_view_review", $this->prow, null)) {
            $this->_paptabSepContaining($this->_privilegeMessage());
        } else if ($this->prow->managerContactId === $this->user->contactXid
                   && !$this->user->privChair) {
            $this->_paptabSepContaining("You are this submission’s administrator.");
        }

        // text format link
        $m = $viewable = [];
        foreach ($this->viewable_rrows as $rr) {
            if ($rr->reviewStatus >= ReviewInfo::RS_DRAFTED) {
                $viewable[] = "reviews";
                break;
            }
        }
        foreach ($this->crows as $cr) {
            if ($this->user->can_view_comment($this->prow, $cr)) {
                $viewable[] = "comments";
                break;
            }
        }
        if (!empty($viewable)) {
            $m[] = '<p class="sd mt-5"><a href="' . $this->prow->reviewurl(["m" => "r", "text" => 1]) . '" class="xx">'
                . Ht::img("txt24.png", "[Text]", "dlimg")
                . "&nbsp;<u>" . ucfirst(join(" and ", $viewable))
                . " in plain text</u></a></p>";
        }

        if (!$this->_review_overview_card(true, null, '<p>There are no reviews or comments for you to view.</p>', $m)) {
            $this->render_rc(true, $this->include_comments());
        }
    }

    private function has_response($respround) {
        foreach ($this->mycrows as $cr) {
            if (($cr->commentType & COMMENTTYPE_RESPONSE)
                && $cr->commentRound == $respround)
                return true;
        }
        return false;
    }

    private function render_rc($reviews, $comments) {
        $rcs = [];
        $any_submitted = false;
        if ($reviews) {
            foreach ($this->viewable_rrows as $rrow) {
                if ($rrow->reviewStatus >= ReviewInfo::RS_DRAFTED) {
                    $rcs[] = $rrow;
                }
                if ($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
                    $any_submitted = true;
                }
            }
        }
        if ($comments && $this->mycrows) {
            $rcs = $this->prow->merge_reviews_and_comments($rcs, $this->mycrows);
        }

        $s = "";
        $ncmt = 0;
        $rf = $this->conf->review_form();
        foreach ($rcs as $rc) {
            if (isset($rc->reviewId)) {
                $rcj = $rf->unparse_review_json($this->user, $this->prow, $rc);
                if (($any_submitted || $rc->reviewStatus === ReviewInfo::RS_ADOPTED)
                    && $rc->reviewStatus < ReviewInfo::RS_COMPLETED
                    && !$this->user->is_my_review($rc)) {
                    $rcj->folded = true;
                }
                $s .= "hotcrp.add_review(" . json_encode_browser($rcj) . ");\n";
            } else {
                ++$ncmt;
                $rcj = $rc->unparse_json($this->user);
                $s .= "hotcrp.add_comment(" . json_encode_browser($rcj) . ");\n";
            }
        }

        if ($comments) {
            $cs = [];
            if ($this->user->can_comment($this->prow, null)) {
                $commentType = $this->prow->has_author($this->user) ? COMMENTTYPE_BYAUTHOR : 0;
                $cs[] = new CommentInfo(["commentType" => $commentType], $this->prow);
            }
            if ($this->admin || $this->prow->has_author($this->user)) {
                foreach ($this->conf->resp_rounds() as $rrd) {
                    if (!$this->has_response($rrd->number)
                        && $rrd->relevant($this->user, $this->prow)) {
                        $crow = CommentInfo::make_response_template($rrd->number, $this->prow);
                        if ($this->user->can_respond($this->prow, $crow))
                            $cs[] = $crow;
                    }
                }
            }
            foreach ($cs as $c) {
                ++$ncmt;
                $s .= "hotcrp.add_comment(" . json_encode_browser($c->unparse_json($this->user)) . ");\n";
            }
        }

        if ($ncmt) {
            CommentInfo::echo_script($this->prow);
        }
        if ($s !== "") {
            echo Ht::unstash_script($s);
        }
    }

    function paptabComments() {
        $this->render_rc(false, $this->include_comments());
    }

    function paptabEndWithoutReviews() {
        echo "</div></div>\n";
    }

    function paptabEndWithReviewMessage() {
        assert(!$this->editable);

        $m = [];
        if ($this->all_rrows
            && ($whyNot = $this->user->perm_view_review($this->prow, null))) {
            $m[] = "<p class=\"sd\">You can’t see the reviews for this submission. " . whyNotText($whyNot) . "</p>";
        }
        if (!$this->conf->time_review_open()
            && $this->prow->review_type($this->user)) {
            if ($this->rrow) {
                $m[] = "<p class=\"sd\">You can’t edit your review because the site is not open for reviewing.</p>";
            } else {
                $m[] = "<p class=\"sd\">You can’t begin your assigned review because the site is not open for reviewing.</p>";
            }
        }

        $this->_review_overview_card($this->user->can_view_review_assignment($this->prow, null), null, "", $m);
    }

    function paptabEndWithEditableReview() {
        $act_pc = $this->user->act_pc($this->prow);

        // review messages
        $msgs = [];
        if ($this->editrrow && !$this->user->is_signed_in()) {
            $msgs[] = $this->conf->_("You followed a review link to edit this review. You can also <a href=\"%s\">sign in to the site</a>.", $this->conf->hoturl("signin", ["email" => $this->editrrow->email, "cap" => null]));
        }
        if (!$this->rrow && !$this->prow->review_type($this->user)) {
            $msgs[] = "You haven’t been assigned to review this submission, but you can review it anyway.";
        }
        if ($this->user->is_admin_force()) {
            if (!$this->user->call_with_overrides(0, "can_view_review", $this->prow, null)) {
                $msgs[] = $this->_privilegeMessage();
            }
        } else if (($whyNot = $this->user->perm_view_review($this->prow, null))
                   && isset($whyNot["reviewNotComplete"])
                   && ($this->user->isPC || $this->conf->setting("extrev_view"))) {
            $nother = 0;
            $myrrow = null;
            foreach ($this->all_rrows as $rrow) {
                if ($this->user->is_my_review($rrow)) {
                    $myrrow = $rrow;
                } else if ($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
                    ++$nother;
                }
            }
            if ($nother > 0) {
                if ($myrrow && $myrrow->reviewStatus === ReviewInfo::RS_DELIVERED) {
                    $msgs[] = $this->conf->_("You’ll be able to see %d other reviews once yours is approved.", $nother);
                } else {
                    $msgs[] = $this->conf->_("You’ll be able to see %d other reviews once you complete your own.", $nother);
                }
            }
        }
        $msgs = array_map(function ($t) { return "<p class=\"sd\">{$t}</p>"; }, $msgs);

        // links
        //$this->_review_overview_card(true, $this->editrrow, "", $msgs);

        // review form, possibly with deadline warning
        $opt = array("edit" => $this->mode === "re");

        if ($this->editrrow
            && ($this->user->is_owned_review($this->editrrow) || $this->admin)
            && !$this->conf->time_review($this->editrrow, $act_pc, true)) {
            if ($this->admin) {
                $override = " As an administrator, you can override this deadline.";
            } else {
                $override = "";
                if ($this->editrrow->reviewStatus >= ReviewInfo::RS_COMPLETED) {
                    $opt["edit"] = false;
                }
            }
            if ($this->conf->time_review_open()) {
                $opt["editmessage"] = 'The <a href="' . $this->conf->hoturl("deadlines") . '">review deadline</a> has passed, so the review can no longer be changed.' . $override;
            } else {
                $opt["editmessage"] = "The site is not open for reviewing, so the review cannot be changed." . $override;
            }
        } else if (!$this->user->can_review($this->prow, $this->editrrow)) {
            $opt["edit"] = false;
        }

        // maybe clickthrough
        if ($opt["edit"] && !$this->user->can_clickthrough("review", $this->prow)) {
            self::echo_review_clickthrough();
        }
        $rf = $this->conf->review_form();
        $rf->show($this->prow, $this->editrrow, $this->user, $opt, $this->review_values);
    }


    // Functions for loading papers

    static function clean_request(Qrequest $qreq) {
        if (!isset($qreq->paperId) && isset($qreq->p)) {
            $qreq->paperId = $qreq->p;
        }
        if (!isset($qreq->reviewId) && isset($qreq->r)) {
            $qreq->reviewId = $qreq->r;
        }
        if (!isset($qreq->commentId) && isset($qreq->c)) {
            $qreq->commentId = $qreq->c;
        }
        if (($pc = $qreq->path_component(0))) {
            if (!isset($qreq->reviewId) && preg_match('/\A\d+[A-Z]+\z/i', $pc)) {
                $qreq->reviewId = $pc;
            } else if (!isset($qreq->paperId)) {
                $qreq->paperId = $pc;
            }
        }
        if (!isset($qreq->paperId)
            && isset($qreq->reviewId)
            && preg_match('/\A(\d+)[A-Z]+\z/i', $qreq->reviewId, $m)) {
            $qreq->paperId = $m[1];
        }
        if (isset($qreq->paperId) || isset($qreq->reviewId)) {
            unset($qreq->q);
        }
    }

    static private function simple_qreq($qreq) {
        return $qreq->method() === "GET"
            && !array_diff($qreq->keys(), ["p", "paperId", "m", "mode", "forceShow", "go", "actas", "t", "q", "r", "reviewId"]);
    }

    /** @param Qrequest $qreq
     * @param Contact $user
     * @return ?int */
    static private function lookup_pid($qreq, $user) {
        // if a number, don't search
        $pid = isset($qreq->paperId) ? $qreq->paperId : $qreq->q;
        if (preg_match('/\A\s*#?(\d+)\s*\z/', $pid, $m)) {
            return intval($m[1]);
        }

        // look up a review ID
        if (!isset($pid) && isset($qreq->reviewId)) {
            return $user->conf->fetch_ivalue("select paperId from PaperReview where reviewId=?", $qreq->reviewId);
        }

        // if a complex request, or a form upload, or empty user, don't search
        if (!self::simple_qreq($qreq) || $user->is_empty()) {
            return null;
        }

        // if no paper ID set, find one
        if (!isset($pid)) {
            $q = "select min(Paper.paperId) from Paper ";
            if ($user->isPC) {
                $q .= "where timeSubmitted>0";
            } else if ($user->has_review()) {
                $q .= "join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=$user->contactId)";
            } else {
                $q .= "join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$user->contactId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")";
            }
            return $user->conf->fetch_ivalue($q);
        }

        // actually try to search
        if ($pid === "" || $pid === "(All)") {
            return null;
        }

        $search = new PaperSearch($user, ["q" => $pid, "t" => $qreq->get("t")]);
        $ps = $search->paper_ids();
        if (count($ps) == 1) {
            $list = $search->session_list_object();
            // DISABLED: check if the paper is in the current list
            unset($qreq->ls);
            $list->set_cookie($user);
            return $ps[0];
        } else {
            return null;
        }
    }

    /** @param ?int $pid */
    static function redirect_request($pid, Qrequest $qreq, Contact $user) {
        if ($pid !== null) {
            $qreq->paperId = $pid;
            unset($qreq->q, $qreq->p);
            $user->conf->redirect_self($qreq);
        } else if ((isset($qreq->paperId) || isset($qreq->q))
                   && !$user->is_empty()) {
            $q = "q=" . urlencode(isset($qreq->paperId) ? $qreq->paperId : $qreq->q);
            if ($qreq->t) {
                $q .= "&t=" . urlencode($qreq->t);
            }
            if ($qreq->page() === "assign") {
                $q .= "&linkto=" . $qreq->page();
            }
            $user->conf->redirect_hoturl("search", $q);
        }
    }

    /** @return ?PaperInfo */
    static function fetch_paper_request(Qrequest $qreq, Contact $user) {
        self::clean_request($qreq);
        $pid = self::lookup_pid($qreq, $user);
        if (self::simple_qreq($qreq)
            && ($pid === null || (string) $pid !== $qreq->paperId)) {
            self::redirect_request($pid, $qreq, $user);
        }
        if ($pid !== null) {
            $options = ["topics" => true, "options" => true];
            if ($user->privChair
                || ($user->isPC && $user->conf->timePCReviewPreferences())) {
                $options["reviewerPreference"] = true;
            }
            $prow = $user->paper_by_id($pid, $options);
        } else {
            $prow = null;
        }
        $whynot = $user->perm_view_paper($prow, false, $pid);
        if (!$whynot
            && !isset($qreq->paperId)
            && isset($qreq->reviewId)
            && !$user->privChair
            && (!($rrow = $prow->review_of_id($qreq->reviewId))
                || !$user->can_view_review($prow, $rrow))) {
            $whynot = new PermissionProblem($user->conf, ["invalidId" => "paper"]);
        }
        if ($whynot) {
            $qreq->set_annex("paper_whynot", $whynot);
        }
        $user->conf->paper = $whynot ? null : $prow;
        return $user->conf->paper;
    }

    function resolveReview($want_review) {
        $this->prow->ensure_full_reviews();
        $this->all_rrows = $this->prow->reviews_by_display();

        $this->viewable_rrows = array();
        $round_mask = 0;
        $min_view_score = VIEWSCORE_EMPTYBOUND;
        foreach ($this->all_rrows as $rrow) {
            if ($this->user->can_view_review($this->prow, $rrow)) {
                $this->viewable_rrows[] = $rrow;
                if ($rrow->reviewRound !== null) {
                    $round_mask |= 1 << (int) $rrow->reviewRound;
                }
                $min_view_score = min($min_view_score, $this->user->view_score_bound($this->prow, $rrow));
            }
        }
        $rf = $this->conf->review_form();
        Ht::stash_script("hotcrp.set_review_form(" . json_encode_browser($rf->unparse_json($round_mask, $min_view_score)) . ")");

        $want_rid = $want_rordinal = -1;
        $rtext = (string) $this->qreq->reviewId;
        if ($rtext !== "" && $rtext !== "new") {
            if (ctype_digit($rtext)) {
                $want_rid = intval($rtext);
            } else if (str_starts_with($rtext, (string) $this->prow->paperId)
                       && ($x = substr($rtext, strlen((string) $this->prow->paperId))) !== ""
                       && ctype_alpha($x)) {
                $want_rordinal = parseReviewOrdinal(strtoupper($x));
            }
        }

        $this->rrow = $myrrow = $approvable_rrow = null;
        foreach ($this->viewable_rrows as $rrow) {
            if (($want_rid > 0 && $rrow->reviewId == $want_rid)
                || ($want_rordinal > 0 && $rrow->reviewOrdinal == $want_rordinal)) {
                $this->rrow = $rrow;
            }
            if ($rrow->contactId === $this->user->contactId
                || (!$myrrow && $this->user->is_my_review($rrow))) {
                $myrrow = $rrow;
            }
            if (($rrow->requestedBy === $this->user->contactId || $this->admin)
                && $rrow->reviewStatus === ReviewInfo::RS_DELIVERED
                && !$approvable_rrow) {
                $approvable_rrow = $rrow;
            }
        }

        if ($this->rrow) {
            $this->editrrow = $this->rrow;
        } else if (!$approvable_rrow
                   || ($myrrow
                       && $myrrow->reviewStatus !== 0
                       && !$this->prefer_approvable)) {
            $this->editrrow = $myrrow;
        } else {
            $this->editrrow = $approvable_rrow;
        }

        if ($want_review
            && $this->user->can_review($this->prow, $this->editrrow, false)) {
            $this->mode = "re";
        }
    }

    function resolveComments() {
        $this->crows = $this->prow->all_comments();
        $this->mycrows = $this->prow->viewable_comments($this->user, true);
    }

    /** @return list<ReviewInfo> */
    function all_reviews() {
        return $this->all_rrows;
    }

    function fixReviewMode() {
        if ($this->mode === "re"
            && $this->rrow
            && !$this->user->can_review($this->prow, $this->rrow, false)
            && ($this->rrow->contactId != $this->user->contactId
                || $this->rrow->reviewStatus >= ReviewInfo::RS_COMPLETED)) {
            $this->mode = "p";
        }
        if ($this->mode === "p"
            && $this->rrow
            && !$this->user->can_view_review($this->prow, $this->rrow)) {
            $this->rrow = $this->editrrow = null;
        }
        if ($this->mode === "p"
            && $this->prow->paperId
            && empty($this->viewable_rrows)
            && empty($this->mycrows)
            && $this->prow->has_author($this->user)
            && !$this->allow_admin
            && ($this->conf->timeFinalizePaper($this->prow) || $this->prow->timeSubmitted <= 0)) {
            $this->mode = "edit";
        }
    }
}

class PaperTableFieldRender {
    /** @var PaperOption */
    public $option;
    /** @var int */
    public $view_state;
    public $title;
    public $value;
    /** @var ?bool */
    public $value_long;

    /** @param PaperOption $option */
    function __construct($option, $view_state, FieldRender $fr) {
        $this->option = $option;
        $this->view_state = $view_state;
        $this->title = $fr->title;
        $this->value = $fr->value;
        $this->value_long = $fr->value_long;
    }
}
