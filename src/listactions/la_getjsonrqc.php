<?php
// listactions/la_getjsonrqc.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class GetJsonRQC_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_manager();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $results = ["hotcrp_version" => HOTCRP_VERSION];
        if (($git_data = Conf::git_status())) {
            $results["hotcrp_commit"] = $git_data[0];
        }
        $rf = $user->conf->review_form();
        $results["reviewform"] = $rf->unparse_form_json($rf->bound_viewable_fields(VIEWSCORE_REVIEWERONLY));
        $pj = [];
        $ps = new PaperStatus($user->conf, $user, ["hide_docids" => true]);
        foreach ($ssel->paper_set($user, ["topics" => true, "options" => true]) as $prow) {
            if ($user->allow_administer($prow)) {
                $pj[] = $j = $ps->paper_json($prow);
                $prow->ensure_full_reviews();
                foreach ($prow->viewable_reviews_by_display($user) as $rrow) {
                    if ($rrow->reviewSubmitted) {
                        $j->reviews[] = $rf->unparse_review_json($user, $prow, $rrow, ReviewForm::RJ_NO_EDITABLE | ReviewForm::RJ_UNPARSE_RATINGS | ReviewForm::RJ_ALL_RATINGS | ReviewForm::RJ_NO_REVIEWERONLY);
                    }
                }
            } else {
                $pj[] = (object) ["pid" => $prow->paperId, "error" => "You don’t have permission to administer this paper."];
            }
        }
        $user->set_overrides($old_overrides);
        $results["papers"] = $pj;
        header("Content-Type: application/json; charset=utf-8");
        header("Content-Disposition: attachment; filename=" . mime_quote_string($user->conf->download_prefix . "rqc.json"));
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit;
    }
}
